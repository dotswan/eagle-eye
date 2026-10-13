<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Filesystem\Filesystem;
use Dotswan\EagleEye\Testing\DriverContractTests;
use Dotswan\EagleEye\Contracts\Driver\ActivityLogDriverFactoryInterface;
use Dotswan\EagleEye\Contracts\Driver\ActivityLogDriverInterface;
use Dotswan\EagleEye\Contracts\Driver\FullActivityLogDriverInterface;
use Dotswan\EagleEye\Contracts\Transport\TransportInterface;
use Dotswan\EagleEye\Data\ActivityEntry;
use Dotswan\EagleEye\Data\ActivityDraft;
use Dotswan\EagleEye\Data\Subject;
use Dotswan\EagleEye\Data\Actor;
use Dotswan\EagleEye\Drivers\DriverManager;
use Dotswan\EagleEye\Drivers\MemoryDriver;
use Dotswan\EagleEye\Enums\ActivityChannel;
use Dotswan\EagleEye\Enums\ActivitySeverity;
use Dotswan\EagleEye\Enums\Capability;
use Dotswan\EagleEye\Facades\EagleEye;
use Dotswan\EagleEye\Privacy\SubjectEraser;
use Dotswan\EagleEye\Privacy\ErasureRegistry;
use Dotswan\EagleEye\Retention\RetentionPruner;
use Dotswan\EagleEye\Transport\ActivityBuffer;
use Dotswan\EagleEye\Transport\Flusher;
use Dotswan\EagleEye\Transport\GuardedTransport;
use Dotswan\EagleEye\Transport\Jobs\PersistActivityBatchJob;
use Dotswan\EagleEye\Tests\Support\AlwaysFailingDriver;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Dotswan\EagleEye\Capture\CaptureSuppression;
use Dotswan\EagleEye\Capture\QueryCapture;
use PHPUnit\Framework\Assert;
use Dotswan\EagleEye\Contracts\Repository\ActivityRepositoryInterface;
use Dotswan\EagleEye\Data\Query\ActivityQuery;
use Dotswan\EagleEye\Data\Query\Cursor;
use Dotswan\EagleEye\Data\Query\QueryResult;
use Dotswan\EagleEye\Data\WriteFailure;
use Dotswan\EagleEye\Data\EnvelopeBudget;
use Dotswan\EagleEye\Data\ErasureResult;
use Dotswan\EagleEye\Transport\QueueTransport;
use Dotswan\EagleEye\Transport\BatchWriteException;
use Dotswan\EagleEye\Transport\SyncTransport;
use Dotswan\EagleEye\Transport\SpoolTransport;
use Dotswan\EagleEye\Transport\SpoolFile;
use Dotswan\EagleEye\Transport\StreamTransport;
use Dotswan\EagleEye\Transport\SpoolFallbackException;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Support\Facades\Event;
use Dotswan\EagleEye\Drivers\ActivityLogSchema;
use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Database\SQLiteConnection;
use Mockery\MockInterface;

function swapInDriver(string $name, ActivityLogDriverInterface $driver, int $buffer = 100): void
{
    $manager = app(DriverManager::class);
    $manager->extend($name, fn (): ActivityLogDriverInterface => $driver);
    $manager->forgetDrivers();
    config()->set('eagle-eye.default', $name);
    config()->set('eagle-eye.transport.buffer', $buffer);
}

/** @return ActivityLogDriverInterface&MockInterface */
function mockActivityDriver(): ActivityLogDriverInterface
{
    $driver = Mockery::mock(ActivityLogDriverInterface::class);

    if (!$driver instanceof ActivityLogDriverInterface) {
        throw new LogicException('Mock driver contract unavailable.');
    }

    return $driver;
}

/** @return FullActivityLogDriverInterface&MockInterface */
function mockFullActivityDriver(): FullActivityLogDriverInterface
{
    $driver = Mockery::mock(FullActivityLogDriverInterface::class);

    if (!$driver instanceof FullActivityLogDriverInterface) {
        throw new LogicException('Mock full driver contract unavailable.');
    }

    return $driver;
}

/** @return QueueContract&MockInterface */
function mockActivityQueue(): QueueContract
{
    $queue = Mockery::mock(QueueContract::class);

    if (!$queue instanceof QueueContract) {
        throw new LogicException('Mock queue contract unavailable.');
    }

    return $queue;
}

it('queues one job per flush instead of per entry and the job persists', function (): void {
    $driver = new MemoryDriver();
    swapInDriver('memory', $driver);
    config()->set('eagle-eye.transport.driver', 'queue');

    Queue::fake();

    eagleEye()->record('one');
    eagleEye()->record('two');
    eagleEye()->record('three');
    app(Flusher::class)->flush();

    Queue::assertPushed(PersistActivityBatchJob::class, 1);

    $job = new PersistActivityBatchJob(array_map(
        static fn (ActivityEntry $entry): array => $entry->toStorage(),
        [DriverContractTests::entry(1)],
    ));
    $job->handle(app(ActivityLogDriverFactoryInterface::class), app(ErasureRegistry::class));

    expect($driver->entries())->toHaveCount(1);
});

it('force flushes when the buffer reaches capacity', function (): void {
    $driver = new MemoryDriver();
    swapInDriver('memory', $driver, 2);

    eagleEye()->record('first');
    eagleEye()->record('second');

    expect($driver->entries())->toHaveCount(2);

    eagleEye()->record('third');
    expect($driver->entries())->toHaveCount(2)
        ->and(app(ActivityBuffer::class)->pending())->toBe(1);

    app(Flusher::class)->flush();
    expect($driver->entries())->toHaveCount(3);
});

it('enforces finite buffer entry and byte ceilings', function (): void {
    $storage = DriverContractTests::entry(4)->toStorage();
    $storage['payload'] = ['chunks' => array_fill(0, 8, str_repeat('x', 7000))];
    $first = ActivityEntry::fromStorage($storage);
    $storage['id'] = DriverContractTests::id(5);
    $second = ActivityEntry::fromStorage($storage);
    $entryBytes = EnvelopeBudget::size($first);
    $buffer = new ActivityBuffer(100, $entryBytes + 1000);

    expect($buffer->push($first))->toBeFalse()
        ->and($buffer->pendingBytes())->toBe($entryBytes)
        ->and($buffer->wouldOverflow($second))->toBeTrue()
        ->and(fn (): ActivityBuffer => new ActivityBuffer(101))->toThrow(InvalidArgumentException::class);
});

it('delivers no more than one hundred entries or one MiB per batch', function (): void {
    $batches = [];
    $driver = mockActivityDriver();
    $driver->shouldReceive('writeBatch')->andReturnUsing(function (array $entries) use (&$batches): array {
        $batches[] = $entries;

        return [];
    });
    $buffer = new ActivityBuffer();

    for ($sequence = 10; $sequence < 130; $sequence++) {
        $storage = DriverContractTests::entry($sequence)->toStorage();
        $storage['payload'] = ['chunks' => array_fill(0, 8, str_repeat('x', 7000))];
        $entry = ActivityEntry::fromStorage($storage);

        if ($buffer->wouldOverflow($entry)) {
            (new Flusher($buffer, new SyncTransport(static fn (): ActivityLogDriverInterface => $driver)))->flush();
        }

        $buffer->push($entry);
    }

    (new Flusher($buffer, new SyncTransport(static fn (): ActivityLogDriverInterface => $driver)))->flush();
    $batchBytes = array_map(static fn(array $entries): int => 2 + array_sum(array_map(EnvelopeBudget::size(...), $entries)) + max(0, count($entries) - 1), $batches);

    if ([] === $batches || [] === $batchBytes) {
        throw new LogicException('Expected bounded delivery batches.');
    }

    expect(array_sum(array_map(count(...), $batches)))->toBe(120)
        ->and(max(array_map(count(...), $batches)))->toBeLessThanOrEqual(100)
        ->and(max($batchBytes))->toBeLessThanOrEqual(EnvelopeBudget::MAX_BATCH_BYTES)
        ->and($buffer->pending())->toBe(0)
        ->and($buffer->pendingBytes())->toBe(0);
});

it('rejects an oversized queued batch before serialization into a job', function (): void {
    $queue = mockActivityQueue();
    $queue->shouldNotReceive('push');
    $transport = new QueueTransport($queue);
    $entries = [];

    for ($sequence = 200; $sequence < 301; $sequence++) {
        $entries[] = DriverContractTests::entry($sequence);
    }

    expect(fn () => $transport->send($entries))->toThrow(LengthException::class);
});

