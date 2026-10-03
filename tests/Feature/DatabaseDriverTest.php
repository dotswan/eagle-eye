<?php

declare(strict_types=1);

use Dotswan\EagleEye\Transport\Flusher;
use Dotswan\EagleEye\Drivers\DriverManager;
use Dotswan\EagleEye\Data\ActivityEntry;
use Dotswan\EagleEye\Data\Query\ActivityQuery;
use Illuminate\Support\ServiceProvider;
use Illuminate\Database\Connection;
use Dotswan\EagleEye\EagleEyeServiceProvider;
use Dotswan\EagleEye\Contracts\Repository\ActivityRepositoryInterface;
use Dotswan\EagleEye\Drivers\ActivityLogSchema;
use Dotswan\EagleEye\Drivers\DatabaseDriver;
use Dotswan\EagleEye\Enums\Capability;
use Dotswan\EagleEye\Enums\SortOrder;
use Dotswan\EagleEye\Testing\DriverContractTests;

beforeEach(function (): void {
    $schema = app('db')->connection()->getSchemaBuilder();
    $schema->dropIfExists('activity_logs');
    ActivityLogSchema::create($schema, 'activity_logs');

    $manager = app(DriverManager::class);
    $manager->extend('database', fn (): DatabaseDriver => new DatabaseDriver(app('db')->connection()));
    $manager->forgetDrivers();

    config()->set('eagle-eye.default', 'database');
});

it('records searches and aggregates through the database driver', function (): void {
    $entry = eagleEye()->record('campaign.approved', ['campaign_id' => 42]);
    app(Flusher::class)->flush();

    expect($entry)->not->toBeNull();

    if (!$entry instanceof ActivityEntry) {
        return;
    }

    $repository = app(ActivityRepositoryInterface::class);
    $found = $repository->find($entry->id);

    expect($found)->not->toBeNull()
        ->and($found?->action)->toBe('campaign.approved')
        ->and($repository->search((new ActivityQuery())->search('approved'))->entries)->toHaveCount(1)
        ->and($repository->aggregate(new ActivityQuery(), 'channel'))->toHaveKey('custom');
});

it('encodes quoted tags as valid JSON query bindings', function (): void {
    $driver = new DatabaseDriver(app('db')->connection());
    $entry = DriverContractTests::entry(6101, tags: ['quoted"tag']);
    $driver->write($entry);

    expect($driver->search((new ActivityQuery())->withAnyTag(['quoted"tag']))->entries)
        ->toHaveCount(1)
        ->and($driver->search((new ActivityQuery())->withAnyTag(['missing"tag']))->entries)
        ->toBe([]);
});

it('filters by actor or subject type alone without an id', function (): void {
    $driver = new DatabaseDriver(app('db')->connection());
    $driver->write(DriverContractTests::entry(7101, actorId: 'actor-1'));
    $driver->write(DriverContractTests::entry(7102, actorId: 'actor-2'));

    $actorQuery = (new ActivityQuery())->forActor('user', 'actor-1');
    $actorQuery->actorId = null;

    $subjectQuery = (new ActivityQuery())->forSubject('campaign', '42');
    $subjectQuery->subjectId = null;

    expect($driver->search($actorQuery)->entries)->toHaveCount(2)
        ->and($driver->search($subjectQuery)->entries)->toHaveCount(2);
});

it('builds valid JSON tag bindings for mysql and postgres', function (string $dialect, string $expected): void {
    $connection = Mockery::mock(Connection::class);

    if (! $connection instanceof Connection) {
        throw new LogicException('Expected a database connection mock.');
    }

    $connection->shouldReceive('getDriverName')->once()->andReturn($dialect);
    $driver = new DatabaseDriver($connection);
    $method = new ReflectionMethod($driver, 'tagContains');

    expect($method->invoke($driver, 'quoted"tag')[1])->toBe($expected);
})->with([
    'mysql'    => ['mysql', '"quoted\\"tag"'],
    'postgres' => ['pgsql', '["quoted\\"tag"]'],
]);

it('reports capabilities honestly and stays healthy', function (): void {
    $driver = new DatabaseDriver(app('db')->connection());

    expect($driver->healthy())->toBeTrue()
        ->and($driver->supports(Capability::ExactCount))->toBeTrue()
        ->and($driver->supports(Capability::LiveTail))->toBeFalse()
        ->and($driver->name())->toBe('database');
});

it('installs idempotently and exposes stats', function (): void {
    $driver = new DatabaseDriver(app('db')->connection());

    $driver->install();
    $driver->install();

    expect(app('db')->connection()->getSchemaBuilder()->hasTable('activity_logs'))->toBeTrue();

    eagleEye()->record('stats.check');
    app(Flusher::class)->flush();

    $stats = $driver->stats();

    expect($stats['entries'])->toBe(1)
        ->and($stats['oldest'])->toBeInstanceOf(DateTimeImmutable::class);
});

