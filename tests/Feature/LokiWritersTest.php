<?php

declare(strict_types=1);

use Dotswan\EagleEye\Data\Query\Cursor;
use Dotswan\EagleEye\Data\Query\QueryResult;
use Dotswan\EagleEye\Data\ActivityEntry;
use Dotswan\EagleEye\Drivers\DriverManager;
use Dotswan\EagleEye\Drivers\Loki\LokiMisconfigurationException;
use Illuminate\Support\Facades\Artisan;
use Dotswan\EagleEye\Data\Query\ActivityQuery;
use Dotswan\EagleEye\Drivers\Loki\LokiDriver;
use Dotswan\EagleEye\Drivers\Loki\BoundedResponse;
use Dotswan\EagleEye\Drivers\Loki\LokiLineFormatter;
use Dotswan\EagleEye\Drivers\Loki\LokiQueryTranslator;
use Dotswan\EagleEye\Drivers\Loki\LokiWriteException;
use Dotswan\EagleEye\Drivers\Loki\Reader\LokiClient;
use Dotswan\EagleEye\Drivers\Loki\Writers\PushWriter;
use Dotswan\EagleEye\Drivers\Loki\Writers\SpoolWriter;
use Dotswan\EagleEye\Drivers\Loki\Writers\StdoutWriter;
use Dotswan\EagleEye\EagleEyeServiceProvider;
use Dotswan\EagleEye\Enums\SortOrder;
use Dotswan\EagleEye\Testing\DriverContractTests;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response as HttpResponse;
use GuzzleHttp\Psr7\Response as Psr7Response;
use GuzzleHttp\Handler\StreamHandler;
use GuzzleHttp\Handler\CurlHandler;

/** @return array{0: resource, 1: string, 2: string} */
function startStreamingLokiFixture(?string $routerSource = null): array
{
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);

    if (false === $socket) {
        throw new RuntimeException($errorMessage ?? 'Unable to open fixture socket.', $errorCode ?? 0);
    }

    $address = (string) stream_socket_get_name($socket, false);
    fclose($socket);
    $directory = sys_get_temp_dir().'/eagle-eye-http-'.bin2hex(random_bytes(6));
    mkdir($directory, 0o700, true);
    $router = $directory.'/router.php';
    file_put_contents($router, $routerSource ?? <<<'PHP'
<?php
header('Content-Type: application/json');
header('Connection: close');
echo '{"status":"success","data":{"result":[';
for ($chunk = 0; $chunk < 64; $chunk++) {
    echo str_repeat('x', 65536);
    flush();
}
echo ']}}';
PHP);
    $process = proc_open([PHP_BINARY, '-S', $address, $router], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);

    if (! is_resource($process)) {
        throw new RuntimeException('Unable to start the streaming Loki fixture.');
    }

    for ($attempt = 0; $attempt < 100; $attempt++) {
        $probe = @fsockopen($address);

        if (is_resource($probe)) {
            fclose($probe);

            return [$process, 'http://'.$address, $directory];
        }

        usleep(10_000);
    }

    proc_terminate($process);
    throw new RuntimeException('Streaming Loki fixture did not become ready.');
}