it('reports a stream write failure instead of acknowledging delivery', function (): void {
    if (! is_writable('/dev/full')) {
        Assert::markTestSkipped('/dev/full is unavailable on this platform.');
    }

    expect(fn () => (new StreamTransport('/dev/full'))->send([
        DriverContractTests::entry(199),
    ]))->toThrow(RuntimeException::class, 'Unable to write');
});

it('lands entries in the spool when storage is down and never breaks the caller', function (): void {
    $path = storage_path('eagle-eye/test-'.uniqid().'/spool.log');
    swapInDriver('failing', new AlwaysFailingDriver(), 10);
    config()->set('eagle-eye.transport.spool.path', $path);
    app()->forgetInstance(TransportInterface::class);

    $entry = eagleEye()->record('during.outage');

    expect($entry)->not->toBeNull();

    app(Flusher::class)->flush();

    expect(is_file($path))->toBeTrue()
        ->and(mb_substr_count((string) file_get_contents($path), "\n"))->toBe(1)
        ->and(app(TransportInterface::class))->toBeInstanceOf(GuardedTransport::class);

    if (is_file($path)) {
        unlink($path);
    }
});

it('ships spooled entries to the driver and removes the spool', function (): void {
    $driver = new MemoryDriver();
    swapInDriver('memory', $driver);

    $directory = storage_path('eagle-eye/test-'.uniqid());
    mkdir($directory, 0o775, true);
    $path = $directory.'/spool.log';
    $line = json_encode(DriverContractTests::entry(9)->toStorage())."\n";
    file_put_contents($path, $line.$line);
    config()->set('eagle-eye.transport.spool.path', $path);

    $status = app(Kernel::class)->call('activity-log:ship');

    expect($status)->toBe(0);

    expect($driver->stats()['entries'])->toBe(1)
        ->and(is_file($path))->toBeFalse();
});

it('does not leak buffered entries across container scopes', function (): void {
    $driver = new MemoryDriver();
    swapInDriver('memory', $driver, 100);

    eagleEye()->record('scoped.entry');
    expect(app(ActivityBuffer::class)->pending())->toBe(1);

    app()->forgetScopedInstances();

    expect(app(ActivityBuffer::class)->pending())->toBe(0);
});

it('keeps memory bounded under heavy load', function (): void {
    $driver = new MemoryDriver(1000);
    swapInDriver('memory', $driver, 50);

    for ($i = 0; $i < 10_000; $i++) {
        eagleEye()->record('load.'.$i);
    }

    expect(app(ActivityBuffer::class)->pending())->toBeLessThanOrEqual(50)
        ->and($driver->entries())->toHaveCount(1000);
});

it('does not retain scoped capture state across ten thousand worker scopes', function (): void {
    $driver = new MemoryDriver(100);
    swapInDriver('scope-soak', $driver, 1);
    $baseline = memory_get_usage();
    memory_reset_peak_usage();

    for ($scope = 0; $scope < 10_000; $scope++) {
        app()->forgetScopedInstances();
        app(CaptureSuppression::class)->run(static fn (): null => null);
        eagleEye()->record(
            ActivityDraft::make('scope.'.$scope)
                ->withActor(new Actor('worker', (string) ($scope % 10)))
                ->withSubject(new Subject('job', (string) $scope)),
        );
    }

    expect(memory_get_usage() - $baseline)->toBeLessThan(8 * 1024 * 1024)
        ->and(memory_get_peak_usage() - $baseline)->toBeLessThan(16 * 1024 * 1024)
        ->and($driver->entries())->toHaveCount(100)
        ->and(app(ActivityBuffer::class)->pending())->toBe(0);
})->group('performance');

it('erases a subject and writes an anonymised tombstone', function (): void {
    $fake = EagleEye::fake();

    eagleEye()->record(ActivityDraft::make('subject.changed')->withSubject(new Subject('user', '42', 'Sara')));
    eagleEye()->record(
        ActivityDraft::make('actor.changed')
            ->withActor(new Actor('user', '42', 'Sara'))
            ->withSubject(new Subject('campaign', '99', 'Campaign')),
    );
    eagleEye()->record(ActivityDraft::make('other.changed')->withSubject(new Subject('user', '84', 'Alex')));

    $result = app(SubjectEraser::class)->erase('user', '42');

    $entries = $fake->recorded();
    $tombstones = array_values(array_filter($entries, static fn (ActivityEntry $entry): bool => 'privacy.subject.forgotten' === $entry->action));

    expect($result->state)->toBe('completed')
        ->and($result->removed)->toBe(2)
        ->and($entries)->toHaveCount(2)
        ->and($tombstones)->toHaveCount(1)
        ->and($tombstones[0]->payload)->not->toHaveKey('subject_id')
        ->and(json_encode($tombstones[0]->payload))->not->toContain('"42"')
        ->and(json_encode($tombstones[0]->payload))->not->toContain('Sara');
});

it('reports an unaccepted erasure as failure without a tombstone', function (): void {
    $fake = EagleEye::fake();
    $driver = mockFullActivityDriver();
    $driver->shouldReceive('forget')->once()->with('user', '42')->andReturn(ErasureResult::failed('asynchronous'));
    $driver->shouldReceive('name')->andReturn('asynchronous');
    swapInDriver('unaccepted-erasure', $driver);

    expect(fn () => app(SubjectEraser::class)->erase('user', '42'))
        ->toThrow(RuntimeException::class, 'did not accept the erasure request')
        ->and($fake->recorded())->toBe([]);
});

it('reports asynchronous erasure as accepted and pending', function (): void {
    EagleEye::fake();
    $written = [];
    $driver = mockFullActivityDriver();
    $driver->shouldReceive('forget')->once()->with('user', '42')->andReturn(ErasureResult::pending('asynchronous', ['request-1']));
    $driver->shouldReceive('name')->andReturn('asynchronous');
    $driver->shouldReceive('supports')->with(Capability::SynchronousDeletion)->andReturnFalse();
    $driver->shouldReceive('writeBatch')->once()->andReturnUsing(function (array $entries) use (&$written): array {
        $written = $entries;

        return [];
    });
    swapInDriver('accepted-erasure', $driver, 1);
    $kernel = app(Kernel::class);
    $status = $kernel->call('activity-log:forget', ['type' => 'user', 'id' => '42']);
    $output = $kernel->output();

    expect($status)->toBe(0)
        ->and($output)->toContain('accepted and pending')
        ->and($output)->not->toContain('Erased 0')
        ->and(array_map(static fn (ActivityEntry $entry): string => $entry->action, $written))
        ->toBe(['privacy.subject.erasure_requested']);
});

it('commits a pseudonymous erasure receipt and blocks a queued replay across worker resolution', function (): void {
    $fake = EagleEye::fake();
    $entry = DriverContractTests::entry(51, actorId: '42', subjectType: 'user', subjectId: '42');
    $job = new PersistActivityBatchJob([$entry->toStorage()]);
    $result = app(SubjectEraser::class)->erase('user', '42');

    expect($job)->toBeInstanceOf(ShouldBeEncrypted::class);
    $job->handle(app(ActivityLogDriverFactoryInterface::class), app(ErasureRegistry::class));
    config()->set('eagle-eye.default', 'database');
    app(DriverManager::class)->forgetDrivers();
    $database = app(ActivityLogDriverFactoryInterface::class)->driver();

    if (! $database instanceof FullActivityLogDriverInterface) {
        throw new LogicException('Expected full database driver.');
    }

    $database->write($entry);

    $barrier = DB::table('activity_erasure_barriers')->first();
    $receipt = DB::table('activity_erasure_receipts')->first();
    $serialized = json_encode([$barrier, $receipt], JSON_THROW_ON_ERROR);

    expect($result->state)->toBe('completed')
        ->and($fake->recorded())->toHaveCount(1)
        ->and($fake->recorded()[0]->action)->toBe('privacy.subject.forgotten')
        ->and($database->find($entry->id))->toBeNull()
        ->and($barrier?->identity_hmac)->toHaveLength(64)
        ->and($receipt?->state)->toBe('completed')
        ->and($receipt?->receipt_hmac)->toHaveLength(64)
        ->and($serialized)->not->toContain('"42"')
        ->and($serialized)->not->toContain('Sara');
});

