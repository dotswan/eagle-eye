<?php

declare(strict_types=1);

use Dotswan\EagleEye\Contracts\Driver\FullActivityLogDriverInterface;
use Dotswan\EagleEye\Contracts\Driver\StoresFullActivityBodiesInterface;
use Illuminate\Database\QueryException;
use Dotswan\EagleEye\Data\Query\Cursor;
use Dotswan\EagleEye\Data\Query\QueryResult;
use Dotswan\EagleEye\Data\ActivityEntry;
use Dotswan\EagleEye\Data\WriteFailure;
use Dotswan\EagleEye\Data\Query\ActivityQuery;
use Dotswan\EagleEye\Drivers\ActivityLogSchema;
use Dotswan\EagleEye\Drivers\DatabaseDriver;
use Dotswan\EagleEye\Drivers\IndexDriver;
use Dotswan\EagleEye\Drivers\MultiDriver;
use Dotswan\EagleEye\Drivers\MemoryDriver;
use Dotswan\EagleEye\Enums\ActivityChannel;
use Dotswan\EagleEye\Enums\Capability;
use Dotswan\EagleEye\Enums\SortOrder;
use Dotswan\EagleEye\Testing\DriverContractTests;

function indexDriver(): IndexDriver
{
    $schema = app('db')->connection()->getSchemaBuilder();
    $schema->dropIfExists('activity_log_index');
    ActivityLogSchema::createIndex($schema);

    return new IndexDriver(app('db')->connection());
}

function multiDriver(?FullActivityLogDriverInterface $primary = null): MultiDriver
{
    $schema = app('db')->connection()->getSchemaBuilder();
    $schema->dropIfExists('activity_logs');
    ActivityLogSchema::create($schema, 'activity_logs');
    $schema->dropIfExists('activity_log_index');
    ActivityLogSchema::createIndex($schema);

    $connection = app('db')->connection();

    return new MultiDriver(
        $primary ?? new DatabaseDriver($connection),
        new IndexDriver($connection),
    );
}

it('projects headers without payload and answers unbounded subject queries', function (): void {
    $driver = indexDriver();
    $driver->writeBatch([
        DriverContractTests::entry(1, subjectType: 'invoice', subjectId: '1042', title: 'Invoice updated'),
        DriverContractTests::entry(2, subjectType: 'invoice', subjectId: '2048', title: 'Other invoice'),
    ]);

    $result = $driver->search((new ActivityQuery())->forSubject('invoice', '1042'));

    expect($result->entries)->toHaveCount(1)
        ->and($result->entries[0]->title)->toBe('Invoice updated')
        ->and($result->entries[0]->payload)->toBe([])
        ->and($result->entries[0]->subject?->className)->toBe(DriverContractTests::class)
        ->and($result->count)->toBe(1)
        ->and($result->exactCount)->toBeTrue();

    expect($driver->count(new ActivityQuery()))->toBe(2);
});

it('supports exact counts, aggregation and tag scans on headers', function (): void {
    $driver = indexDriver();
    $driver->writeBatch([
        DriverContractTests::entry(1, tags: ['gdpr', 'campaign:1'], channel: ActivityChannel::Model),
        DriverContractTests::entry(2, tags: ['campaign:2'], channel: ActivityChannel::Http),
    ]);

    expect($driver->aggregate(new ActivityQuery(), 'channel'))->toBe(['http' => 1, 'model' => 1])
        ->and($driver->tags('campaign'))->toBe(['campaign:1', 'campaign:2'])
        ->and($driver->search((new ActivityQuery())->withAnyTag(['gdpr']))->entries)->toHaveCount(1);
});

it('aggregates complete stable actor identities', function (): void {
    $driver = indexDriver();
    $driver->writeBatch([
        DriverContractTests::entry(21, actorId: 'a:1'),
        DriverContractTests::entry(22, actorId: 'a:1'),
        DriverContractTests::entry(23, actorId: 'b:2'),
    ]);

    expect($driver->aggregate(new ActivityQuery(), 'actor'))->toBe([
        '["user","a:1"]' => 2,
        '["user","b:2"]' => 1,
    ]);
});

it('assembles traces and prunes synchronously', function (): void {
    $driver = indexDriver();
    $traceId = str_repeat('b', 32);
    $driver->writeBatch([
        DriverContractTests::entry(1, traceId: $traceId),
        DriverContractTests::entry(2, traceId: $traceId),
        DriverContractTests::entry(3),
    ]);

    $trace = $driver->search((new ActivityQuery())->forTrace($traceId)->orderByTime(SortOrder::Ascending)->limit(15))->entries;

    expect(array_map(static fn (ActivityEntry $entry): string => $entry->id, $trace))
        ->toBe([DriverContractTests::id(1), DriverContractTests::id(2)]);

    expect($driver->forget('campaign', '42')->removed)->toBe(3)
        ->and($driver->stats()['entries'])->toBe(0);
});