/** @return array{0: resource, 1: string, 2: string} */
function startRawLokiFixture(string $mode): array
{
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);

    if (false === $socket) {
        throw new RuntimeException($errorMessage ?? 'Unable to open fixture socket.', $errorCode ?? 0);
    }

    $address = (string) stream_socket_get_name($socket, false);
    fclose($socket);
    $directory = sys_get_temp_dir().'/eagle-eye-raw-http-'.bin2hex(random_bytes(6));
    mkdir($directory, 0o700, true);
    $server = $directory.'/server.php';
    file_put_contents($server, <<<'PHP'
<?php
$server = stream_socket_server('tcp://'.$argv[1]);
while (is_resource($connection = stream_socket_accept($server, 10))) {
    stream_set_timeout($connection, 1);
    $requestLine = fgets($connection);
    if (!is_string($requestLine) || '' === trim($requestLine)) {
        fclose($connection);
        continue;
    }
    while (is_string($line = fgets($connection)) && "\r\n" !== $line && "\n" !== $line) {
    }
    fwrite($connection, "HTTP/1.1 200 OK\r\n");
    fflush($connection);
    if ('slow_headers' === $argv[2]) {
        for ($header = 0; $header < 10; $header++) {
            fwrite($connection, "X-Slow-{$header}: yes\r\n");
            fflush($connection);
            usleep(300000);
        }
    } elseif ('stalled_headers' === $argv[2]) {
        fwrite($connection, "X-Stalled: yes\r\n");
        fflush($connection);
        sleep(5);
    }
    $body = '{"status":"success","data":{"result":[]}}';
    fwrite($connection, 'Content-Length: '.strlen($body)."\r\nConnection: close\r\n\r\n");
    fflush($connection);
    if ('stalled_body' === $argv[2]) {
        fwrite($connection, substr($body, 0, 1));
        fflush($connection);
        sleep(5);
    } else {
        fwrite($connection, $body);
    }
    fclose($connection);
    break;
}

fclose($server);
PHP);
    $process = proc_open([PHP_BINARY, $server, $address, $mode], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);

    if (! is_resource($process)) {
        throw new RuntimeException('Unable to start raw HTTP fixture.');
    }

    for ($attempt = 0; $attempt < 100; $attempt++) {
        $probe = @fsockopen($address);

        if (is_resource($probe)) {
            fclose($probe);

            return [$process, 'http://'.$address, $directory];
        }

        usleep(10_000);
    }

    proc_terminate($process);
    proc_close($process);
    unlink($server);
    rmdir($directory);
    throw new RuntimeException('Raw HTTP fixture did not become ready.');
}

/** @param list<array{0: string, 1: string}> $values */
function fakeCollectorLokiRange(array $values): void
{
    Http::fake(static function (Request $request) use ($values) {
        if (! str_contains($request->url(), '/loki/api/v1/query_range')) {
            throw new LogicException('Expected a Loki range query.');
        }

        $parameters = $request->data();
        $start = (string) ($parameters['start'] ?? '0');
        $end = (string) ($parameters['end'] ?? PHP_INT_MAX);
        $direction = (string) ($parameters['direction'] ?? 'backward');
        $limit = (int) ($parameters['limit'] ?? 100);
        $query = (string) ($parameters['query'] ?? '');
        preg_match_all('/01J\d{23}/', $query, $matches);
        $excludedIds = array_unique($matches[0]);

        $page = array_values(array_filter($values, static function (array $value) use ($start, $end, $excludedIds): bool {
            if (strcmp($value[0], $start) < 0 || strcmp($value[0], $end) >= 0) {
                return false;
            }

            $decoded = json_decode($value[1], true);
            $id = is_array($decoded) ? ($decoded['id'] ?? null) : null;

            return ! is_string($id) || ! in_array($id, $excludedIds, true);
        }));
        usort($page, static fn (array $left, array $right): int => 'forward' === $direction
            ? strcmp($left[0], $right[0])
            : strcmp($right[0], $left[0]));

        return Http::response([
            'status' => 'success',
            'data'   => [
                'resultType' => 'streams',
                'result'     => [['stream' => ['namespace' => 'my-app'], 'values' => array_slice($page, 0, $limit)]],
            ],
        ]);
    });
}

/** @param list<array{0: string, 1: string}> $values */
function fakeCollectorLokiDriver(array $values): LokiDriver
{
    fakeCollectorLokiRange($values);

    return new LokiDriver(
        new PushWriter('http://loki.test'),
        new LokiClient('http://loki.test'),
        new LokiLineFormatter(['app' => 'my-app']),
        new LokiQueryTranslator(selector: '{namespace="my-app"}'),
        ['app' => 'my-app'],
    );
}