it('records a partial erasure outcome and fails without ordinary telemetry', function (): void {
    $fake = EagleEye::fake();
    $driver = mockFullActivityDriver();
    $driver->shouldReceive('forget')->once()->andReturn(ErasureResult::combine([
        ErasureResult::completed('database', 2),
        ErasureResult::failed('loki'),
    ]));
    $driver->shouldReceive('name')->andReturn('multi');
    swapInDriver('partial-erasure', $driver);

    expect(fn () => app(SubjectEraser::class)->erase('user', '42'))
        ->toThrow(RuntimeException::class, 'state [partial]')
        ->and($fake->recorded())->toBe([])
        ->and(DB::table('activity_erasure_receipts')->value('state'))->toBe('partial');
});

it('accepts bounded long model classes and defaults missing creation timestamps consistently', function (): void {
    $entry = DriverContractTests::entry(73, subjectType: 'model', subjectId: '42');
    $arguments = get_object_vars($entry);
    $arguments['subject'] = new Subject('model', '42', className: str_repeat('Namespace', 20));
    $arguments['createdAt'] = null;
    $entry = new ActivityEntry(...$arguments);

    expect(app(ErasureRegistry::class)->allows($entry))->toBeTrue();
    app(ErasureRegistry::class)->begin(str_repeat('Namespace', 20), '42');
    expect(app(ErasureRegistry::class)->allows($entry))->toBeFalse();
});

it('keeps erasure blocked until a previously admitted remote delivery settles', function (): void {
    EagleEye::fake();
    $registry = app(ErasureRegistry::class);
    $entry = DriverContractTests::entry(74, subjectType: 'user', subjectId: '42');
    $target = new MemoryDriver();

    $registry->deliver([$entry], function (array $entries) use ($registry, $target): void {
        $registry->begin('user', '42');
        expect(fn () => $registry->assertDrained('user', '42'))->toThrow(RuntimeException::class, 'unresolved delivery');
        $target->writeBatch($entries);
    });

    $registry->assertDrained('user', '42');
    $target->forget('user', '42');
    $registry->deliver([$entry], function (array $entries) use ($target): void {
        $target->writeBatch($entries);
    });

    expect($target->find($entry->id))->toBeNull()
        ->and(DB::table('activity_delivery_attempts')->count())->toBe(0);
});

it('keeps killed remote delivery attempts durable across erasure and replay processes', function (): void {
    if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
        Assert::markTestSkipped('PCNTL and POSIX are required for the process-boundary regression.');
    }

    $database = tempnam(sys_get_temp_dir(), 'eagle-eye-erasure-');

    if (! is_string($database)) {
        throw new RuntimeException('Unable to allocate the erasure concurrency database.');
    }

    $connect = static function () use ($database): SQLiteConnection {
        $pdo = new PDO('sqlite:'.$database);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA busy_timeout = 2000');

        return new SQLiteConnection($pdo, $database, '', ['name' => 'sqlite']);
    };
    $connection = $connect();
    ActivityLogSchema::createErasureState($connection->getSchemaBuilder());
    $entry = DriverContractTests::entry(740, subjectType: 'user', subjectId: '42');
    $ready = $database.'.ready';
    $childError = $database.'.error';
    $child = pcntl_fork();

    if (-1 === $child) {
        throw new RuntimeException('Unable to fork the delivery worker.');
    }

    if (0 === $child) {
        try {
            $registry = new ErasureRegistry($connect(), str_repeat('s', 32));
            $registry->deliver([$entry], static function () use ($ready): void {
                file_put_contents($ready, 'admitted');

                while (is_file($ready)) {
                    usleep(10_000);
                }
            });
        } catch (Throwable $exception) {
            file_put_contents($childError, $exception::class.':'.$exception->getMessage());
        }

        exit(1);
    }

    try {
        $deadline = microtime(true) + 5;

        while (! is_file($ready) && ! is_file($childError) && microtime(true) < $deadline) {
            usleep(1_000);
        }

        if (is_file($childError)) {
            throw new RuntimeException((string) file_get_contents($childError));
        }

        expect(is_file($ready))->toBeTrue();
        $registry = new ErasureRegistry($connection, str_repeat('s', 32));
        $requestId = $registry->begin('user', '42');

        expect(fn () => $registry->assertDrained('user', '42'))
            ->toThrow(RuntimeException::class, 'unresolved delivery')
            ->and($connection->table('activity_erasure_receipts')->where('state', 'completed')->count())->toBe(0);

        posix_kill($child, SIGKILL);
        pcntl_waitpid($child, $status);
        $child = 0;
        expect(pcntl_wifsignaled($status))->toBeTrue()
            ->and(pcntl_wtermsig($status))->toBe(SIGKILL);

        $registry->finish($requestId, 'user', '42', ErasureResult::failed('remote'));
        $attemptId = $connection->table('activity_delivery_attempts')->where('identity_hmac', $registry->digest('user', '42'))->value('attempt_id');

        if (! is_string($attemptId)) {
            throw new LogicException('Expected a durable unresolved delivery attempt.');
        }

        $factory = Mockery::mock(ActivityLogDriverFactoryInterface::class);

        if (! $factory instanceof ActivityLogDriverFactoryInterface) {
            throw new LogicException('Expected an activity driver factory.');
        }

        $factory->shouldNotReceive('driver');
        (new PersistActivityBatchJob([$entry->toStorage()]))->handle($factory, $registry);
        $spoolPath = $database.'.spool';
        (new SpoolTransport($spoolPath, erasure: $registry))->send([$entry]);
        $called = false;
        $registry->deliver([$entry], static function () use (&$called): void {
            $called = true;
        });

        expect($called)->toBeFalse()
            ->and(is_file($spoolPath))->toBeFalse()
            ->and($connection->table('activity_delivery_attempts')->where('attempt_id', $attemptId)->exists())->toBeTrue();

        $registry->resolveAttempt($attemptId, 'remote settlement ticket 740', 'user', '42');
        $registry->assertDrained('user', '42');
        $retryId = $registry->begin('user', '42');
        $registry->finish($retryId, 'user', '42', ErasureResult::completed('remote', 0));

        expect($connection->table('activity_delivery_attempts')->count())->toBe(0)
            ->and($connection->table('activity_erasure_receipts')->where('id', $requestId)->value('state'))->toBe('failed')
            ->and($connection->table('activity_erasure_receipts')->where('id', $retryId)->value('state'))->toBe('completed')
            ->and($registry->allows($entry))->toBeFalse();
    } finally {
        if ($child > 0) {
            posix_kill($child, SIGKILL);
            pcntl_waitpid($child, $status);
        }

        foreach (glob($database.'.*') ?: [] as $artifact) {
            unlink($artifact);
        }

        unlink($database);
    }
});

it('retains ambiguous and unacknowledged delivery attempts until scoped operator settlement', function (bool $throws): void {
    $registry = app(ErasureRegistry::class);
    $entry = DriverContractTests::entry(75, subjectType: 'user', subjectId: '42');
    $deliver = fn () => $registry->deliver([$entry], static function (array $entries) use ($throws): void {
        if ($throws) {
            throw new RuntimeException('remote status unknown');
        }
    }, acknowledged: false);

    if ($throws) {
        expect($deliver)->toThrow(RuntimeException::class, 'remote status unknown');
    } else {
        $deliver();
    }

    $registry->begin('user', '42');
    expect(fn () => $registry->assertDrained('user', '42'))->toThrow(RuntimeException::class, 'unresolved delivery');
    $attempt = DB::table('activity_delivery_attempts')->value('attempt_id');

    if (! is_string($attempt)) {
        throw new LogicException('Expected unresolved delivery attempt.');
    }

    expect(fn () => $registry->resolveAttempt($attempt, 'backend settlement ticket 42', 'user', '999'))
        ->toThrow(RuntimeException::class, 'does not exist');
    $registry->resolveAttempt($attempt, 'backend settlement ticket 42', 'user', '42');
    $registry->assertDrained('user', '42');
    expect(DB::table('activity_delivery_attempts')->count())->toBe(0)
        ->and(DB::table('activity_erasure_receipts')->where('state', 'delivery_resolved')->count())->toBe(1);
})->with([[true], [false]]);