it('prunes sql rows in bounded id batches', function (): void {
    $driver = indexDriver();
    $entries = [];

    for ($sequence = 1; $sequence <= 1001; $sequence++) {
        $entries[] = DriverContractTests::entry($sequence);
    }

    foreach (array_chunk($entries, 100) as $batch) {
        $driver->writeBatch($batch);
    }
    $deletes = [];
    app('db')->connection()->listen(static function ($query) use (&$deletes): void {
        if (str_starts_with(mb_strtolower($query->sql), 'delete')) {
            $deletes[] = $query;
        }
    });

    $deleted = $driver->prune(new DateTimeImmutable('+1 day'));
    $maximumBindings = max([0, ...array_map(static fn ($query): int => count($query->bindings), $deletes)]);

    expect($deleted)->toBe(1001)
        ->and($deletes)->toHaveCount(2)
        ->and($maximumBindings)->toBeLessThanOrEqual(1000);
});

it('declares honest header-only capabilities', function (): void {
    $driver = indexDriver();

    expect($driver->supports(Capability::ExactCount))->toBeTrue()
        ->and($driver->supports(Capability::RankedAggregation))->toBeTrue()
        ->and($driver->supports(Capability::UnboundedTimeRange))->toBeTrue()
        ->and($driver->supports(Capability::FullTextSearch))->toBeFalse()
        ->and($driver->supports(Capability::JsonFieldQuery))->toBeFalse();
});

DriverContractTests::define('multi', static fn (): MultiDriver => multiDriver());

it('fans writes out to both children', function (): void {
    $multi = multiDriver();
    $entry = DriverContractTests::entry(1, title: 'Fan out probe');

    $multi->write($entry);

    $primaryRow = app('db')->connection()->table('activity_logs')->where('id', $entry->id)->first();
    $indexRow = app('db')->connection()->table('activity_log_index')->where('id', $entry->id)->first();

    expect($primaryRow)->not->toBeNull()
        ->and($indexRow)->not->toBeNull();
});

it('tolerates accelerator failure with the ignore policy', function (): void {
    $schema = app('db')->connection()->getSchemaBuilder();
    $schema->dropIfExists('activity_logs');
    ActivityLogSchema::create($schema, 'activity_logs');

    $connection = app('db')->connection();
    $multi = new MultiDriver(
        new DatabaseDriver($connection),
        new IndexDriver($connection), // table intentionally missing
        acceleratorOnFailure: 'ignore',
    );

    $multi->write(DriverContractTests::entry(1));

    expect($connection->table('activity_logs')->count())->toBe(1);
});

it('throws when the primary fails with the throw policy', function (): void {
    $connection = app('db')->connection();
    $schema = $connection->getSchemaBuilder();
    $schema->dropIfExists('activity_logs');
    $schema->dropIfExists('activity_log_index');
    ActivityLogSchema::createIndex($schema);

    $multi = new MultiDriver(
        new DatabaseDriver($connection), // activity_logs table intentionally missing
        new IndexDriver($connection),
        primaryOnFailure: 'throw',
    );

    $multi->write(DriverContractTests::entry(1));
})->throws(QueryException::class);

it('still lists entries from the index when the primary is hard-down', function (): void {
    $connection = app('db')->connection();
    $schema = $connection->getSchemaBuilder();
    $schema->dropIfExists('activity_logs');
    $schema->dropIfExists('activity_log_index');
    ActivityLogSchema::createIndex($schema);

    $index = new IndexDriver($connection);
    $index->writeBatch([
        DriverContractTests::entry(1, title: 'Survives outage'),
        DriverContractTests::entry(2, title: 'Also survives'),
    ]);

    $multi = new MultiDriver(
        new DatabaseDriver($connection), // primary table missing = hard down
        $index,
        primaryOnFailure: 'ignore',
        acceleratorOnFailure: 'ignore',
    );

    $result = $multi->search(new ActivityQuery());

    expect($result->entries)->toHaveCount(2)
        ->and($result->entries[0]->payload)->toBe([])
        ->and($multi->count(new ActivityQuery()))->toBe(2);
});