it('retries with backoff and recovers through the spool', function (): void {
    $spoolPath = tempnam(sys_get_temp_dir(), 'loki-spool-');

    if (false === $spoolPath) {
        throw new RuntimeException('Unable to create Loki spool fixture.');
    }

    Http::fakeSequence()
        ->pushStatus(429)
        ->pushStatus(429)
        ->pushStatus(204)
        ->pushStatus(503)
        ->pushStatus(503)
        ->pushStatus(503)
        ->pushStatus(503)
        ->pushStatus(204);

    $formatter = new LokiLineFormatter(['app' => 'my-app']);
    $streams = $formatter->streams([DriverContractTests::entry(1), DriverContractTests::entry(2)]);

    (new PushWriter('http://loki.test', backoffMs: [1, 1, 1]))->write($streams);
    Http::assertSentCount(3);
    expect(is_file($spoolPath) ? filesize($spoolPath) : null)->toBeIn([0, null]);

    (new PushWriter('http://loki.test', backoffMs: [1, 1, 1], spool: new SpoolWriter($spoolPath)))->write($streams);

    $rows = file($spoolPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $rows = false === $rows ? [] : $rows;
    expect($rows)->toHaveCount(2);

    $first = json_decode((string) $rows[0], true);
    expect($first['stream']['app'] ?? null)->toBe('my-app')
        ->and($first['values'][0][2]['id'] ?? null)->toBe(DriverContractTests::entry(1)->id);

    config()->set('eagle-eye.drivers.loki.write.spool', $spoolPath);
    config()->set('eagle-eye.drivers.loki.write.endpoint', 'http://loki.test');
    expect(Artisan::call('activity-log:loki-ship'))->toBe(0);

    expect(is_file($spoolPath))->toBeFalse();
});

it('throws when push fails and no spool is configured', function (): void {
    Http::fakeSequence()
        ->pushStatus(500)
        ->pushStatus(500)
        ->pushStatus(500);

    $pusher = new PushWriter('http://loki.test', backoffMs: [1]);
    $formatter = new LokiLineFormatter(['app' => 'my-app']);

    $pusher->write($formatter->streams([DriverContractTests::entry(1)]));
})->throws(LokiWriteException::class, 'no spool');

it('sends tenant header and basic auth when configured', function (): void {
    Http::fakeSequence()->pushStatus(204);

    $pusher = new PushWriter(
        'http://loki.test',
        tenant: 'acme',
        auth: ['type' => 'basic', 'username' => 'u', 'password' => 'p'],
        backoffMs: [1],
    );
    $formatter = new LokiLineFormatter(['app' => 'my-app']);

    $pusher->write($formatter->streams([DriverContractTests::entry(1)]));

    Http::assertSent(
        static fn ($request): bool => 'acme' === $request->header('X-Scope-OrgID')[0]
            && str_starts_with((string) ($request->header('Authorization')[0] ?? ''), 'Basic '),
    );
});

it('fails explicitly when a full equal-timestamp Loki page cannot advance', function (): void {
    $values = [];

    for ($sequence = 1; $sequence <= 502; $sequence++) {
        $row = DriverContractTests::entry($sequence)->toStorage();
        $row['logged_at'] = '2026-09-08T00:00:00.000000+00:00';
        $row['created_at'] = $row['logged_at'];
        $values[] = ['1788796800000000000', json_encode($row, JSON_THROW_ON_ERROR)];
    }

    Http::fake(static fn () => Http::response(['status' => 'success', 'data' => ['resultType' => 'streams', 'result' => [
        ['stream' => ['app' => 'my-app'], 'values' => $values],
    ]]]));

    $driver = new LokiDriver(
        new PushWriter('http://loki.test'),
        new LokiClient('http://loki.test', timeout: 60),
        new LokiLineFormatter(['app' => 'my-app']),
        new LokiQueryTranslator(),
        ['app' => 'my-app'],
    );

    expect(fn (): QueryResult => $driver->search((new ActivityQuery())->limit(500)))
        ->toThrow(RuntimeException::class, 'results are incomplete');
});

it('paginates collector results by Loki ingestion time despite body timestamp skew', function (SortOrder $order): void {
    $values = [];

    foreach ([5, 1, 4, 2, 3] as $offset => $sequence) {
        $storage = DriverContractTests::entry($sequence)->toStorage();
        $storage['logged_at'] = sprintf('2026-09-08T00:00:0%d.000000Z', 6 - $offset);
        $storage['created_at'] = $storage['logged_at'];
        $values[] = [
            (string) (1_788_796_800_000_000_000 + ($offset * 1_000_000_000)),
            json_encode([LokiLineFormatter::STDOUT_MARKER => true] + $storage, JSON_THROW_ON_ERROR),
        ];
    }

    $driver = fakeCollectorLokiDriver($values);
    $cursor = null;
    $ids = [];

    do {
        $query = (new ActivityQuery())
            ->between(new DateTimeImmutable('2026-09-07T00:00:00Z'), new DateTimeImmutable('2026-09-09T00:00:00Z'))
            ->orderByTime($order)
            ->limit(2)
            ->cursor($cursor);
        $page = $driver->search($query);
        array_push($ids, ...array_map(static fn (ActivityEntry $entry): string => $entry->id, $page->entries));
        $cursor = $page->nextCursor;
    } while ($cursor instanceof Cursor);

    $expected = array_map(DriverContractTests::id(...), [5, 1, 4, 2, 3]);

    expect($ids)->toBe(SortOrder::Ascending === $order ? $expected : array_reverse($expected))
        ->and(array_unique($ids))->toHaveCount(5);
})->with([
    'ascending'  => [SortOrder::Ascending],
    'descending' => [SortOrder::Descending],
]);

it('advances past a corrupt full collector page at raw nanosecond precision', function (SortOrder $order): void {
    $base = 1_788_796_800_000_000_000;
    $corruptOffset = SortOrder::Ascending === $order ? 100 : 900;
    $validOffset = SortOrder::Ascending === $order ? 101 : 899;
    $storage = DriverContractTests::entry(6301)->toStorage();
    $storage['logged_at'] = '2026-09-08T00:00:00.000000Z';
    $storage['created_at'] = $storage['logged_at'];
    $values = [
        [(string) ($base + $corruptOffset), '{corrupt-1'],
        [(string) ($base + $corruptOffset), '{corrupt-2'],
        [(string) ($base + $corruptOffset), '{corrupt-3'],
        [
            (string) ($base + $validOffset),
            json_encode([LokiLineFormatter::STDOUT_MARKER => true] + $storage, JSON_THROW_ON_ERROR),
        ],
    ];

    $result = fakeCollectorLokiDriver($values)->search(
        (new ActivityQuery())
            ->between(new DateTimeImmutable('2026-09-07T00:00:00Z'), new DateTimeImmutable('2026-09-09T00:00:00Z'))
            ->orderByTime($order)
            ->limit(2),
    );

    expect(array_map(static fn (ActivityEntry $entry): string => $entry->id, $result->entries))
        ->toBe([DriverContractTests::id(6301)]);
})->with([
    'ascending'  => [SortOrder::Ascending],
    'descending' => [SortOrder::Descending],
]);

it('maps collector aggregate fields and rejects stream-only groups', function (): void {
    Http::fake(static function (Request $request) {
        $query = (string) ($request->data()['query'] ?? '');
        $metric = str_contains($query, 'ee_actor_type')
            ? ['ee_actor_type' => 'user', 'ee_actor_id' => '42']
            : ['ee_channel' => 'model'];

        return Http::response(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => [
            ['metric' => $metric, 'value' => ['1', '3']],
        ]]]);
    });
    $driver = new LokiDriver(
        new PushWriter('http://loki.test'),
        new LokiClient('http://loki.test'),
        new LokiLineFormatter(['app' => 'my-app']),
        new LokiQueryTranslator(selector: '{namespace="my-app"}'),
        ['app' => 'my-app'],
    );

    expect($driver->aggregate(new ActivityQuery(), 'channel'))->toBe(['model' => 3])
        ->and($driver->aggregate(new ActivityQuery(), 'actor'))->toBe(['["user","42"]' => 3])
        ->and(fn (): array => $driver->aggregate(new ActivityQuery(), 'app'))
        ->toThrow(InvalidArgumentException::class, 'not supported');
    Http::assertSentCount(2);
});