it('preserves barriers across app key rotation and fails closed when the dedicated key changes', function (): void {
    $registry = app(ErasureRegistry::class);
    $registry->begin('user', '42');
    config()->set('app.key', 'base64:'.base64_encode(str_repeat('r', 32)));
    app()->forgetInstance(ErasureRegistry::class);
    expect(app(ErasureRegistry::class)->blocks('user', '42'))->toBeTrue();
    config()->set('eagle-eye.security_key', str_repeat('replacement', 4));
    app()->forgetInstance(ErasureRegistry::class);
    expect(fn () => app(ErasureRegistry::class)->blocks('user', '42'))
        ->toThrow(RuntimeException::class, 'security key does not match');
});

it('rejects erasure inside a native PDO transaction before driver mutation', function (): void {
    $driver = mockFullActivityDriver();
    $driver->shouldNotReceive('forget');
    $driver->shouldReceive('name')->andReturn('database');
    swapInDriver('transaction-erasure', $driver);
    $connection = app('db')->connection();

    $connection->getPdo()->beginTransaction();

    try {
        expect(fn () => app(SubjectEraser::class)->erase('user', '42'))
            ->toThrow(RuntimeException::class, 'independent committed database boundary')
            ->and(DB::table('activity_erasure_receipts')->count())->toBe(0);
    } finally {
        $connection->getPdo()->rollBack();
    }
});

it('bounds erasure barriers and permits identity reuse only after explicit expiry cleanup', function (): void {
    $connection = app('db')->connection();

    $registry = new ErasureRegistry(
        $connection,
        'test-key',
        barrierDays: 31,
        maxReplayAgeDays: 30,
        maxBarriers: 1,
        maxReceipts: 10,
    );
    $registry->begin('user', '42');

    expect($registry->blocks('user', '42'))->toBeTrue()
        ->and(fn (): string => $registry->begin('user', '84'))
        ->toThrow(RuntimeException::class, 'barrier quota');

    DB::table('activity_erasure_barriers')
        ->where('identity_hmac', $registry->digest('user', '42'))
        ->update(['expires_at' => '2000-01-01 00:00:00.000000']);

    expect($registry->blocks('user', '42'))->toBeFalse()
        ->and($registry->purgeExpired())->toBe(1)
        ->and(DB::table('activity_erasure_barriers')->count())->toBe(0);
});

it('discards an erased identity from an existing spool while shipping valid neighbours', function (): void {
    $path = storage_path('eagle-eye/erasure-spool-'.uniqid().'/spool.log');
    $blocked = DriverContractTests::entry(61, actorId: '42', subjectType: 'user', subjectId: '42');
    $allowed = DriverContractTests::entry(62, actorId: '84', subjectType: 'user', subjectId: '84');
    (new SpoolTransport($path))->send([$blocked, $allowed]);
    $registry = app(ErasureRegistry::class);
    $requestId = $registry->begin('user', '42');
    $registry->finish($requestId, 'user', '42', ErasureResult::completed('database', 0));
    $driver = new MemoryDriver();
    swapInDriver('erasure-spool-target', $driver);
    config()->set('eagle-eye.transport.spool.path', $path);

    $status = app(Kernel::class)->call('activity-log:ship', ['--chunk' => 1]);

    expect($status)->toBe(0)
        ->and(array_map(static fn (ActivityEntry $entry): string => $entry->id, $driver->entries()))->toBe([$allowed->id])
        ->and(is_file($path))->toBeFalse()
        ->and(is_file($path.'.quarantine'))->toBeFalse();

    (new Filesystem())->deleteDirectory(dirname($path));
});

it('applies retention precedence for severity channel and tag rules', function (): void {
    $driver = new MemoryDriver();
    swapInDriver('retention-memory', $driver);
    $now = new DateTimeImmutable('2026-09-08 12:00:00 UTC');
    $entry = static function (int $sequence, ActivityChannel $channel, ActivitySeverity $severity, int $ageDays, bool $temporary = false) use ($now): ActivityEntry {
        $storage = DriverContractTests::entry($sequence, tags: $temporary ? ['temporary'] : [], channel: $channel, severity: $severity)->toStorage();
        $storage['logged_at'] = $now->modify("-{$ageDays} days")->format(DATE_ATOM);
        $storage['created_at'] = $storage['logged_at'];

        return ActivityEntry::fromStorage($storage);
    };

    $driver->writeBatch([
        $entry(1, ActivityChannel::Http, ActivitySeverity::Info, 40),
        $entry(2, ActivityChannel::Auth, ActivitySeverity::Info, 40),
        $entry(3, ActivityChannel::Http, ActivitySeverity::Error, 40),
        $entry(4, ActivityChannel::Custom, ActivitySeverity::Info, 20, true),
    ]);
    config()->set('eagle-eye.retention.default_days', 30);
    config()->set('eagle-eye.retention.channels.auth', 365);
    config()->set('eagle-eye.retention.severities.error', 60);
    config()->set('eagle-eye.retention.tags.temporary', 10);

    expect(app(RetentionPruner::class)->prune($now))->toBe(2)
        ->and(array_map(static fn (ActivityEntry $entry): string => $entry->id, $driver->entries()))
        ->toBe([DriverContractTests::id(2), DriverContractTests::id(3)]);
});

it('validates the complete retention plan before deleting anything', function (): void {
    $driver = new MemoryDriver();
    $driver->write(DriverContractTests::entry(6));
    swapInDriver('retention-invalid', $driver);
    config()->set('eagle-eye.retention.default_days', 30);
    config()->set('eagle-eye.retention.tags.keep-longer', 31);

    expect(fn () => app(RetentionPruner::class)->prune(new DateTimeImmutable('+1 year')))
        ->toThrow(InvalidArgumentException::class)
        ->and($driver->entries())->toHaveCount(1);
});

it('rejects malformed retention values before deleting anything', function (mixed $value): void {
    $driver = new MemoryDriver();
    $entry = DriverContractTests::entry(6100);
    $driver->write($entry);
    swapInDriver('retention-malformed', $driver);
    config()->set('eagle-eye.retention.default_days', $value);

    expect(fn () => app(RetentionPruner::class)->prune(new DateTimeImmutable('+1 year')))
        ->toThrow(InvalidArgumentException::class, 'retention.default_days')
        ->and(array_map(static fn (ActivityEntry $stored): string => $stored->id, $driver->entries()))->toBe([$entry->id]);
})->with([
    'non-numeric string' => ['not-a-number'],
    'zero'               => [0],
    'negative'           => [-1],
    'fraction'           => [1.5],
    'boolean'            => [true],
    'array'              => [[30]],
]);

it('rejects malformed retention rule maps before deleting anything', function (string $key, mixed $value): void {
    $driver = new MemoryDriver();
    $entry = DriverContractTests::entry(6101);
    $driver->write($entry);
    swapInDriver('retention-map-malformed', $driver);
    config()->set($key, $value);

    expect(fn () => app(RetentionPruner::class)->prune(new DateTimeImmutable('+1 year')))
        ->toThrow(InvalidArgumentException::class)
        ->and(array_map(static fn (ActivityEntry $stored): string => $stored->id, $driver->entries()))->toBe([$entry->id]);
})->with([
    'channels type'       => ['eagle-eye.retention.channels', 'auth'],
    'unknown channel'     => ['eagle-eye.retention.channels.unknown', 10],
    'invalid channel day' => ['eagle-eye.retention.channels.auth', false],
    'unknown severity'    => ['eagle-eye.retention.severities.unknown', 10],
    'invalid severity'    => ['eagle-eye.retention.severities.error', '10'],
    'tags type'           => ['eagle-eye.retention.tags', 'temporary'],
    'invalid tag days'    => ['eagle-eye.retention.tags.temporary', 2.5],
    'invalid tag key'     => ["eagle-eye.retention.tags.bad\ntag", 1],
]);

