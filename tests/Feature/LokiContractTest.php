<?php

declare(strict_types=1);

use Dotswan\EagleEye\Data\ActivityEntry;
use Dotswan\EagleEye\Data\EnvelopeBudget;
use Dotswan\EagleEye\Data\Query\ActivityQuery;
use Dotswan\EagleEye\Data\Query\Cursor;
use Dotswan\EagleEye\Drivers\Loki\LokiDriver;
use Dotswan\EagleEye\Drivers\Loki\LokiLineFormatter;
use Dotswan\EagleEye\Drivers\Loki\LokiQueryTranslator;
use Dotswan\EagleEye\Drivers\Loki\LokiStream;
use Dotswan\EagleEye\Drivers\Loki\Reader\LokiClient;
use Dotswan\EagleEye\Drivers\Loki\Writers\LokiWriterInterface;
use Dotswan\EagleEye\Drivers\Loki\Writers\PushWriter;
use Dotswan\EagleEye\Drivers\Loki\Writers\StdoutWriter;
use Dotswan\EagleEye\Enums\ActivityChannel;
use Dotswan\EagleEye\Enums\Capability;
use Dotswan\EagleEye\Enums\SortOrder;
use Dotswan\EagleEye\Http\Resources\ActivityEntryResource;
use Dotswan\EagleEye\Testing\DriverContractTests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/** @phpstan-impure */
function lokiTestEndpoint(): ?string
{
    $endpoint = getenv('EAGLE_EYE_LOKI_TEST_URL');

    return false !== $endpoint && '' !== $endpoint ? $endpoint : null;
}

function lokiDriverFor(string $tenantLabel = 'contract'): LokiDriver
{
    if ('collector' === lokiTestMode()) {
        return realCollectorLokiDriver($tenantLabel);
    }

    $endpoint = (string) lokiTestEndpoint();
    $app = sprintf('eagle-eye-%s-%d', $tenantLabel, (int) (microtime(true) * 1000));

    return new LokiDriver(
        new PushWriter($endpoint, backoffMs: [50, 200, 500]),
        new LokiClient($endpoint),
        new LokiLineFormatter(['app' => $app]),
        new LokiQueryTranslator('json' !== (getenv('EAGLE_EYE_LOKI_MODE') ?: 'metadata')),
        ['app' => $app],
    );
}

function settlingLokiDriver(): LokiDriver
{
    if ('collector' === lokiTestMode()) {
        return realCollectorLokiDriver('contract');
    }

    $endpoint = (string) lokiTestEndpoint();
    $app = sprintf('eagle-eye-contract-%d', (int) (microtime(true) * 1000));

    return new LokiDriver(
        new readonly class ($endpoint) implements LokiWriterInterface {
            private PushWriter $inner;

            public function __construct(string $endpoint)
            {
                $this->inner = new PushWriter($endpoint, backoffMs: [50, 200, 500]);
            }

            public function write(array $streams): void
            {
                $this->inner->write($streams);

                usleep(250_000);
            }

            public function healthy(): bool
            {
                return $this->inner->healthy();
            }
        },
        new LokiClient($endpoint),
        new LokiLineFormatter(['app' => $app]),
        new LokiQueryTranslator('json' !== (getenv('EAGLE_EYE_LOKI_MODE') ?: 'metadata')),
        ['app' => $app],
    );
}

/** @phpstan-impure */
function lokiTestMode(): string
{
    $mode = getenv('EAGLE_EYE_LOKI_MODE');

    return false !== $mode && '' !== $mode ? $mode : 'metadata';
}

/**
 * @param null|Closure(string, int): string $timestamp
 */