it('finds bodies on the primary but degrades to headers', function (): void {
    $multi = multiDriver();
    $entry = DriverContractTests::entry(1, title: 'Body lives on primary');

    $multi->write($entry);

    $found = $multi->find($entry->id);

    expect($found)->not->toBeNull()
        ->and($found?->payload)->toBe($entry->payload)
        ->and($found?->title)->toBe('Body lives on primary');
});

it('returns a marked header when the primary fails during the narrowed body lookup', function (): void {
    $primary = Mockery::mock(FullActivityLogDriverInterface::class);
    $accelerator = Mockery::mock(FullActivityLogDriverInterface::class);

    if (! $primary instanceof FullActivityLogDriverInterface || ! $accelerator instanceof FullActivityLogDriverInterface) {
        throw new LogicException('Full activity driver mock unavailable.');
    }
    $header = DriverContractTests::entry(41, title: 'Header survives');

    $primary->shouldReceive('find')->once()->andThrow(new RuntimeException('primary down'));
    $primary->shouldReceive('search')->once()->andThrow(new RuntimeException('still down'));
    $accelerator->shouldReceive('find')->once()->with($header->id)->andReturn($header);

    $found = (new MultiDriver($primary, $accelerator, primaryOnFailure: 'ignore'))->find($header->id);

    expect($found)->not->toBeNull()
        ->and($found?->id)->toBe($header->id)
        ->and($found?->payload['__eagle_eye']['body_available'])->toBeFalse();
});

it('marks a stale header when its retained body is missing', function (): void {
    $primary = Mockery::mock(FullActivityLogDriverInterface::class);
    $accelerator = Mockery::mock(FullActivityLogDriverInterface::class);

    if (! $primary instanceof FullActivityLogDriverInterface || ! $accelerator instanceof FullActivityLogDriverInterface) {
        throw new LogicException('Full activity driver mock unavailable.');
    }
    $header = DriverContractTests::entry(50, title: 'Stale header');

    $primary->shouldReceive('find')->once()->with($header->id)->andReturnNull();
    $primary->shouldReceive('search')->once()->andReturn(new QueryResult([]));
    $accelerator->shouldReceive('find')->once()->with($header->id)->andReturn($header);

    $found = (new MultiDriver($primary, $accelerator))->find($header->id);

    expect($found?->id)->toBe($header->id)
        ->and($found?->payload['__eagle_eye']['body_available'])->toBeFalse();
});

it('does not query for a body when the accelerator has no header', function (): void {
    $primary = Mockery::mock(FullActivityLogDriverInterface::class);
    $accelerator = Mockery::mock(FullActivityLogDriverInterface::class);

    if (! $primary instanceof FullActivityLogDriverInterface || ! $accelerator instanceof FullActivityLogDriverInterface) {
        throw new LogicException('Full activity driver mock unavailable.');
    }

    $primary->shouldReceive('find')->once()->with('missing')->andReturnNull();
    $primary->shouldNotReceive('search');
    $accelerator->shouldReceive('find')->once()->with('missing')->andReturnNull();

    expect((new MultiDriver($primary, $accelerator))->find('missing'))->toBeNull();
});

it('returns the recovered body after the primary find health changes', function (): void {
    $primary = Mockery::mock(FullActivityLogDriverInterface::class);
    $accelerator = Mockery::mock(FullActivityLogDriverInterface::class);

    if (! $primary instanceof FullActivityLogDriverInterface || ! $accelerator instanceof FullActivityLogDriverInterface) {
        throw new LogicException('Full activity driver mock unavailable.');
    }
    $header = DriverContractTests::entry(45, title: 'Header');
    $body = DriverContractTests::entry(45, title: 'Recovered body');
    $collisionStorage = DriverContractTests::entry(46, title: 'References '.$header->id)->toStorage();
    $collisionStorage['logged_at'] = $header->loggedAt->format(DATE_ATOM);
    $collision = ActivityEntry::fromStorage($collisionStorage);

    $primary->shouldReceive('find')->once()->with($header->id)->andThrow(new RuntimeException('transient outage'));
    $primary->shouldReceive('search')->once()->andReturn(new QueryResult([$collision, $body]));
    $accelerator->shouldReceive('find')->once()->with($header->id)->andReturn($header);

    $found = (new MultiDriver($primary, $accelerator))->find($header->id);

    expect($found?->id)->toBe($body->id)
        ->and($found?->title)->toBe('Recovered body')
        ->and($found?->payload)->toBe($body->payload);
});