it('registers and executes the complete console command surface', function (): void {
    $fake = EagleEye::fake();
    $entry = eagleEye()->record(
        ActivityDraft::make('command.fixture')
            ->withActor(new Actor('user', '7', 'Sara'))
            ->withSubject(new Subject('user', '7', 'Sara')),
    );
    $kernel = app(Kernel::class);
    $directory = storage_path('eagle-eye/command-'.uniqid());
    $export = $directory.'/export.ndjson';
    $scaffold = $directory.'/scaffold';

    expect($entry)->not->toBeNull()
        ->and($kernel->call('activity-log:doctor'))->toBe(0)
        ->and($kernel->call('activity-log:tail', ['--limit' => 1]))->toBe(0)
        ->and($kernel->call('activity-log:trail', ['--user' => 'user:7']))->toBe(0)
        ->and($kernel->call('activity-log:trace', ['id' => $entry?->trace->traceId]))->toBe(0)
        ->and($kernel->call('activity-log:stats'))->toBe(0)
        ->and($kernel->call('activity-log:export', ['--output' => $export, '--chunk' => 1]))->toBe(0)
        ->and($kernel->call('activity-log:prune'))->toBe(0)
        ->and($kernel->call('activity-log:forget', ['type' => 'user', 'id' => '7']))->toBe(0)
        ->and($kernel->call('activity-log:verify'))->toBe(0)
        ->and($kernel->call('activity-log:cache'))->toBe(0)
        ->and($kernel->call('activity-log:loki-config'))->toBe(0)
        ->and($kernel->call('activity-log:loki-ship'))->toBe(0)
        ->and($kernel->call('activity-log:ship'))->toBe(0)
        ->and($kernel->call('activity-log:make-driver', ['name' => 'Archive', '--path' => $scaffold]))->toBe(0)
        ->and(is_file($export))->toBeTrue()
        ->and(is_file($scaffold.'/Drivers/ArchiveDriver.php'))->toBeTrue()
        ->and(is_file($scaffold.'/Config/archive.php'))->toBeTrue()
        ->and(is_file($scaffold.'/Tests/ArchiveDriverContractTest.php'))->toBeTrue();

    config()->set('eagle-eye.retention.channels.auth', 365);
    config()->set('eagle-eye.retention.severities.error', 90);
    config()->set('eagle-eye.retention.tags.temporary', 7);
    expect($kernel->call('activity-log:loki-config'))->toBe(1)
        ->and($kernel->output())->toContain('cannot enforce tag rules');

    expect(array_map(static fn (ActivityEntry $record): string => $record->action, $fake->recorded()))
        ->toContain('privacy.subject.forgotten');

    require $scaffold.'/Drivers/ArchiveDriver.php';
    $driverClass = 'App\\ActivityLog\\Drivers\\ArchiveDriver';
    $scaffoldedSource = (string) file_get_contents($scaffold.'/Drivers/ArchiveDriver.php');

    expect(is_a($driverClass, FullActivityLogDriverInterface::class, true))->toBeTrue()
        ->and($scaffoldedSource)->toContain('return false;', 'Implement persistent activity storage.');

    (new Filesystem())->deleteDirectory($directory);
});

it('reports prune forget and integrity command failures', function (): void {
    $kernel = app(Kernel::class);

    config()->set('eagle-eye.retention.default_days', 'invalid');
    expect($kernel->call('activity-log:prune'))->toBe(1)
        ->and($kernel->output())->toContain('must be an integer from 1 to 36500');

    $driver = mockFullActivityDriver();
    $driver->shouldReceive('forget')->once()->with('user', '42')->andReturn(ErasureResult::failed('remote'));
    $driver->shouldReceive('name')->andReturn('remote');
    swapInDriver('command-failure', $driver);
    expect($kernel->call('activity-log:forget', ['type' => 'user', 'id' => '42']))->toBe(1)
        ->and($kernel->output())->toContain('did not accept the erasure request');

    swapInDriver('verify-failure', new MemoryDriver());
    DB::table('activity_integrity_state')->where('name', 'chain')->update(['last_segment_id' => '01J00000000000000000999999']);
    expect($kernel->call('activity-log:verify'))->toBe(1)
        ->and($kernel->output())->toContain('Integrity failure');
});

it('warms only valid configured subject metadata', function (): void {
    config()->set('eagle-eye.subjects.per_class', [
        ActivityEntry::class => [],
        'Missing\\Subject'   => [],
        42                   => [],
    ]);

    $kernel = app(Kernel::class);

    expect($kernel->call('activity-log:cache'))->toBe(0)
        ->and($kernel->output())->toContain('Warmed 1 subject metadata definition or definitions.');
});

it('reports unsupported tail and invalid trail invocations', function (): void {
    swapInDriver('command-memory', new MemoryDriver());
    $kernel = app(Kernel::class);

    expect($kernel->call('activity-log:tail', ['--follow' => true]))->toBe(1)
        ->and($kernel->output())->toContain('Continuous live tail is not implemented')
        ->and($kernel->call('activity-log:trail'))->toBe(2)
        ->and($kernel->output())->toContain('Provide --user or --subject')
        ->and($kernel->call('activity-log:trail', ['--subject' => 'campaign:42']))->toBe(0);
});

it('exposes bounded spool inspection behavior', function (): void {
    $path = sys_get_temp_dir().'/eagle-eye-rows-'.bin2hex(random_bytes(8)).'.jsonl';
    $transport = new SpoolTransport($path);

    expect($transport->path())->toBe($path)
        ->and(SpoolTransport::rows($path))->toBe([]);

    file_put_contents($path, "\n");

    try {
        expect(SpoolTransport::rows($path))->toBe([]);
    } finally {
        unlink($path);
    }
});

it('fails before erasure when the driver lacks maintenance support', function (): void {
    $driver = mockActivityDriver();
    $driver->shouldReceive('name')->once()->andReturn('read-only');
    swapInDriver('read-only', $driver);

    expect(fn (): ErasureResult => app(SubjectEraser::class)->erase('user', '42'))
        ->toThrow(LogicException::class, 'does not support erasure');
});

it('records a failed erasure request when a maintenance driver throws', function (): void {
    $driver = mockFullActivityDriver();
    $driver->shouldReceive('forget')->once()->with('user', '42')->andThrow(new RuntimeException('remote unavailable'));
    $driver->shouldReceive('name')->once()->andReturn('remote');
    swapInDriver('throwing-maintenance', $driver);

    expect(fn (): ErasureResult => app(SubjectEraser::class)->erase('user', '42'))
        ->toThrow(RuntimeException::class, 'remote unavailable');

    expect(DB::table('activity_erasure_receipts')->where('state', 'failed')->exists())->toBeTrue();
});

it('prints every trace page and reports completion', function (): void {
    EagleEye::fake();
    $traceId = str_repeat('c', 32);
    $driver = app(ActivityLogDriverFactoryInterface::class)->driver();

    foreach (range(1, 16) as $sequence) {
        $driver->write(DriverContractTests::entry(800 + $sequence, traceId: $traceId));
    }

    $kernel = app(Kernel::class);
    $status = $kernel->call('activity-log:trace', ['id' => $traceId]);
    $output = $kernel->output();

    expect($status)->toBe(0)
        ->and($output)->toContain(DriverContractTests::id(816))
        ->and($output)->toContain('Trace complete: 16 entries.');
});

it('makes doctor fail for an invalid driver configuration', function (): void {
    config()->set('eagle-eye.default', 'missing');
    app(DriverManager::class)->forgetDrivers();

    expect(app(Kernel::class)->call('activity-log:doctor'))->toBe(1);
});

it('reports incomplete exports instead of successful truncated output', function (bool $repeatedCursor): void {
    $entry = DriverContractTests::entry(1901);
    $cursor = new Cursor($entry->loggedAt, $entry->id);
    $repository = Mockery::mock(ActivityRepositoryInterface::class);
    $repository->shouldReceive('search')->once()->andReturn(
        new QueryResult([$entry], $repeatedCursor ? $cursor : null, true),
    );

    if ($repeatedCursor) {
        $repository->shouldReceive('search')->once()->andReturn(new QueryResult([], clone $cursor, true));
    }

    app()->instance(ActivityRepositoryInterface::class, $repository);
    $target = tempnam(sys_get_temp_dir(), 'eagle-eye-export-');

    if (false === $target) {
        throw new RuntimeException('Cannot allocate isolated export target.');
    }

    try {
        $kernel = app(Kernel::class);

        expect($kernel->call('activity-log:export', ['--output' => $target]))->toBe(1)
            ->and($kernel->output())->toContain('Export incomplete after 1 entries')
            ->and($kernel->output())->not->toContain('Exported ')
            ->and(mb_substr_count((string) file_get_contents($target), PHP_EOL))->toBe(1);
    } finally {
        unlink($target);
    }
})->with([[false], [true]]);