function realCollectorLokiDriver(string $tenantLabel = 'contract', ?Closure $timestamp = null): LokiDriver
{
    $endpoint = (string) lokiTestEndpoint();
    $namespace = 'ee-collector-'.$tenantLabel.'-'.bin2hex(random_bytes(6));
    $app = 'eagle-eye-'.$tenantLabel.'-'.bin2hex(random_bytes(6));
    $target = tempnam(sys_get_temp_dir(), 'eagle-eye-loki-');

    if (false === $target) {
        throw new RuntimeException('Unable to create collector stdout fixture.');
    }

    $timestamp ??= static fn (string $sourceNanoseconds, int $_index): string => $sourceNanoseconds;

    $writer = new class ($endpoint, $namespace, $target, $timestamp) implements LokiWriterInterface {
        private readonly StdoutWriter $stdout;

        private int $offset = 0;

        /** @param Closure(string, int): string $timestamp */
        public function __construct(
            private readonly string $endpoint,
            private readonly string $namespace,
            private readonly string $target,
            private readonly Closure $timestamp,
        ) {
            $this->stdout = new StdoutWriter($target);
        }

        public function __destruct()
        {
            @unlink($this->target);
        }

        /** @param list<LokiStream> $streams */
        public function write(array $streams): void
        {
            $sourceNanoseconds = [];

            foreach ($streams as $stream) {
                foreach ($stream->values as [$nanoseconds]) {
                    $sourceNanoseconds[] = $nanoseconds;
                }
            }

            $this->stdout->write($streams);
            $handle = fopen($this->target, 'rb');

            if (false === $handle) {
                throw new RuntimeException('Unable to read collector stdout fixture.');
            }

            try {
                if (0 !== fseek($handle, $this->offset)) {
                    throw new RuntimeException('Unable to read collector stdout fixture.');
                }

                $contents = stream_get_contents($handle);
            } finally {
                fclose($handle);
            }

            if (false === $contents) {
                throw new RuntimeException('Unable to read collector stdout fixture.');
            }

            $this->offset += mb_strlen($contents, '8bit');
            $lines = array_values(array_filter(explode("\n", $contents), static fn (string $line): bool => '' !== $line));

            if (count($sourceNanoseconds) !== count($lines)) {
                throw new RuntimeException('Collector stdout fixture line count mismatch.');
            }

            $values = [];

            foreach ($lines as $index => $line) {
                $nanoseconds = ($this->timestamp)($sourceNanoseconds[$index], $index);
                $values[] = [$nanoseconds, json_encode([
                    'level'   => 'info',
                    'logger'  => 'http.log.access',
                    'request' => ['method' => 'GET', 'uri' => '/health'],
                ], JSON_THROW_ON_ERROR)];
                $values[] = [$nanoseconds, $line];
                $values[] = [$nanoseconds, json_encode([
                    'time'    => (new DateTimeImmutable())->format(DATE_RFC3339_EXTENDED),
                    'level'   => 'INFO',
                    'app'     => 'MyApp',
                    'action'  => 'system',
                    'message' => 'foreign application log',
                ], JSON_THROW_ON_ERROR)];
            }

            Http::timeout(5)->post($this->endpoint.'/loki/api/v1/push', [
                'streams' => [[
                    'stream' => [
                        'namespace' => $this->namespace,
                        'stream'    => 'stdout',
                        'action'    => 'foreign',
                    ],
                    'values' => $values,
                ]],
            ])->throw();

            usleep(250_000);
        }

        public function healthy(): bool
        {
            return $this->stdout->healthy()
                && Http::timeout(5)->get($this->endpoint.'/ready')->successful();
        }
    };

    return new LokiDriver(
        $writer,
        new LokiClient($endpoint),
        new LokiLineFormatter(['app' => $app]),
        new LokiQueryTranslator(false, selector: sprintf('{namespace="%s"}', $namespace)),
        ['app' => $app],
    );
}

function lokiEntryAt(int $sequence, string $timestamp): ActivityEntry
{
    $storage = DriverContractTests::entry($sequence)->toStorage();
    $storage['logged_at'] = $timestamp;
    $storage['created_at'] = $timestamp;

    return ActivityEntry::fromStorage($storage);
}