it('caps delayed body recovery to one hour for old entries', function (): void {
    $primary = Mockery::mock(FullActivityLogDriverInterface::class);
    $accelerator = Mockery::mock(FullActivityLogDriverInterface::class);

    if (! $primary instanceof FullActivityLogDriverInterface || ! $accelerator instanceof FullActivityLogDriverInterface) {
        throw new LogicException('Full activity driver mock unavailable.');
    }

    $headerStorage = DriverContractTests::entry(47, title: 'Queued header')->toStorage();
    $headerStorage['logged_at'] = (new DateTimeImmutable('-60 days'))->format('Y-m-d H:i:s.u');
    $headerStorage['created_at'] = $headerStorage['logged_at'];
    $header = ActivityEntry::fromStorage($headerStorage);
    $body = DriverContractTests::entry(47, title: 'Collected later');

    $primary->shouldReceive('find')->once()->with($header->id)->andReturnNull();
    $primary->shouldReceive('search')->once()->with(Mockery::on(fn (ActivityQuery $query): bool => $query->from?->format('U.u') === $header->loggedAt->modify('-1 second')->format('U.u')
            && $query->to instanceof DateTimeImmutable
            && $query->to->format('U.u') === $header->loggedAt->modify('+1 hour')->format('U.u')))->andReturn(new QueryResult([$body]));
    $accelerator->shouldReceive('find')->once()->with($header->id)->andReturn($header);

    $found = (new MultiDriver($primary, $accelerator))->find($header->id);

    expect($found?->id)->toBe($body->id)
        ->and($found?->title)->toBe('Collected later');
});

it('never returns a text collision for an exact detail lookup', function (): void {
    $schema = app('db')->connection()->getSchemaBuilder();
    $schema->dropIfExists('activity_logs');
    ActivityLogSchema::create($schema, 'activity_logs');
    $primary = new DatabaseDriver(app('db')->connection());
    $accelerator = new MemoryDriver();
    $requested = DriverContractTests::entry(51, title: 'Requested activity');
    $collisionStorage = DriverContractTests::entry(52, title: 'References '.$requested->id)->toStorage();
    $collisionStorage['logged_at'] = $requested->loggedAt->format(DATE_ATOM);
    $collisionStorage['created_at'] = $collisionStorage['logged_at'];
    $collision = ActivityEntry::fromStorage($collisionStorage);
    $primary->write($collision);
    $accelerator->write($requested);

    $found = (new MultiDriver($primary, $accelerator))->find($requested->id);

    expect($found?->id)->toBe($requested->id)
        ->and($found?->payload['__eagle_eye']['body_available'])->toBeFalse();
});

it('reports primary batch rejections when no full-body fallback accepted them', function (): void {
    $entry = DriverContractTests::entry(42);
    $failure = new WriteFailure($entry->id, 'rejected');
    $primary = Mockery::mock(FullActivityLogDriverInterface::class);

    if (! $primary instanceof FullActivityLogDriverInterface) {
        throw new LogicException('Full activity driver mock unavailable.');
    }

    $primary->shouldReceive('writeBatch')->once()->andReturn([$failure]);

    $failures = (new MultiDriver($primary, null, primaryOnFailure: 'ignore'))->writeBatch([$entry]);

    expect($failures)->toHaveCount(1)
        ->and($failures[0]->entryId)->toBe($entry->id);
});

it('reports thrown primary batch failures when a fallback is absent', function (): void {
    $entries = [DriverContractTests::entry(46), DriverContractTests::entry(47)];
    $primary = Mockery::mock(FullActivityLogDriverInterface::class);

    if (! $primary instanceof FullActivityLogDriverInterface) {
        throw new LogicException('Full activity driver mock unavailable.');
    }
    $primary->shouldReceive('writeBatch')->once()->andThrow(new RuntimeException('primary offline'));

    $failures = (new MultiDriver($primary, null, primaryOnFailure: 'fallback'))->writeBatch($entries);

    expect(array_map(static fn (WriteFailure $failure): string => $failure->entryId, $failures))->toBe([
        $entries[0]->id,
        $entries[1]->id,
    ]);
});

it('recovers thrown primary batch failures through a full-body fallback', function (): void {
    $entries = [DriverContractTests::entry(48), DriverContractTests::entry(49)];
    $primary = Mockery::mock(FullActivityLogDriverInterface::class);

    if (! $primary instanceof FullActivityLogDriverInterface) {
        throw new LogicException('Full activity driver mock unavailable.');
    }
    $primary->shouldReceive('writeBatch')->once()->andThrow(new RuntimeException('primary offline'));
    $fallback = new MemoryDriver();

    $failures = (new MultiDriver($primary, $fallback, primaryOnFailure: 'fallback'))->writeBatch($entries);

    expect($failures)->toBe([])
        ->and($fallback->find($entries[0]->id)?->payload)->toBe($entries[0]->payload)
        ->and($fallback->find($entries[1]->id)?->payload)->toBe($entries[1]->payload);
});