it('suppresses internal SQL and HTTP during buffered and queued writes', function (): void {
    config()->set('database.default', 'testing');
    config()->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    config()->set('eagle-eye.capture.query.slow_threshold_ms', 0);
    app(QueryCapture::class)->register();
    Http::fake(['telemetry.test/*' => Http::response([], 200)]);
    $writes = 0;
    $actions = [];
    $driver = mockActivityDriver();
    $driver->shouldReceive('writeBatch')->andReturnUsing(function (array $entries) use (&$writes, &$actions): array {
        $writes++;

        if ($writes > 3) {
            throw new RuntimeException('Bounded recursion detector.');
        }

        foreach ($entries as $entry) {
            $actions[] = $entry->action;
        }

        DB::select('select 1');
        Http::post('https://telemetry.test/ingest', ['entries' => count($entries)]);

        return [];
    });
    swapInDriver('telemetry-boundary', $driver, 1);
    config()->set('eagle-eye.transport.on_failure', 'ignore');

    eagleEye()->record('host.first');
    (new PersistActivityBatchJob([DriverContractTests::entry(40)->toStorage()]))
        ->handle(app(ActivityLogDriverFactoryInterface::class), app(ErasureRegistry::class));
    eagleEye()->record('host.after');

    expect($writes)->toBe(3)
        ->and($actions)->toHaveCount(3)
        ->and($actions)->toContain('host.first', 'host.after')
        ->and(app(ActivityBuffer::class)->pending())->toBe(0)
        ->and(app(CaptureSuppression::class)->active())->toBeFalse();
    Http::assertSentCount(3);
    Http::assertSent(fn ($request): bool => ! $request->hasHeader('traceparent'));
});

it('suppresses repository reads and restores capture after read failure', function (): void {
    $driver = mockFullActivityDriver();
    $query = new ActivityQuery();
    $driver->shouldReceive('count')->once()->with($query)->andReturnUsing(function (): int {
        expect(app(CaptureSuppression::class)->active())->toBeTrue();
        eagleEye()->record('internal.read');

        throw new RuntimeException('Query unavailable.');
    });
    swapInDriver('read-boundary', $driver);

    expect(fn () => app(ActivityRepositoryInterface::class)->count($query))
        ->toThrow(RuntimeException::class, 'Query unavailable.');

    expect(app(CaptureSuppression::class)->active())->toBeFalse()
        ->and(app(ActivityBuffer::class)->pending())->toBe(0);
    eagleEye()->record('host.after.read');
    expect(app(ActivityBuffer::class)->pending())->toBe(1);
    app(ActivityBuffer::class)->drain();
});

it('suppresses telemetry for every repository read operation', function (string $method): void {
    $driver = mockFullActivityDriver();
    $result = match ($method) {
        'find'              => null,
        'search'            => QueryResult::empty(),
        'count'             => 0,
        'aggregate', 'tags' => [],
        default             => throw new LogicException('Unexpected repository method.'),
    };
    $driver->shouldReceive($method)->once()->andReturnUsing(function () use ($result): mixed {
        expect(app(CaptureSuppression::class)->active())->toBeTrue();
        eagleEye()->record('internal.read');

        return $result;
    });
    swapInDriver('read-boundary', $driver);
    $repository = app(ActivityRepositoryInterface::class);
    $actual = match ($method) {
        'find'      => $repository->find('id'),
        'search'    => $repository->search(new ActivityQuery()),
        'count'     => $repository->count(new ActivityQuery()),
        'aggregate' => $repository->aggregate(new ActivityQuery(), 'channel'),
        'tags'      => $repository->tags(),
    };

    expect($actual)->toBe($result)
        ->and(app(ActivityBuffer::class)->pending())->toBe(0)
        ->and(app(CaptureSuppression::class)->active())->toBeFalse();
})->with(['find', 'search', 'count', 'aggregate', 'tags']);

it('restores nested suppression after throws and across scopes', function (): void {
    $suppression = app(CaptureSuppression::class);
    $suppression->run(function () use ($suppression): void {
        expect(fn () => $suppression->run(static function (): void {
            throw new RuntimeException('Nested failure.');
        }))->toThrow(RuntimeException::class, 'Nested failure.');

        expect($suppression->active())->toBeTrue();
    });

    expect($suppression->active())->toBeFalse();
    app()->forgetScopedInstances();
    expect(app(CaptureSuppression::class))->not->toBe($suppression)
        ->and(app(CaptureSuppression::class)->active())->toBeFalse();
});

it('restores capture and propagates queued persistence failures', function (): void {
    $driver = mockActivityDriver();
    $driver->shouldReceive('writeBatch')->once()->andReturnUsing(function (): never {
        expect(app(CaptureSuppression::class)->active())->toBeTrue();

        throw new RuntimeException('Persistence unavailable.');
    });
    swapInDriver('failed-queue-boundary', $driver);
    $job = new PersistActivityBatchJob([DriverContractTests::entry(41)->toStorage()]);

    expect(fn () => $job->handle(app(ActivityLogDriverFactoryInterface::class), app(ErasureRegistry::class)))
        ->toThrow(BatchWriteException::class, 'Eagle Eye driver failed to write the delivery batch.');
    eagleEye()->record('host.after.failed.job');
    expect(app(CaptureSuppression::class)->active())->toBeFalse()
        ->and(app(ActivityBuffer::class)->pending())->toBe(1);
    app(ActivityBuffer::class)->drain();
});

it('uses finite queued retries and spools only terminally rejected entries', function (): void {
    $accepted = DriverContractTests::entry(42);
    $rejected = DriverContractTests::entry(43);
    $path = storage_path('eagle-eye/terminal-queue-'.uniqid().'/spool.log');
    config()->set('eagle-eye.transport.on_failure', 'spool');
    config()->set('eagle-eye.transport.spool.path', $path);
    $job = new PersistActivityBatchJob([$accepted->toStorage(), $rejected->toStorage()]);

    $job->failed(new BatchWriteException([$rejected], 'Rejected.'));

    expect($job->tries)->toBe(3)
        ->and($job->timeout)->toBe(30)
        ->and($job->failOnTimeout)->toBeTrue()
        ->and($job->backoff)->toBe([1, 5, 15])
        ->and(array_column(SpoolTransport::rows($path), 'id'))->toBe([$rejected->id])
        ->and(app(CaptureSuppression::class)->active())->toBeFalse();
    (new Filesystem())->deleteDirectory(dirname($path));
});

it('spools only entries explicitly rejected by the driver', function (): void {
    $accepted = DriverContractTests::entry(60);
    $rejected = DriverContractTests::entry(61);
    $driver = mockActivityDriver();
    $driver->shouldReceive('writeBatch')->once()->with([$accepted, $rejected])
        ->andReturn([new WriteFailure($rejected->id, 'Unavailable', '503')]);
    $path = storage_path('eagle-eye/partial-'.uniqid().'/spool.log');
    $transport = new GuardedTransport(new SyncTransport(fn (): ActivityLogDriverInterface => $driver), new SpoolTransport($path));

    $transport->send([$accepted, $rejected]);

    expect(array_column(SpoolTransport::rows($path), 'id'))->toBe([$rejected->id])
        ->and($transport->failures())->toBe(1);
    (new Filesystem())->deleteDirectory(dirname($path));
});

it('rejects malformed driver acknowledgements without claiming success', function (array $failures): void {
    $entry = DriverContractTests::entry(62);
    $driver = mockActivityDriver();
    $driver->shouldReceive('writeBatch')->once()->andReturn($failures);
    $transport = new SyncTransport(fn (): ActivityLogDriverInterface => $driver);

    expect(fn () => $transport->send([$entry]))->toThrow(RuntimeException::class);
})->with([
    'unknown ID'   => [[new WriteFailure('unknown', 'Unavailable')]],
    'duplicate ID' => [[new WriteFailure(DriverContractTests::id(62), 'Unavailable'), new WriteFailure(DriverContractTests::id(62), 'Unavailable')]],
    'invalid row'  => [['invalid']],
]);

it('does not delete spooled entries when writes return failures', function (): void {
    $entry = DriverContractTests::entry(63);
    $driver = mockActivityDriver();
    $driver->shouldReceive('writeBatch')->once()->andReturn([new WriteFailure($entry->id, 'Unavailable')]);
    swapInDriver('reject-spool', $driver);
    $path = storage_path('eagle-eye/rejected-'.uniqid().'/spool.log');
    (new SpoolTransport($path))->send([$entry]);
    config()->set('eagle-eye.transport.spool.path', $path);

    expect(app(Kernel::class)->call('activity-log:ship'))->toBe(1)
        ->and(array_column(SpoolTransport::rows($path), 'id'))->toBe([$entry->id]);
    (new Filesystem())->deleteDirectory(dirname($path));
});