it('uses read-side Loki credentials with write-side fallback', function (?array $readAuth, ?string $readTenant, string $expectedTenant, string $expectedAuthorization): void {
    config()->set('eagle-eye.drivers.loki.write.tenant', 'write-tenant');
    config()->set('eagle-eye.drivers.loki.write.auth', ['type' => 'basic', 'username' => 'write-user', 'password' => 'write-password']);
    config()->set('eagle-eye.drivers.loki.read.tenant', $readTenant);
    config()->set('eagle-eye.drivers.loki.read.auth', $readAuth);
    app(DriverManager::class)->forgetDrivers();
    Http::fake(['*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => []]])]);

    $driver = app(DriverManager::class)->driver('loki');

    if (! $driver instanceof LokiDriver) {
        throw new LogicException('Expected Loki driver.');
    }

    expect($driver->count(new ActivityQuery()))->toBe(0);
    Http::assertSent(static fn (Request $request): bool => $expectedTenant === ($request->header('X-Scope-OrgID')[0] ?? null)
        && $expectedAuthorization === ($request->header('Authorization')[0] ?? null));
})->with([
    'override' => [
        ['type' => 'bearer', 'username' => null, 'password' => 'read-token'],
        'read-tenant',
        'read-tenant',
        'Bearer read-token',
    ],
    'fallback' => [null, null, 'write-tenant', 'Basic '.base64_encode('write-user:write-password')],
]);

it('rejects an invalid collector selector during provider boot registration', function (): void {
    config()->set('eagle-eye.drivers.loki.read.selector', '{namespace="my-app"} | json');

    expect(fn () => (new EagleEyeServiceProvider(app()))->register())
        ->toThrow(LokiMisconfigurationException::class, 'Invalid Loki read selector');
});

it('reports stdout collector erasure as failed without calling Loki', function (): void {
    Http::preventStrayRequests();
    $driver = new LokiDriver(
        new StdoutWriter('php://memory'),
        new LokiClient('http://loki.test'),
        new LokiLineFormatter(['app' => 'my-app']),
        new LokiQueryTranslator(selector: '{namespace="my-app"}'),
        ['app' => 'my-app'],
    );

    $result = $driver->forget('user', '42');

    expect($result->state)->toBe('failed')
        ->and($result->outcomes)->toBe([[
            'driver'      => 'loki',
            'state'       => 'failed',
            'removed'     => 0,
            'request_ids' => [],
        ]]);
    Http::assertNothingSent();
});

it('returns only the exact requested Loki entry identity', function (): void {
    $requested = DriverContractTests::entry(6201);
    $wrongStorage = DriverContractTests::entry(6202)->toStorage();
    $wrongStorage['title'] = 'mentions '.$requested->id;
    $wrong = ActivityEntry::fromStorage($wrongStorage);

    Http::fake(['*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'streams', 'result' => [
        ['stream' => ['app' => 'my-app'], 'values' => [
            ['1788796800000000000', json_encode($wrong->toStorage(), JSON_THROW_ON_ERROR)],
            ['1788796799000000000', json_encode($requested->toStorage(), JSON_THROW_ON_ERROR)],
        ]],
    ]]])]);
    $driver = new LokiDriver(
        new PushWriter('http://loki.test'),
        new LokiClient('http://loki.test'),
        new LokiLineFormatter(['app' => 'my-app']),
        new LokiQueryTranslator(),
        ['app' => 'my-app'],
    );

    expect($driver->find($requested->id)?->id)->toBe($requested->id);
    Http::assertSentCount(1);
    Http::assertSent(static fn ($request): bool => str_contains($request->url(), $requested->id));
});