it('acknowledges a primary rejection only after a full-body fallback accepts it', function (): void {
    $entry = DriverContractTests::entry(43);
    $primary = Mockery::mock(FullActivityLogDriverInterface::class);

    if (! $primary instanceof FullActivityLogDriverInterface) {
        throw new LogicException('Full activity driver mock unavailable.');
    }
    $primary->shouldReceive('writeBatch')->once()->andReturn([
        new WriteFailure($entry->id, 'rejected'),
    ]);
    $fallback = new MemoryDriver();

    $failures = (new MultiDriver($primary, $fallback, primaryOnFailure: 'fallback'))->writeBatch([$entry]);

    expect($failures)->toBe([])
        ->and($fallback->find($entry->id)?->payload)->toBe($entry->payload);
});

it('retains only failures rejected by both primary and full-body fallback', function (): void {
    $accepted = DriverContractTests::entry(53);
    $rejected = DriverContractTests::entry(54);
    $primary = Mockery::mock(FullActivityLogDriverInterface::class);
    $fallback = Mockery::mock(FullActivityLogDriverInterface::class, StoresFullActivityBodiesInterface::class);

    if (! $primary instanceof FullActivityLogDriverInterface || ! $fallback instanceof FullActivityLogDriverInterface) {
        throw new LogicException('Full activity driver mock unavailable.');
    }

    $primary->shouldReceive('writeBatch')->once()->andReturn([
        new WriteFailure($accepted->id, 'primary rejected'),
        new WriteFailure($rejected->id, 'primary rejected'),
    ]);
    $fallback->shouldReceive('writeBatch')->once()->with([$accepted, $rejected])->andReturn([
        new WriteFailure($rejected->id, 'fallback rejected'),
    ]);

    $failures = (new MultiDriver($primary, $fallback, primaryOnFailure: 'fallback'))->writeBatch([$accepted, $rejected]);

    expect($failures)->toHaveCount(1)
        ->and($failures[0]->entryId)->toBe($rejected->id)
        ->and($failures[0]->message)->toBe('primary rejected');
});

it('does not treat a header-only index as a successful body fallback', function (): void {
    $entry = DriverContractTests::entry(44);
    $primary = Mockery::mock(FullActivityLogDriverInterface::class);

    if (! $primary instanceof FullActivityLogDriverInterface) {
        throw new LogicException('Full activity driver mock unavailable.');
    }

    $primary->shouldReceive('writeBatch')->once()->andReturn([new WriteFailure($entry->id, 'rejected')]);
    $failures = (new MultiDriver($primary, indexDriver(), primaryOnFailure: 'fallback'))->writeBatch([$entry]);

    expect($failures)->toHaveCount(1)
        ->and($failures[0]->entryId)->toBe($entry->id);
});

it('paginates the accelerator without gaps or duplicates', function (): void {
    $multi = multiDriver();
    $entries = [];

    for ($i = 1; $i <= 5; $i++) {
        $entries[] = DriverContractTests::entry($i);
    }

    $multi->writeBatch($entries);

    $first = $multi->search((new ActivityQuery())->orderByTime(SortOrder::Ascending)->limit(2));

    expect($first->entries)->toHaveCount(2)
        ->and($first->hasMore)->toBeTrue();

    $seen = [];

    foreach ([...$first->entries] as $entry) {
        $seen[$entry->id] = true;
    }

    $cursor = $first->nextCursor;
    $pages = 0;

    while ($cursor instanceof Cursor && $pages < 10) {
        $pages++;
        $page = $multi->search((new ActivityQuery())->orderByTime(SortOrder::Ascending)->limit(2)->cursor($cursor));

        foreach ($page->entries as $entry) {
            $seen[$entry->id] = ($seen[$entry->id] ?? 0) + 1;
        }

        $cursor = $page->nextCursor;
    }

    expect(array_keys($seen))->toHaveCount(5);
});

it('uses the same full body dataset for search count and aggregation', function (): void {
    $multi = multiDriver();
    $multi->writeBatch([
        DriverContractTests::entry(31, title: 'Visible header'),
        DriverContractTests::entry(32, title: 'Another header'),
    ]);
    $query = (new ActivityQuery())->search('Visible header');

    expect($multi->search($query)->entries)->toHaveCount(1)
        ->and($multi->count($query))->toBe(1)
        ->and(array_sum($multi->aggregate($query, 'channel')))->toBe(1);
});