it('enforces the spool quota against the incoming bytes atomically', function (): void {
    $entry = DriverContractTests::entry(70);
    $bytes = mb_strlen(json_encode($entry->toStorage(), JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR)."\n", '8bit');
    $path = storage_path('eagle-eye/quota-'.uniqid().'/spool.log');
    $spool = new SpoolTransport($path, $bytes);
    $spool->send([$entry]);

    expect(fn () => $spool->send([$entry]))->toThrow(RuntimeException::class)
        ->and(filesize($path))->toBe($bytes)
        ->and(SpoolTransport::rows($path))->toHaveCount(1);
    (new Filesystem())->deleteDirectory(dirname($path));
});

it('fails safely when the spool directory cannot be created', function (): void {
    $parent = tempnam(sys_get_temp_dir(), 'eagle-eye-spool-parent-');
    $path = $parent.'/spool.log';

    expect(fn () => (new SpoolTransport($path))->send([DriverContractTests::entry(80)]))
        ->toThrow(RuntimeException::class, 'Unable to create Eagle Eye spool directory');

    if (is_string($parent) && is_file($parent)) {
        unlink($parent);
    }
});

it('rejects a second concurrent spool shipper without touching its claim', function (): void {
    $entry = DriverContractTests::entry(81);
    $path = storage_path('eagle-eye/ship-lock-'.uniqid().'/spool.log');
    (new SpoolTransport($path))->send([$entry]);
    config()->set('eagle-eye.transport.spool.path', $path);
    swapInDriver('locked-spool', new MemoryDriver());
    $lock = fopen($path.'.ship.lock', 'c+b');

    expect($lock)->not->toBeFalse();

    if (false === $lock) {
        return;
    }

    flock($lock, LOCK_EX);
    expect(app(Kernel::class)->call('activity-log:ship'))->toBe(1)
        ->and(array_column(SpoolTransport::rows($path), 'id'))->toBe([$entry->id]);
    flock($lock, LOCK_UN);
    fclose($lock);
    (new Filesystem())->deleteDirectory(dirname($path));
});

it('preserves concurrent appends when a claimed spool delivery fails', function (): void {
    $original = DriverContractTests::entry(71);
    $concurrent = DriverContractTests::entry(72);
    $path = storage_path('eagle-eye/concurrent-'.uniqid().'/spool.log');
    $spool = new SpoolTransport($path);
    $spool->send([$original]);
    config()->set('eagle-eye.transport.spool.path', $path);
    $driver = mockActivityDriver();
    $driver->shouldReceive('writeBatch')->once()->andReturnUsing(function () use ($spool, $concurrent): never {
        $spool->send([$concurrent]);

        throw new RuntimeException('Driver unavailable.');
    });
    swapInDriver('concurrent-spool', $driver);

    expect(app(Kernel::class)->call('activity-log:ship'))->toBe(1)
        ->and(array_column(SpoolTransport::rows($path), 'id'))->toBe([$concurrent->id, $original->id]);

    $recovered = new MemoryDriver();
    swapInDriver('recovered-spool', $recovered);
    expect(app(Kernel::class)->call('activity-log:ship'))->toBe(0)
        ->and(array_map(static fn (ActivityEntry $entry): string => $entry->id, $recovered->entries()))
        ->toEqualCanonicalizing([$original->id, $concurrent->id]);
    (new Filesystem())->deleteDirectory(dirname($path));
});

it('checkpoints successful entries so only rejected entries are retried', function (): void {
    $accepted = DriverContractTests::entry(73);
    $rejected = DriverContractTests::entry(74);
    $path = storage_path('eagle-eye/checkpoint-'.uniqid().'/spool.log');
    (new SpoolTransport($path))->send([$accepted, $rejected]);
    config()->set('eagle-eye.transport.spool.path', $path);
    $driver = mockActivityDriver();
    $driver->shouldReceive('writeBatch')->once()->andReturn([new WriteFailure($rejected->id, 'Unavailable')]);
    swapInDriver('partial-spool', $driver);

    expect(app(Kernel::class)->call('activity-log:ship'))->toBe(1);

    $recovered = new MemoryDriver();
    swapInDriver('partial-spool-recovery', $recovered);
    expect(app(Kernel::class)->call('activity-log:ship'))->toBe(0)
        ->and(array_map(static fn (ActivityEntry $entry): string => $entry->id, $recovered->entries()))->toBe([$rejected->id]);
    (new Filesystem())->deleteDirectory(dirname($path));
});

it('recovers a current stale processing claim without an active spool', function (): void {
    $entry = DriverContractTests::entry(75);
    $path = storage_path('eagle-eye/stale-'.uniqid().'/spool.log');
    (new SpoolTransport($path))->send([$entry]);
    rename($path, $path.'.processing');
    config()->set('eagle-eye.transport.spool.path', $path);
    $driver = new MemoryDriver();
    swapInDriver('stale-spool', $driver);

    expect(app(Kernel::class)->call('activity-log:ship'))->toBe(0)
        ->and($driver->entries())->toHaveCount(1)
        ->and(is_file($path.'.processing'))->toBeFalse();
    (new Filesystem())->deleteDirectory(dirname($path));
});

it('replays idempotently after persistence crashes before checkpoint acknowledgement', function (): void {
    $entry = DriverContractTests::entry(79);
    $path = storage_path('eagle-eye/persist-before-ack-'.uniqid().'/spool.log');
    (new SpoolTransport($path))->send([$entry]);
    config()->set('eagle-eye.transport.spool.path', $path);
    $persisted = new MemoryDriver();
    $crashing = mockActivityDriver();
    $crashing->shouldReceive('writeBatch')->once()->andReturnUsing(function (array $entries) use ($persisted): never {
        $batch = [];

        foreach ($entries as $candidate) {
            if (! $candidate instanceof ActivityEntry) {
                throw new RuntimeException('Unexpected spool delivery value.');
            }

            $batch[] = $candidate;
        }

        $persisted->writeBatch($batch);

        throw new RuntimeException('Crashed before spool acknowledgement.');
    });
    swapInDriver('persist-before-ack', $crashing);

    expect(app(Kernel::class)->call('activity-log:ship'))->toBe(1)
        ->and(array_map(static fn (ActivityEntry $stored): string => $stored->id, $persisted->entries()))->toBe([$entry->id])
        ->and(glob($path.'.processing*'))->not->toBeEmpty();

    swapInDriver('persist-before-ack-restart', $persisted);

    expect(app(Kernel::class)->call('activity-log:ship'))->toBe(0)
        ->and(array_map(static fn (ActivityEntry $stored): string => $stored->id, $persisted->entries()))->toBe([$entry->id])
        ->and(glob($path.'.processing*'))->toBe([]);
    (new Filesystem())->deleteDirectory(dirname($path));
});

it('quarantines malformed and oversized rows while shipping valid neighbours', function (): void {
    $first = DriverContractTests::entry(76);
    $last = DriverContractTests::entry(77);
    $path = storage_path('eagle-eye/quarantine-'.uniqid().'/spool.log');
    $spool = new SpoolTransport($path);
    $spool->send([$first]);
    file_put_contents($path, "invalid json\n".str_repeat('x', 1_048_577)."\n", FILE_APPEND);
    $spool->send([$last]);
    config()->set('eagle-eye.transport.spool.path', $path);
    $driver = new MemoryDriver();
    swapInDriver('quarantine-spool', $driver);

    expect(app(Kernel::class)->call('activity-log:ship'))->toBe(1)
        ->and(array_map(static fn (ActivityEntry $entry): string => $entry->id, $driver->entries()))->toBe([$first->id, $last->id])
        ->and(is_file($path.'.quarantine'))->toBeTrue()
        ->and(filesize($path.'.quarantine'))->toBe(1_048_591);
    (new Filesystem())->deleteDirectory(dirname($path));
});