it('rejects oversized Loki responses before decoding', function (): void {
    Http::fake(['*' => Http::response(str_repeat('x', 2_097_153))]);

    expect(fn (): array => (new LokiClient('http://loki.test'))->queryRange('{}', 1, '1', '2', 'forward'))
        ->toThrow(LokiWriteException::class, 'exceeds the 2 MiB decode limit');
});

it('stops a chunked Loki response at the transfer ceiling', function (): void {
    [$process, $endpoint, $directory] = startStreamingLokiFixture();
    memory_reset_peak_usage();
    $baseline = memory_get_usage(true);

    try {
        expect(fn (): array => (new LokiClient($endpoint))->queryRange('{}', 1, '1', '2', 'forward'))
            ->toThrow(LokiWriteException::class, 'exceeds the 2 MiB decode limit');

        expect(memory_get_peak_usage(true) - $baseline)->toBeLessThan(10 * 1024 * 1024);
    } finally {
        proc_terminate($process);
        proc_close($process);
        unlink($directory.'/router.php');
        rmdir($directory);
    }
});

it('enforces one deadline across a slow response transfer', function (): void {
    [$process, $endpoint, $directory] = startStreamingLokiFixture(<<<'PHP'
<?php
header('Content-Type: application/json');
header('Connection: close');
for ($byte = 0; $byte < 20; $byte++) {
    echo 'x';
    flush();
    usleep(300_000);
}
PHP);
    $thrown = null;
    $startedAt = hrtime(true);

    try {
        try {
            (new LokiClient($endpoint, timeout: 1))->queryRange('{}', 1, '1', '2', 'forward');
        } catch (Throwable $exception) {
            $thrown = $exception;
        }

        $elapsedSeconds = (hrtime(true) - $startedAt) / 1_000_000_000;

        expect($thrown)->toBeInstanceOf(Throwable::class)
            ->and($thrown?->getMessage())->toMatch('/deadline|timed out|timeout|error 28/i')
            ->and($elapsedSeconds)->toBeLessThan(2.5);
    } finally {
        proc_terminate($process);
        proc_close($process);
        unlink($directory.'/router.php');
        rmdir($directory);
    }
});