if (null !== lokiTestEndpoint()) {
    DriverContractTests::define('collector' === lokiTestMode() ? 'loki-collector' : 'loki', static fn (): LokiDriver => settlingLokiDriver());
    DriverContractTests::defineSeries('collector' === lokiTestMode() ? 'loki-collector' : 'loki', static fn (): LokiDriver => settlingLokiDriver());

    it('matches exact tags and subject classes without relying on structured metadata', function (): void {
        $driver = lokiDriverFor('exact-filters');
        $tags = ['billing', 'rebilling', 'a,b', 'say"hello', 'path\\name', ''];
        foreach ($tags as $offset => $tag) {
            $row = DriverContractTests::entry($offset + 1, tags: [$tag])->toStorage();
            $row['subject_class'] = 'App\\Models\\Invoice';
            $entry = ActivityEntry::fromStorage($row);
            $driver->write($entry);
        }
        usleep(300_000);
        foreach ($tags as $offset => $tag) {
            expect(array_map(static fn (ActivityEntry $entry): string => $entry->id, $driver->search((new ActivityQuery())->withAnyTag([$tag]))->entries))
                ->toBe('' === $tag ? [] : [DriverContractTests::id($offset + 1)]);
        }
        expect($driver->search((new ActivityQuery())->forSubject('App\\Models\\Invoice', '42'))->entries)->toHaveCount(6)
            ->and($driver->search((new ActivityQuery())->forSubject('campaign', '42'))->entries)->toHaveCount(6)
            ->and($driver->search((new ActivityQuery())->withAnyTag(['billing', 'a,b']))->entries)->toHaveCount(2);
    });

    it('finds the top level identity before limiting nested identity collisions', function (): void {
        $driver = lokiDriverFor('nested-identities');
        $target = DriverContractTests::entry(1);
        $driver->write($target);
        for ($index = 2; $index <= 12; $index++) {
            $row = DriverContractTests::entry($index)->toStorage();
            $row['payload'] = ['id' => $target->id];
            $driver->write(ActivityEntry::fromStorage($row));
        }
        usleep(300_000);
        expect($driver->find($target->id)?->id)->toBe($target->id)
            ->and($driver->find(DriverContractTests::id(999)))->toBeNull();
    });

    it('paginates valid large same-timestamp envelopes within the response budget', function (): void {
        $driver = lokiDriverFor('large-pages');
        $at = new DateTimeImmutable('-1 minute');
        for ($index = 1; $index <= 40; $index++) {
            $row = DriverContractTests::entry($index)->toStorage();
            $row['logged_at'] = $at->format('Y-m-d H:i:s.u');
            $row['payload'] = array_fill(0, 7, str_repeat('a', 8000));
            $entry = ActivityEntry::fromStorage($row);
            expect(EnvelopeBudget::size($entry))->toBeLessThan(EnvelopeBudget::MAX_ENTRY_BYTES);
            $driver->write($entry);
        }
        usleep(300_000);
        $query = (new ActivityQuery())->limit(7);
        $ids = [];
        $before = memory_get_usage(true);
        do {
            $result = $driver->search($query);
            foreach ($result->entries as $entry) {
                $ids[] = $entry->id;
            }
            $query->cursor($result->nextCursor);
        } while ($result->hasMore);
        expect($ids)->toBe(array_map(DriverContractTests::id(...), range(40, 1)))
            ->and(memory_get_usage(true) - $before)->toBeLessThan(32 * 1024 * 1024)
            ->and($driver->search((new ActivityQuery())->limit(1))->entries)->toHaveCount(1);
    });

    it('correlates real compactor receipts and observes completed actor and subject deletion', function (): void {
        $labels = ['app' => 'erasure-'.bin2hex(random_bytes(6))];
        $driver = new LokiDriver(
            new PushWriter((string) lokiTestEndpoint()),
            new LokiClient((string) lokiTestEndpoint()),
            new LokiLineFormatter($labels),
            new LokiQueryTranslator('json' !== (getenv('EAGLE_EYE_LOKI_MODE') ?: 'metadata')),
            $labels,
        );
        $driver->write(DriverContractTests::entry(1, actorId: 'erase-me', subjectId: 'other'));
        $driver->write(DriverContractTests::entry(2, actorId: 'other', subjectType: 'user', subjectId: 'erase-me'));
        $driver->write(DriverContractTests::entry(3, actorId: 'keep-me', subjectId: 'keep-me'));
        $ingestionDeadline = microtime(true) + 15;
        do {
            $ingested = $driver->search((new ActivityQuery())->last('1 day'))->entries;
            if (3 === count($ingested)) {
                break;
            }
            usleep(250_000);
        } while (microtime(true) < $ingestionDeadline);
        expect($ingested)->toHaveCount(3);

        $result = $driver->forget('user', 'erase-me');
        expect($result->state)->toBe('accepted_pending');
        $ids = array_merge(...array_column($result->outcomes, 'request_ids'));
        expect($ids)->toHaveCount(2);
        $deadline = microtime(true) + 300;
        $processed = [];
        do {
            $requests = Http::timeout(5)->get(lokiTestEndpoint().'/loki/api/v1/delete')->json();
            $processed = array_values(array_filter($requests, static fn (array $request): bool => in_array($request['request_id'], $ids, true) && 'processed' === $request['status']));
            if (2 === count($processed)) {
                break;
            }
            usleep(250_000);
        } while (microtime(true) < $deadline);
        expect($processed)->toHaveCount(2);

        do {
            $entries = $driver->search((new ActivityQuery())->last('1 day'))->entries;
            if ([DriverContractTests::id(3)] === array_map(static fn (ActivityEntry $entry): string => $entry->id, $entries)) {
                break;
            }
            usleep(250_000);
        } while (microtime(true) < $deadline);
        expect(array_map(static fn (ActivityEntry $entry): string => $entry->id, $entries))->toBe([DriverContractTests::id(3)]);
    });

    it('paginates over same-timestamp entries without gaps or duplicates', function (): void {
        $driver = lokiDriverFor('pagination');
        $timestamp = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $entries = [];

        for ($i = 1; $i <= 25; $i++) {
            $entry = DriverContractTests::entry($i);
            $entries[] = new ActivityEntry(
                id: DriverContractTests::id(10_000 + $i),
                schemaVersion: $entry->schemaVersion,
                loggedAt: $timestamp,
                channel: $entry->channel,
                action: $entry->action,
                severity: $entry->severity,
                title: $entry->title,
                summary: $entry->summary,
                actor: $entry->actor,
                subject: $entry->subject,
                trace: $entry->trace,
                tags: $entry->tags,
                request: $entry->request,
                payload: $entry->payload,
                secrets: $entry->secrets,
                payloadBytes: $entry->payloadBytes,
                environment: $entry->environment,
                hostname: $entry->hostname,
            );
        }

        $driver->writeBatch($entries);
        usleep(600_000);

        $seen = [];
        $cursor = null;
        $pages = 0;

        do {
            $query = (new ActivityQuery())
                ->between($timestamp->modify('-5 minutes'), $timestamp->modify('+5 minutes'))
                ->limit(7)
                ->orderByTime(SortOrder::Descending);

            if ($cursor instanceof Cursor) {
                $query->cursor($cursor);
            }

            $result = $driver->search($query);
            $pages++;

            foreach ($result->entries as $entry) {
                $seen[$entry->id] = ($seen[$entry->id] ?? 0) + 1;
            }

            $cursor = $result->nextCursor;
        } while ($cursor instanceof Cursor && $pages < 20);

        expect($seen)->toHaveCount(25);
        foreach ($seen as $hits) {
            expect($hits)->toBe(1);
        }
        expect($pages)->toBeGreaterThanOrEqual(4);
    })->skip(null === lokiTestEndpoint(), 'EAGLE_EYE_LOKI_TEST_URL not set');

    it('paginates collector ingestion timestamps without gaps duplicates or noise', function (): void {
        $formatter = new LokiLineFormatter([]);
        $ingestionBase = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $sequence = 0;
        $driver = realCollectorLokiDriver(
            'skew-pagination',
            static function (string $sourceNanoseconds, int $index) use ($formatter, $ingestionBase, &$sequence): string {
                if ('' === $sourceNanoseconds || $index < 0) {
                    throw new LogicException('Invalid collector timestamp input.');
                }

                return $formatter->nanoseconds($ingestionBase->modify(sprintf('+%d milliseconds', $sequence++)));
            },
        );
        $loggedAt = $ingestionBase->modify('-30 minutes');
        $entries = [
            lokiEntryAt(71, $loggedAt->modify('+7 seconds')->format('Y-m-d H:i:s.u')),
            lokiEntryAt(72, $loggedAt->modify('+1 second')->format('Y-m-d H:i:s.u')),
            lokiEntryAt(73, $loggedAt->modify('+6 seconds')->format('Y-m-d H:i:s.u')),
            lokiEntryAt(74, $loggedAt->modify('+2 seconds')->format('Y-m-d H:i:s.u')),
            lokiEntryAt(75, $loggedAt->modify('+5 seconds')->format('Y-m-d H:i:s.u')),
            lokiEntryAt(76, $loggedAt->modify('+3 seconds')->format('Y-m-d H:i:s.u')),
            lokiEntryAt(77, $loggedAt->modify('+4 seconds')->format('Y-m-d H:i:s.u')),
        ];

        foreach ($entries as $entry) {
            $driver->write($entry);
        }

        $ids = [];
        $cursor = null;

        do {
            $query = (new ActivityQuery())
                ->between($ingestionBase->modify('-1 minute'), $ingestionBase->modify('+1 minute'))
                ->orderByTime(SortOrder::Descending)
                ->limit(2)
                ->cursor($cursor);
            $result = $driver->search($query);
            array_push($ids, ...array_map(static fn (ActivityEntry $entry): string => $entry->id, $result->entries));
            $cursor = $result->nextCursor;
        } while ($cursor instanceof Cursor);

        expect($ids)->toBe(array_reverse(array_map(static fn (ActivityEntry $entry): string => $entry->id, $entries)))
            ->and($ids)->toHaveCount(count(array_unique($ids)));
    })->skip('collector' !== lokiTestMode(), 'EAGLE_EYE_LOKI_MODE is not collector');

    it('filters collector lines by numeric status', function (): void {
        $driver = realCollectorLokiDriver('status-filter');
        $ok = DriverContractTests::entry(81);
        $failedStorage = DriverContractTests::entry(82, channel: ActivityChannel::Http)->toStorage();
        $failedStorage['status_code'] = 503;
        $failed = ActivityEntry::fromStorage($failedStorage);

        $driver->writeBatch([$ok, $failed]);

        expect(array_map(
            static fn (ActivityEntry $entry): string => $entry->id,
            $driver->search((new ActivityQuery())->whereStatus('>=', 500))->entries,
        ))->toBe([$failed->id])
            ->and(array_map(
                static fn (ActivityEntry $entry): string => $entry->id,
                $driver->search((new ActivityQuery())->channels(ActivityChannel::Http))->entries,
            ))->toBe([$failed->id]);
    })->skip('collector' !== lokiTestMode(), 'EAGLE_EYE_LOKI_MODE is not collector');

    it('matches named-timezone half-open days across DST transitions', function (
        string $day,
        string $before,
        string $first,
        string $last,
        string $nextMidnight,
        int $expectedHours,
    ): void {
        $driver = lokiDriverFor('dst-boundary');
        $entries = [
            lokiEntryAt(51, $before),
            lokiEntryAt(52, $first),
            lokiEntryAt(53, $last),
            lokiEntryAt(54, $nextMidnight),
        ];
        $driver->writeBatch($entries);
        $timezone = new DateTimeZone('Europe/Berlin');
        $from = new DateTimeImmutable($day, $timezone);
        $to = $from->modify('+1 day');
        $deadline = microtime(true) + 15;
        $ids = [];

        do {
            $result = $driver->search((new ActivityQuery())->between($from, $to)->limit(10));
            $ids = array_map(static fn (ActivityEntry $entry): string => $entry->id, $result->entries);

            if ([$entries[2]->id, $entries[1]->id] === $ids) {
                break;
            }

            usleep(250_000);
        } while (microtime(true) < $deadline);

        expect($ids)->toBe([$entries[2]->id, $entries[1]->id])
            ->and($to->getTimestamp() - $from->getTimestamp())->toBe($expectedHours * 3600);
    })->with([
        'spring forward day' => [
            '2025-03-30',
            '2025-03-29T22:59:59.999999Z',
            '2025-03-29T23:00:00.000000Z',
            '2025-03-30T21:59:59.999999Z',
            '2025-03-30T22:00:00.000000Z',
            23,
        ],
        'fall back day' => [
            '2025-10-26',
            '2025-10-25T21:59:59.999999Z',
            '2025-10-25T22:00:00.000000Z',
            '2025-10-26T22:59:59.999999Z',
            '2025-10-26T23:00:00.000000Z',
            25,
        ],
    ])->skip(null === lokiTestEndpoint(), 'EAGLE_EYE_LOKI_TEST_URL not set');

    it('aggregates with real logql metric queries', function (): void {
        $driver = lokiDriverFor('metrics');
        $driver->writeBatch([
            DriverContractTests::entry(1),
            DriverContractTests::entry(2, channel: ActivityChannel::Http),
            DriverContractTests::entry(3, channel: ActivityChannel::Queue),
        ]);
        usleep(600_000);

        $query = (new ActivityQuery())->last('1 day');
        $result = $driver->aggregate($query, 'channel');

        expect($result['custom'] ?? 0)->toBe(1)
            ->and($result['http'] ?? 0)->toBe(1)
            ->and($result['queue'] ?? 0)->toBe(1);

        expect($driver->count($query))->toBe(3);
    })->skip(null === lokiTestEndpoint(), 'EAGLE_EYE_LOKI_TEST_URL not set');

    it('aggregates stable actor identities in both Loki query modes', function (): void {
        $driver = lokiDriverFor('actor-aggregate');
        $driver->writeBatch([
            DriverContractTests::entry(31, actorId: 'same:label:a'),
            DriverContractTests::entry(32, actorId: 'same:label:a'),
            DriverContractTests::entry(33, actorId: 'same:label:b'),
        ]);
        usleep(600_000);

        expect($driver->aggregate((new ActivityQuery())->last('1 day'), 'actor'))->toBe([
            '["user","same:label:a"]' => 2,
            '["user","same:label:b"]' => 1,
        ]);
    })->skip(null === lokiTestEndpoint(), 'EAGLE_EYE_LOKI_TEST_URL not set');

    it('returns an exact real backend ranking beyond one hundred actors', function (): void {
        $driver = lokiDriverFor('actor-cutoff');
        $entries = [];

        for ($actor = 0; $actor < 101; $actor++) {
            for ($event = 0; $event < 3; $event++) {
                $entries[] = DriverContractTests::entry(
                    1 + ($actor * 3) + $event,
                    actorId: sprintf('actor-%03d', $actor),
                );
            }
        }

        foreach (array_chunk($entries, 100) as $batch) {
            expect($driver->writeBatch($batch))->toBe([]);
        }

        usleep(800_000);

        $ranking = $driver->aggregateTop((new ActivityQuery())->last('1 day'), 'actor', 5);

        expect($ranking)->toHaveCount(5)
            ->and(array_values($ranking))->toBe([3, 3, 3, 3, 3]);
    })->skip(null === lokiTestEndpoint(), 'EAGLE_EYE_LOKI_TEST_URL not set');

    it('reports capabilities and honest approximate counts', function (): void {
        $driver = lokiDriverFor('capabilities');

        expect($driver->supports(Capability::FullTextSearch))->toBeTrue()
            ->and($driver->supports(Capability::ExactCount))->toBeFalse()
            ->and($driver->supports(Capability::RankedAggregation))->toBeTrue()
            ->and($driver->supports(Capability::UnboundedTimeRange))->toBeFalse()
            ->and($driver->supports(Capability::LiveTail))->toBeFalse()
            ->and($driver->stats())->toHaveKeys(['entries', 'bytes', 'oldest']);
    })->skip(null === lokiTestEndpoint(), 'EAGLE_EYE_LOKI_TEST_URL not set');

    it('serves the identical api envelope shape as every other driver', function (): void {
        $driver = lokiDriverFor('envelope');
        $driver->write(DriverContractTests::entry(1));
        usleep(400_000);

        $result = $driver->search(new ActivityQuery());
        $entry = $result->entries[0] ?? null;

        expect($entry)->not->toBeNull();

        if (! $entry instanceof ActivityEntry) {
            return;
        }

        $array = (new ActivityEntryResource($entry))
            ->toArray(Request::create('/', 'GET'));

        expect(array_keys($array))->toBe([
            'id', 'schema_version', 'logged_at', 'channel', 'action', 'severity',
            'title', 'summary', 'actor', 'subject', 'trace', 'tags', 'request',
            'changes', 'payload', 'sealed', 'payload_bytes', 'environment',
            'hostname', 'memory_kb', 'integrity_hash', 'created_at',
        ]);
    })->skip(null === lokiTestEndpoint(), 'EAGLE_EYE_LOKI_TEST_URL not set');
} else {
    it('requires a real loki endpoint for the contract suite')->skip();
}