it('publishes the migration under the eagle-eye-migrations tag', function (): void {
    $schema = app('db')->connection()->getSchemaBuilder();

    expect(ServiceProvider::pathsToPublish(
        EagleEyeServiceProvider::class,
        'eagle-eye-migrations',
    ))->not->toBeEmpty()
        ->and($schema->hasTable('activity_erasure_barriers'))->toBeTrue()
        ->and($schema->hasTable('activity_erasure_receipts'))->toBeTrue()
        ->and($schema->hasTable('activity_integrity_state'))->toBeTrue()
        ->and($schema->hasTable('activity_integrity_segments'))->toBeTrue()
        ->and($schema->hasTable('activity_integrity_entries'))->toBeTrue();
});

it('bounds large trace hydration in the sql query', function (): void {
    $driver = new DatabaseDriver(app('db')->connection());
    $traceId = str_repeat('f', 32);

    for ($offset = 0; $offset < 5001; $offset += 100) {
        $entries = [];

        for ($sequence = $offset + 1; $sequence <= min($offset + 100, 5001); $sequence++) {
            $entries[] = DriverContractTests::entry($sequence, traceId: $traceId);
        }

        $driver->writeBatch($entries);
    }

    $selects = [];
    app('db')->connection()->listen(static function ($query) use (&$selects): void {
        if (str_starts_with(mb_strtolower($query->sql), 'select')) {
            $selects[] = $query->sql;
        }
    });
    memory_reset_peak_usage();
    $baseline = memory_get_usage(true);

    $trace = $driver->search((new ActivityQuery())->forTrace($traceId)->orderByTime(SortOrder::Ascending)->limit(15))->entries;
    $boundedSelects = array_values(array_filter(
        $selects,
        static fn (string $sql): bool => str_contains(mb_strtolower($sql), 'limit 16'),
    ));

    expect($trace)->toHaveCount(15)
        ->and($selects)->toHaveCount(2)
        ->and($boundedSelects)->toHaveCount(1)
        ->and(memory_get_peak_usage(true) - $baseline)->toBeLessThan(8 * 1024 * 1024);
})->group('performance');

it('rejects oversized direct batches before persisting any id', function (): void {
    $driver = new DatabaseDriver(app('db')->connection());
    $accepted = DriverContractTests::entry(6001);
    $storage = DriverContractTests::entry(6002)->toStorage();
    $storage['payload'] = array_fill(0, 9, str_repeat('x', 8192));
    $storage['payload_bytes'] = mb_strlen(json_encode($storage['payload'], JSON_THROW_ON_ERROR), '8bit');
    $rejected = ActivityEntry::fromStorage($storage);
    memory_reset_peak_usage();
    $baseline = memory_get_usage(true);

    expect(fn (): array => $driver->writeBatch([$accepted, $rejected]))->toThrow(LengthException::class)
        ->and($driver->find($accepted->id))->toBeNull()
        ->and($driver->find($rejected->id))->toBeNull()
        ->and($driver->count(new ActivityQuery()))->toBe(0)
        ->and(memory_get_peak_usage(true) - $baseline)->toBeLessThan(4 * 1024 * 1024);
})->group('performance');

it('limits stable actor rankings in sql before hydration', function (): void {
    $driver = new DatabaseDriver(app('db')->connection());
    $entries = [];

    for ($actor = 0; $actor < 20; $actor++) {
        for ($event = 0; $event < 3; $event++) {
            $entries[] = DriverContractTests::entry(1 + $actor * 3 + $event, actorId: sprintf('actor-%02d', $actor));
        }
    }

    $driver->writeBatch($entries);
    $selects = [];
    app('db')->connection()->listen(static function ($query) use (&$selects): void {
        if (str_starts_with(mb_strtolower($query->sql), 'select')) {
            $selects[] = $query->sql;
        }
    });

    $groups = $driver->aggregateTop((new ActivityQuery())->last('1 day'), 'actor', 5);

    expect(array_keys($groups))->toBe([
        '["user","actor-00"]',
        '["user","actor-01"]',
        '["user","actor-02"]',
        '["user","actor-03"]',
        '["user","actor-04"]',
    ])->and(array_values($groups))->toBe([3, 3, 3, 3, 3])
        ->and($selects)->toHaveCount(1)
        ->and(mb_strtolower($selects[0]))->toContain('limit 5');
});