it('enforces one deadline across headers and stalled bodies', function (string $mode): void {
    [$process, $endpoint, $directory] = startRawLokiFixture($mode);
    $startedAt = hrtime(true);

    try {
        expect(fn (): array => (new LokiClient($endpoint, timeout: 1))->queryRange('{}', 1, '1', '2', 'forward'))
            ->toThrow(Exception::class);
        expect((hrtime(true) - $startedAt) / 1_000_000_000)->toBeLessThan(1.75);
    } finally {
        proc_terminate($process);
        proc_close($process);
        unlink($directory.'/server.php');
        rmdir($directory);
    }
})->with(['slow_headers', 'stalled_headers', 'stalled_body']);

it('enforces response byte boundaries without trusting length headers', function (): void {
    expect(BoundedResponse::read(new HttpResponse(new Psr7Response(body: str_repeat('a', 7))), 'boundary', 8))->toBe(str_repeat('a', 7))
        ->and(BoundedResponse::read(new HttpResponse(new Psr7Response(body: str_repeat('b', 8))), 'boundary', 8))->toBe(str_repeat('b', 8));

    expect(fn (): string => BoundedResponse::read(
        new HttpResponse(new Psr7Response(headers: ['Content-Length' => '1'], body: str_repeat('c', 9))),
        'boundary',
        8,
    ))->toThrow(LokiWriteException::class, 'decode limit');
});

it('rejects a stream handler before network IO because it cannot enforce the whole deadline', function (): void {
    BoundedResponse::assertDeadlineCapable(Http::acceptJson()->setHandler(new CurlHandler()));
    $request = Http::acceptJson()
        ->setHandler(new CurlHandler())
        ->withOptions(['handler' => new StreamHandler()]);

    expect(fn () => BoundedResponse::assertDeadlineCapable($request))
        ->toThrow(LokiWriteException::class, 'cannot enforce whole-operation deadlines');

    BoundedResponse::assertDeadlineCapable(
        Http::acceptJson()
            ->setHandler(new StreamHandler())
            ->withOptions(['handler' => new CurlHandler()]),
    );
});

it('rejects a globally configured stream handler before network IO', function (): void {
    Http::globalOptions(['handler' => new StreamHandler()]);

    try {
        expect(fn () => BoundedResponse::assertDeadlineCapable(Http::acceptJson()))
            ->toThrow(LokiWriteException::class, 'cannot enforce whole-operation deadlines');
    } finally {
        Http::globalOptions([]);
    }
});

it('rejects unrelated handlers whose class name mimics curl', function (): void {
    if (! class_exists('Untrusted\\CurlHandler')) {
        eval('namespace Untrusted; final class CurlHandler { public function __invoke(): void {} }');
    }

    $handler = eval('return new \\Untrusted\\CurlHandler();');

    if (! is_object($handler)) {
        throw new LogicException('Unable to create the unrelated HTTP handler.');
    }

    expect(fn () => BoundedResponse::assertDeadlineCapable(
        Http::acceptJson()->withOptions(['handler' => $handler]),
    ))->toThrow(LokiWriteException::class, 'cannot enforce whole-operation deadlines');
});