it('quarantines current-schema poison rows while shipping exact valid neighbours', function (): void {
    $first = DriverContractTests::entry(7601);
    $last = DriverContractTests::entry(7603);
    $invalidEnum = DriverContractTests::entry(7610)->toStorage();
    $invalidEnum['channel'] = 'unknown-channel';
    $missingRequired = DriverContractTests::entry(7611)->toStorage();
    unset($missingRequired['id']);
    $invalidDate = DriverContractTests::entry(7612)->toStorage();
    $invalidDate['logged_at'] = 'not-a-date';
    $invalidSecret = DriverContractTests::entry(7613)->toStorage();
    $invalidSecret['secrets'] = ['payload.token' => ['alg' => []]];
    $overBudget = DriverContractTests::entry(7614)->toStorage();
    $overBudget['payload'] = array_fill(0, 17, str_repeat('x', 8192));
    $overBudget['payload_bytes'] = 139_264;
    $path = storage_path('eagle-eye/schema-quarantine-'.uniqid().'/spool.log');
    $spool = new SpoolTransport($path);
    $spool->send([$first]);
    foreach ([$invalidEnum, $missingRequired, $invalidDate, $invalidSecret, $overBudget] as $poison) {
        file_put_contents($path, json_encode($poison, JSON_THROW_ON_ERROR)."\n", FILE_APPEND);
    }
    $spool->send([$last]);
    config()->set('eagle-eye.transport.spool.path', $path);
    $driver = new MemoryDriver();
    swapInDriver('schema-quarantine-spool', $driver);

    expect(app(Kernel::class)->call('activity-log:ship'))->toBe(1)
        ->and(array_map(static fn (ActivityEntry $entry): string => $entry->id, $driver->entries()))->toBe([$first->id, $last->id])
        ->and(file($path.'.quarantine', FILE_IGNORE_NEW_LINES))->toHaveCount(5)
        ->and(glob($path.'.processing*'))->toBe([]);
    $quarantineBytes = filesize($path.'.quarantine');

    expect(app(Kernel::class)->call('activity-log:ship'))->toBe(0)
        ->and(array_map(static fn (ActivityEntry $entry): string => $entry->id, $driver->entries()))->toBe([$first->id, $last->id])
        ->and(filesize($path.'.quarantine'))->toBe($quarantineBytes);
    (new Filesystem())->deleteDirectory(dirname($path));
});

it('retains a poison source row when quarantine quota is unavailable', function (): void {
    $path = storage_path('eagle-eye/quarantine-quota-'.uniqid().'/spool.log');
    (new SpoolTransport($path))->send([DriverContractTests::entry(7620)]);
    file_put_contents($path, "invalid json\n", FILE_APPEND);
    $spool = new SpoolFile($path, (int) filesize($path));

    expect(fn (): int => $spool->ship(static fn (array $rows): array => [], 100))
        ->toThrow(RuntimeException::class, 'quarantine quota exceeded')
        ->and(array_values(array_filter(glob($path.'.processing*') ?: [], static fn (string $claim): bool => ! str_ends_with($claim, '.offset'))))->toHaveCount(1);
    (new Filesystem())->deleteDirectory(dirname($path));
});

it('rejects a corrupt checkpoint beyond the claimed data', function (): void {
    $entry = DriverContractTests::entry(78);
    $path = storage_path('eagle-eye/corrupt-checkpoint-'.uniqid().'/spool.log');
    (new SpoolTransport($path))->send([$entry]);
    rename($path, $path.'.processing');
    file_put_contents($path.'.processing.offset', '99999999');
    config()->set('eagle-eye.transport.spool.path', $path);
    $driver = new MemoryDriver();
    swapInDriver('corrupt-checkpoint-spool', $driver);

    expect(app(Kernel::class)->call('activity-log:ship'))->toBe(1)
        ->and($driver->entries())->toBe([])
        ->and(is_file($path.'.processing'))->toBeTrue();
    (new Filesystem())->deleteDirectory(dirname($path));
});

it('defers buffered delivery until an open transaction commits', function (): void {
    $driver = new MemoryDriver();
    swapInDriver('transaction-deferral', $driver);
    app()->forgetInstance(TransportInterface::class);

    DB::transaction(function () use ($driver): void {
        eagleEye()->record('inside.transaction');
        expect(app(Flusher::class)->flush())->toBe(0)
            ->and($driver->entries())->toHaveCount(0);
    });

    expect($driver->entries())->toHaveCount(1)
        ->and(app(ActivityBuffer::class)->pending())->toBe(0);
});

it('discards the deferred delivery when the transaction rolls back', function (): void {
    $driver = new MemoryDriver();
    swapInDriver('transaction-rollback', $driver);

    try {
        DB::transaction(function (): void {
            eagleEye()->record('rolled.back.entry');
            app(Flusher::class)->flush();

            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }

    expect($driver->entries())->toHaveCount(0);

    app(Flusher::class)->flush();
    expect($driver->entries())->toHaveCount(1);
});

it('does not flush on sync job events but does on queued connections', function (): void {
    $driver = new MemoryDriver();
    swapInDriver('sync-flush-guard', $driver);
    /** @var JobContract $job */
    $job = Mockery::mock(JobContract::class);

    eagleEye()->record('host.job.entry');

    Event::dispatch(new JobProcessed('sync', $job));
    expect($driver->entries())->toHaveCount(0);

    Event::dispatch(new JobProcessed('redis', $job));
    expect($driver->entries())->not->toHaveCount(0);
});

it('reports a spool fallback once per transport instance', function (): void {
    $path = storage_path('eagle-eye/report-once-'.uniqid().'/spool.log');
    $handler = Mockery::mock(ExceptionHandler::class);
    $handler->shouldReceive('report')->once()->with(Mockery::type(SpoolFallbackException::class));
    app()->instance(ExceptionHandler::class, $handler);

    $transport = new GuardedTransport(
        new SyncTransport(static fn (): ActivityLogDriverInterface => new AlwaysFailingDriver()),
        new SpoolTransport($path),
    );

    $transport->send([DriverContractTests::entry(90)]);
    $transport->send([DriverContractTests::entry(91)]);

    expect($transport->failures())->toBe(2)
        ->and(array_column(SpoolTransport::rows($path), 'id'))->toHaveCount(2);

    (new Filesystem())->deleteDirectory(dirname($path));
});

it('warns in doctor when the spool holds pending entries', function (): void {
    $driver = new MemoryDriver();
    swapInDriver('doctor-spool', $driver);
    $path = storage_path('eagle-eye/doctor-spool-'.uniqid().'/spool.log');
    (new SpoolTransport($path))->send([DriverContractTests::entry(95)]);
    config()->set('eagle-eye.transport.spool.path', $path);

    $kernel = app(Kernel::class);

    expect($kernel->call('activity-log:doctor'))->toBe(0)
        ->and($kernel->output())->toContain('pending in spool');

    (new Filesystem())->deleteDirectory(dirname($path));
});

it('warns in doctor when the published pipeline omits the enricher pipe', function (): void {
    swapInDriver('doctor-pipeline', new MemoryDriver());
    config()->set('eagle-eye.pipeline', []);

    $kernel = app(Kernel::class);

    expect($kernel->call('activity-log:doctor'))->toBe(0)
        ->and($kernel->output())->toContain('missing');
});

it('delivers again after a savepoint commit and an outer rollback', function (): void {
    $driver = new MemoryDriver();
    swapInDriver('deferral-savepoint', $driver);

    try {
        DB::transaction(function (): void {
            try {
                DB::transaction(function (): void {
                    eagleEye()->record('savepoint.entry');
                    app(Flusher::class)->flush();

                    throw new RuntimeException('savepoint rollback');
                });
            } catch (RuntimeException) {
            }

            throw new RuntimeException('outer rollback');
        });
    } catch (RuntimeException) {
    }

    expect($driver->entries())->toHaveCount(0);

    DB::transaction(function (): void {
        eagleEye()->record('after.entry');
        app(Flusher::class)->flush();
    });

    expect($driver->entries())->toHaveCount(2);
});

it('delivers once per sequential transaction', function (): void {
    $driver = new MemoryDriver();
    swapInDriver('deferral-sequential', $driver);

    DB::transaction(function (): void {
        eagleEye()->record('first.entry');
        app(Flusher::class)->flush();
    });

    expect($driver->entries())->toHaveCount(1);

    DB::transaction(function (): void {
        eagleEye()->record('second.entry');
        app(Flusher::class)->flush();
    });

    expect($driver->entries())->toHaveCount(2);
});

it('delivers nested transaction entries exactly once at the outer commit', function (): void {
    $driver = new MemoryDriver();
    swapInDriver('deferral-nested', $driver);

    DB::transaction(function () use ($driver): void {
        eagleEye()->record('outer.entry');
        app(Flusher::class)->flush();

        DB::transaction(function (): void {
            eagleEye()->record('inner.entry');
            app(Flusher::class)->flush();
        });

        expect($driver->entries())->toHaveCount(0);
    });

    expect($driver->entries())->toHaveCount(2);
});