it('ranks more than one thousand sql actors with one bounded aggregate query', function (): void {
    $driver = new DatabaseDriver(app('db')->connection());

    foreach (array_chunk(range(1, 1_001), 100) as $actors) {
        $driver->writeBatch(array_map(
            static fn (int $actor): ActivityEntry => DriverContractTests::entry(
                $actor,
                actorId: sprintf('actor-%04d', $actor),
            ),
            $actors,
        ));
    }

    $selects = [];
    app('db')->connection()->listen(static function ($query) use (&$selects): void {
        if (str_starts_with(mb_strtolower($query->sql), 'select')) {
            $selects[] = mb_strtolower($query->sql);
        }
    });

    $groups = $driver->aggregateTop((new ActivityQuery())->last('1 day'), 'actor', 5);

    expect($groups)->toBe([
        '["user","actor-0001"]' => 1,
        '["user","actor-0002"]' => 1,
        '["user","actor-0003"]' => 1,
        '["user","actor-0004"]' => 1,
        '["user","actor-0005"]' => 1,
    ])->and($selects)->toHaveCount(1)
        ->and($selects[0])->toContain('group by')
        ->and($selects[0])->toContain('limit 5');
})->group('performance');

it('rejects ordinary aggregate cardinality beyond the finite group limit', function (): void {
    $driver = new DatabaseDriver(app('db')->connection());

    foreach (array_chunk(range(1, 1001), 100) as $sequences) {
        $driver->writeBatch(array_map(
            static fn (int $sequence): ActivityEntry => ActivityEntry::fromStorage([
                ...DriverContractTests::entry(7000 + $sequence)->toStorage(),
                'action' => 'action-'.$sequence,
            ]),
            $sequences,
        ));
    }

    $selects = [];
    app('db')->connection()->listen(static function ($query) use (&$selects): void {
        if (str_starts_with(mb_strtolower($query->sql), 'select')) {
            $selects[] = mb_strtolower($query->sql);
        }
    });

    expect(fn (): array => $driver->aggregate(new ActivityQuery(), 'action'))->toThrow(LengthException::class)
        ->and($selects)->toHaveCount(1)
        ->and($selects[0])->toContain('limit 1001');
});

it('bounds retained tag bytes and database scan calls', function (): void {
    $driver = new DatabaseDriver(app('db')->connection());

    foreach (array_chunk(range(1, 100), 25) as $sequences) {
        $driver->writeBatch(array_map(
            static fn (int $sequence): ActivityEntry => DriverContractTests::entry(
                9000 + $sequence,
                tags: array_map(
                    static fn (int $tag): string => sprintf('tag-%04d-', $sequence * 50 + $tag).str_repeat('t', 190),
                    range(1, 50),
                ),
            ),
            $sequences,
        ));
    }

    $selects = 0;
    app('db')->connection()->listen(static function ($query) use (&$selects): void {
        if (str_starts_with(mb_strtolower($query->sql), 'select')) {
            $selects++;
        }
    });
    $tags = $driver->tags(limit: 500);

    expect(array_sum(array_map(static fn (string $tag): int => mb_strlen($tag, '8bit'), $tags)))->toBeLessThanOrEqual(65_536)
        ->and($tags)->not->toBeEmpty()
        ->and($selects)->toBe(1);
});

it('advances across maximum admitted database rows at a fixed byte budget', function (): void {
    $driver = new DatabaseDriver(app('db')->connection());
    $entries = [];

    foreach (range(1, 17) as $sequence) {
        $storage = DriverContractTests::entry(100 + $sequence)->toStorage();
        $storage['payload'] = array_fill(0, 7, str_repeat('z', 8192));
        $storage['payload_bytes'] = mb_strlen(json_encode($storage['payload'], JSON_THROW_ON_ERROR), '8bit');
        $entries[] = ActivityEntry::fromStorage($storage);
    }

    $driver->writeBatch(array_slice($entries, 0, 10));
    $driver->writeBatch(array_slice($entries, 10));
    $selects = [];
    app('db')->connection()->listen(static function ($query) use (&$selects): void {
        if (str_starts_with(mb_strtolower($query->sql), 'select')) {
            $selects[] = mb_strtolower($query->sql);
        }
    });
    memory_reset_peak_usage();
    $baseline = memory_get_usage(true);
    $query = (new ActivityQuery())->orderByTime('asc')->limit(15);
    $first = $driver->search($query);
    $second = $driver->search($query->cursor($first->nextCursor));
    $found = array_merge($first->entries, $second->entries);
    expect(array_map(static fn (ActivityEntry $entry): string => $entry->id, $found))
        ->toBe(array_map(static fn (ActivityEntry $entry): string => $entry->id, $entries))
        ->and($first->hasMore)->toBeTrue()
        ->and($second->hasMore)->toBeFalse()
        ->and(array_map(static fn (ActivityEntry $entry): int => $entry->payloadBytes, $found))->each->toBeGreaterThan(57_000)
        ->and($selects)->toHaveCount(4)
        ->and(array_filter($selects, static fn (string $sql): bool => str_contains($sql, 'limit 16')))->toHaveCount(2)
        ->and(memory_get_peak_usage(true) - $baseline)->toBeLessThan(12 * 1024 * 1024);
})->group('performance');