it('rejects Loki HTTP operations in a process without curl', function (): void {
    $source = <<<'PHP'
require $argv[1];
$request = new Illuminate\Http\Client\PendingRequest(new Illuminate\Http\Client\Factory());
try {
    Dotswan\EagleEye\Drivers\Loki\BoundedResponse::assertDeadlineCapable($request);
} catch (Dotswan\EagleEye\Drivers\Loki\LokiWriteException $exception) {
    fwrite(STDOUT, $exception->getMessage());
    exit(0);
}
exit(2);
PHP;
    $process = proc_open(
        [PHP_BINARY, '-n', '-r', $source, dirname(__DIR__, 2).'/vendor/autoload.php'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );

    if (! is_resource($process)) {
        throw new RuntimeException('Unable to start the no-cURL process.');
    }

    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    expect(proc_close($process))->toBe(0, $errors ?: 'No child-process error output.')
        ->and($output)->toContain('require the cURL extension');
});

it('rejects encoded response bodies before reading them', function (): void {
    expect(fn (): string => BoundedResponse::read(
        new HttpResponse(new Psr7Response(headers: ['Content-Encoding' => 'gzip'], body: 'encoded')),
        'encoding',
        16,
    ))->toThrow(LokiWriteException::class, 'unsupported content encoding');
});

it('bounds connection failures by the configured retry schedule', function (): void {
    $attempts = 0;
    Http::fake(static function () use (&$attempts): never {
        $attempts++;

        throw new ConnectionException('timed out');
    });

    $pusher = new PushWriter('http://loki.test', backoffMs: [1, 1]);
    $streams = (new LokiLineFormatter(['app' => 'my-app']))->streams([DriverContractTests::entry(1)]);

    expect(fn () => $pusher->write($streams))->toThrow(LokiWriteException::class)
        ->and($attempts)->toBe(3);
});

it('requests and retains only a bounded stable Loki actor ranking', function (): void {
    $series = [];

    foreach (['f', 'e', 'd', 'c', 'b', 'a'] as $actorId) {
        $series[] = ['metric' => ['actor_type' => 'user', 'actor_id' => $actorId], 'value' => ['1', '7']];
    }

    Http::fake(['*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => $series]])]);
    $driver = new LokiDriver(
        new PushWriter('http://loki.test'),
        new LokiClient('http://loki.test'),
        new LokiLineFormatter(['app' => 'my-app']),
        new LokiQueryTranslator(),
        ['app' => 'my-app'],
    );

    $groups = $driver->aggregateTop((new ActivityQuery())->last('1 day'), 'actor', 5);

    expect(array_keys($groups))->toBe([
        '["user","a"]',
        '["user","b"]',
        '["user","c"]',
        '["user","d"]',
        '["user","e"]',
    ])->and(array_values($groups))->toBe([7, 7, 7, 7, 7]);
    Http::assertSentCount(1);
});

it('returns deterministic Loki rankings beyond one hundred actors', function (): void {
    $series = array_map(
        static fn (int $actor): array => ['metric' => ['actor_type' => 'user', 'actor_id' => (string) $actor], 'value' => ['1', '7']],
        range(1, 101),
    );
    Http::fake(['*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => $series]])]);
    $driver = new LokiDriver(
        new PushWriter('http://loki.test'),
        new LokiClient('http://loki.test'),
        new LokiLineFormatter(['app' => 'my-app']),
        new LokiQueryTranslator(),
        ['app' => 'my-app'],
    );

    expect(array_keys($driver->aggregateTop((new ActivityQuery())->last('1 day'), 'actor', 5)))->toBe([
        '["user","1"]',
        '["user","10"]',
        '["user","100"]',
        '["user","101"]',
        '["user","11"]',
    ]);
    Http::assertSentCount(1);
});

it('returns an exact Loki ranking above one thousand actors when the cutoff is unambiguous', function (): void {
    $series = array_map(
        static fn (int $actor): array => [
            'metric' => ['actor_type' => 'user', 'actor_id' => sprintf('actor-%04d', $actor)],
            'value'  => ['1', (string) ($actor <= 5 ? 2_000 - $actor : 1)],
        ],
        range(1, 1_001),
    );
    Http::fake(['*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => $series]])]);
    $driver = new LokiDriver(
        new PushWriter('http://loki.test'),
        new LokiClient('http://loki.test'),
        new LokiLineFormatter(['app' => 'my-app']),
        new LokiQueryTranslator(),
        ['app' => 'my-app'],
    );

    expect($driver->aggregateTop((new ActivityQuery())->last('1 day'), 'actor', 5))->toBe([
        '["user","actor-0001"]' => 1_999,
        '["user","actor-0002"]' => 1_998,
        '["user","actor-0003"]' => 1_997,
        '["user","actor-0004"]' => 1_996,
        '["user","actor-0005"]' => 1_995,
    ]);
    Http::assertSentCount(1);
    Http::assertSent(static fn ($request): bool => str_contains((string) $request->data()['query'], 'topk(1001,'));
});

it('rejects a Loki ranking above one thousand actors when the requested cutoff is tied', function (): void {
    $series = array_map(
        static fn (int $actor): array => [
            'metric' => ['actor_type' => 'user', 'actor_id' => sprintf('actor-%04d', $actor)],
            'value'  => ['1', '1'],
        ],
        range(1, 1_001),
    );
    Http::fake(['*' => Http::response(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => $series]])]);
    $driver = new LokiDriver(
        new PushWriter('http://loki.test'),
        new LokiClient('http://loki.test'),
        new LokiLineFormatter(['app' => 'my-app']),
        new LokiQueryTranslator(),
        ['app' => 'my-app'],
    );

    expect(fn (): array => $driver->aggregateTop((new ActivityQuery())->last('1 day'), 'actor', 5))
        ->toThrow(LengthException::class, 'cutoff is ambiguous');
    Http::assertSentCount(1);
});

it('requires both actor and subject deletion requests to be accepted', function (): void {
    $receipts = [];
    Http::fake(function ($request) use (&$receipts) {
        if ('GET' === $request->method()) {
            return Http::response($receipts);
        }
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $parameters);
        $query = $parameters['query'] ?? null;
        $start = $parameters['start'] ?? null;
        $end = $parameters['end'] ?? null;

        if (! is_string($query) || ! is_string($start) || ! is_string($end)) {
            throw new LogicException('Expected scalar Loki deletion parameters.');
        }

        if (str_contains($query, 'actor_type')) {
            return Http::response('', 500);
        }
        $receipts[] = ['request_id' => 'aabbccdd', 'query' => $query, 'start_time' => strtotime($start), 'end_time' => strtotime($end), 'status' => 'received'];
        return Http::response('', 204);
    });
    $driver = new LokiDriver(
        new PushWriter('http://loki.test'),
        new LokiClient('http://loki.test'),
        new LokiLineFormatter(['app' => 'my-app']),
        new LokiQueryTranslator(),
        ['app' => 'my-app'],
    );

    $result = $driver->forget('user', '42');

    expect($result->state)->toBe('partial')
        ->and($result->outcomes[0]['request_ids'])->toBe(['aabbccdd'])
        ->and($result->outcomes[1]['request_ids'])->toBe([]);
    Http::assertSentCount(5);
    Http::assertSent(static fn ($request): bool => 'POST' === $request->method() && str_contains($request->url(), 'start=1970-01-01T00%3A00%3A00Z'));
});

it('rejects a Loki delete 404 even when it carries an unrelated request id', function (): void {
    $receipts = [];
    Http::fake(function ($request) use (&$receipts) {
        if ('GET' === $request->method()) {
            return Http::response($receipts);
        }
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $parameters);
        $query = $parameters['query'] ?? null;
        $start = $parameters['start'] ?? null;
        $end = $parameters['end'] ?? null;

        if (! is_string($query) || ! is_string($start) || ! is_string($end)) {
            throw new LogicException('Expected scalar Loki deletion parameters.');
        }

        if (str_contains($query, 'subject_type')) {
            return Http::response('', 404, ['X-Request-Id' => 'proxy-request']);
        }
        $receipts[] = ['request_id' => '11223344', 'query' => $query, 'start_time' => strtotime($start), 'end_time' => strtotime($end), 'status' => 'received'];
        return Http::response('', 204);
    });
    $driver = new LokiDriver(
        new PushWriter('http://loki.test'),
        new LokiClient('http://loki.test'),
        new LokiLineFormatter(['app' => 'my-app']),
        new LokiQueryTranslator(),
        ['app' => 'my-app'],
    );

    expect($driver->forget('user', '42')->state)->toBe('partial');
    Http::assertSentCount(5);
});

it('reuses an actual covering deletion receipt and rejects uncorrelated acceptance', function (): void {
    $query = '{app="my-app"} | actor_id="42"';
    Http::fakeSequence()->push([['request_id' => 'aabbccdd', 'query' => $query, 'start_time' => 0, 'end_time' => 1789040000, 'status' => 'received']]);
    expect((new LokiClient('http://loki.test'))->requestDelete($query, '1970-01-01T00:00:00Z', '2026-09-09T00:00:00Z'))->toBe('aabbccdd');
    Http::assertSentCount(1);

    Http::fakeSequence()->push([])->push('', 204)->push([]);
    expect((new LokiClient('http://loki.test'))->requestDelete($query, '1970-01-01T00:00:00Z', '2026-09-09T00:00:00Z'))->toBeNull();
});

it('does not reuse a processed deletion receipt for newly ingested matching logs', function (): void {
    $query = '{app="my-app"} | actor_id="42"';
    $processed = ['request_id' => 'aabbccdd', 'query' => $query, 'start_time' => 0, 'end_time' => 1789040000, 'status' => 'processed'];
    $replacement = ['request_id' => '11223344', 'query' => $query, 'start_time' => 0, 'end_time' => 1789040000, 'status' => 'received'];

    Http::fakeSequence()->push([$processed])->push('', 204)->push([$processed, $replacement]);

    expect((new LokiClient('http://loki.test'))->requestDelete($query, '1970-01-01T00:00:00Z', '2026-09-09T00:00:00Z'))->toBe('11223344');
    Http::assertSentCount(3);
});
