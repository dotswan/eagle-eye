<?php

declare(strict_types=1);

use Dotswan\EagleEye\Http\ApiRoutes;
use Illuminate\Routing\Router;
use Dotswan\EagleEye\Transport\Flusher;
use Dotswan\EagleEye\Data\Query\ActivityQuery;
use Dotswan\EagleEye\Data\Query\QueryResult;
use Dotswan\EagleEye\Facades\EagleEye;
use Dotswan\EagleEye\Contracts\Driver\ActivityLogDriverFactoryInterface;
use Dotswan\EagleEye\Contracts\Repository\ActivityRepositoryInterface;
use Dotswan\EagleEye\Contracts\Resolution\ActivityIdentityResolverInterface;
use Dotswan\EagleEye\Data\Actor;
use Dotswan\EagleEye\Data\Subject;

require_once __DIR__.'/helpers.php';

use Dotswan\EagleEye\Data\ActivityEntry;
use Dotswan\EagleEye\Drivers\ActivityLogSchema;
use Dotswan\EagleEye\Drivers\DatabaseDriver;
use Illuminate\Support\Facades\Gate;

function databaseDriver(): DatabaseDriver
{
    return new DatabaseDriver(app('db')->connection());
}

function dstApiEntry(int $sequence, string $timestamp): ActivityEntry
{
    $storage = apiEntry($sequence)->toStorage();
    $storage['logged_at'] = $timestamp;
    $storage['created_at'] = $timestamp;

    return ActivityEntry::fromStorage($storage);
}

beforeEach(function (): void {
    ApiRoutes::register(app(Router::class), 'api/v1', []);
    config()->set('eagle-eye.default', 'database');

    $schema = app('db')->connection()->getSchemaBuilder();
    $schema->dropIfExists('activity_logs');
    ActivityLogSchema::create($schema, 'activity_logs');

    Gate::define('viewActivityLog', static fn ($user = null): bool => true);
    Gate::define('revealActivityLog', static fn ($user = null, $entry = null, $path = null): bool => true);
});

it('lists entries in the frozen envelope shape', function (): void {
    databaseDriver()->writeBatch([apiEntry(1), apiEntry(2)]);

    [$status, $json] = apiRequest('GET', '/api/v1/activity-logs');

    expect($status)->toBe(200)
        ->and($json['data'])->toHaveCount(2)
        ->and($json['data'][0])->toHaveKeys([
            'id', 'schema_version', 'logged_at', 'channel', 'action', 'severity',
            'title', 'summary', 'actor', 'subject', 'trace', 'tags', 'request',
            'changes', 'payload', 'sealed', 'payload_bytes', 'environment',
            'hostname', 'memory_kb', 'integrity_hash', 'created_at',
        ])
        ->and($json['data'][0]['actor']['url'])->toBe('/users/8821')
        ->and($json['data'][0]['changes'][0])->toBe([
            'path'   => 'status',
            'label'  => 'Status',
            'before' => 'draft',
            'after'  => 'approved',
        ])
        ->and($json['meta']['driver'])->toBe('database')
        ->and($json['meta']['capabilities'])->toContain('exact_count')
        ->and($json['meta']['count'])->toBe(['value' => 2, 'exact' => true])
        ->and($json['links'])->toHaveKey('next');
});

it('batch resolves display identities once per listing', function (): void {
    $resolver = new class () implements ActivityIdentityResolverInterface {
        public int $actorCalls = 0;

        public int $subjectCalls = 0;

        public function resolveActors(array $actors): array
        {
            $this->actorCalls++;

            return array_map(
                static fn (Actor $actor): Actor => new Actor($actor->type, $actor->id, 'Resolved actor', '/resolved/actor'),
                $actors,
            );
        }

        public function resolveSubjects(array $subjects): array
        {
            $this->subjectCalls++;

            return array_map(
                static fn (Subject $subject): Subject => new Subject($subject->type, $subject->id, 'Resolved subject', $subject->className, '/resolved/subject'),
                $subjects,
            );
        }
    };
    app()->instance(ActivityIdentityResolverInterface::class, $resolver);
    databaseDriver()->writeBatch([apiEntry(1), apiEntry(2)]);

    [$status, $json] = apiRequest('GET', '/api/v1/activity-logs');

    expect($status)->toBe(200)
        ->and($resolver->actorCalls)->toBe(1)
        ->and($resolver->subjectCalls)->toBe(1)
        ->and($json['data'][0]['actor']['label'])->toBe('Resolved actor')
        ->and($json['data'][1]['subject']['label'])->toBe('Resolved subject');
});

it('filters listings by actor, subject, search and status', function (): void {
    databaseDriver()->writeBatch([apiEntry(1), apiEntry(2)]);

    [$status, $json] = apiRequest('GET', '/api/v1/activity-logs?actor_type=user&actor_id=8821');
    expect($status)->toBe(200)->and($json['data'])->toHaveCount(2);

    [, $json] = apiRequest('GET', '/api/v1/activity-logs?subject_type=order&subject_id=99999');
    expect($json['data'])->toHaveCount(0);

    [, $json] = apiRequest('GET', '/api/v1/activity-logs?search=orders');
    expect($json['data'])->toHaveCount(2);

    [, $json] = apiRequest('GET', '/api/v1/activity-logs?status=>=400');
    expect($json['data'])->toHaveCount(0);
});

it('paginates with an opaque next cursor', function (): void {
    databaseDriver()->writeBatch([apiEntry(1), apiEntry(2), apiEntry(3)]);

    [, $first] = apiRequest('GET', '/api/v1/activity-logs?per_page=2');
    expect($first['data'])->toHaveCount(2)
        ->and($first['links']['next'])->not->toBeNull();

    [, $second] = apiRequest('GET', '/api/v1/activity-logs?per_page=2&cursor='.$first['links']['next']);
    expect($second['data'])->toHaveCount(1);

    $ids = array_merge(
        array_column($first['data'], 'id'),
        array_column($second['data'], 'id'),
    );
    expect($ids)->toHaveCount(count(array_unique($ids)));
});

it('shows one entry with the full body', function (): void {
    databaseDriver()->write(apiEntry(1));
    $id = apiEntry(1)->id;

    [$status, $json] = apiRequest('GET', "/api/v1/activity-logs/{$id}");

    expect($status)->toBe(200)
        ->and($json['data']['id'])->toBe($id)
        ->and($json['data']['payload']['note'])->toBe('snapshot');

    [$status] = apiRequest('GET', '/api/v1/activity-logs/nope');
    expect($status)->toBe(404);
});

it('rejects a detail lookup that returns a different activity id', function (): void {
    $requested = apiEntry(1);
    $collision = apiEntry(2);
    $repository = Mockery::mock(ActivityRepositoryInterface::class);
    $repository->shouldReceive('find')->once()->with($requested->id)->andReturn($collision);
    app()->instance(ActivityRepositoryInterface::class, $repository);

    [$status] = apiRequest('GET', '/api/v1/activity-logs/'.$requested->id);

    expect($status)->toBe(404);
});

it('returns the nested causal tree for a trace', function (): void {
    $root = apiEntry(1, traceId: str_repeat('d', 32));
    $child = apiEntry(2, causationId: $root->id, traceId: str_repeat('d', 32));
    databaseDriver()->writeBatch([$child, $root]);

    [, $json] = apiRequest('GET', '/api/v1/activity-logs/traces/'.str_repeat('d', 32));

    expect($json['data'])->toHaveCount(1)
        ->and($json['data'][0]['entry']['id'])->toBe($root->id)
        ->and($json['data'][0]['children'][0]['entry']['id'])->toBe($child->id);
});

it('bounds trace reads and reports an incomplete result', function (): void {
    $traceId = str_repeat('e', 32);
    $entries = [];

    for ($sequence = 1; $sequence <= 501; $sequence++) {
        $storage = apiEntry(($sequence % 50) + 1, traceId: $traceId)->toStorage();
        $storage['id'] = sprintf('01J%021d', 10_000 + $sequence);
        $entries[] = ActivityEntry::fromStorage($storage);
    }

    $driver = databaseDriver();

    foreach (array_chunk($entries, 100) as $batch) {
        $driver->writeBatch($batch);
    }

    [$status, $json] = apiRequest('GET', '/api/v1/activity-logs/traces/'.$traceId);

    expect($status)->toBe(200)
        ->and($json['meta']['trace']['complete'])->toBeFalse()
        ->and($json['meta']['trace']['returned'])->toBe(15)
        ->and($json['meta']['trace']['next'])->toBeString();

    $ids = array_column(array_column($json['data'], 'entry'), 'id');

    while (is_string($json['meta']['trace']['next'])) {
        [, $json] = apiRequest('GET', '/api/v1/activity-logs/traces/'.$traceId.'?cursor='.rawurlencode($json['meta']['trace']['next']));
        $ids = array_merge($ids, array_column(array_column($json['data'], 'entry'), 'id'));
    }

    expect($ids)->toHaveCount(501)
        ->and($ids)->toHaveCount(count(array_unique($ids)))
        ->and($json['meta']['trace']['complete'])->toBeTrue();
});

it('aggregates stats and lists tags', function (): void {
    databaseDriver()->writeBatch([apiEntry(1), apiEntry(2)]);

    [, $json] = apiRequest('GET', '/api/v1/activity-logs/stats?group_by=channel&interval=day');
    expect($json['data']['groups']['http'])->toBe(2);

    [, $json] = apiRequest('GET', '/api/v1/activity-logs/tags?prefix=gd');
    expect($json['data'])->toBe(['gdpr']);
});

it('preserves timezone semantics at valid statistics boundaries', function (): void {
    databaseDriver()->write(apiEntry(1));

    [$status, $json] = apiRequest(
        'GET',
        '/api/v1/activity-logs/stats?group_by=channel&from=2026-09-08T14%3A00%3A00%2B02%3A00&to=2026-09-08T14%3A01%3A00%2B02%3A00',
    );

    expect($status)->toBe(200)
        ->and($json['data']['groups']['http'])->toBe(1);
});

it('rejects invalid bounded query statistics tag and cursor inputs', function (): void {
    foreach ([
        '/api/v1/activity-logs?per_page=101',
        '/api/v1/activity-logs?channel=invalid',
        '/api/v1/activity-logs?cursor=invalid',
        '/api/v1/activity-logs/stats?group_by=payload',
        '/api/v1/activity-logs/stats?from=definitely-not-a-date&to=2026-01-01',
        '/api/v1/activity-logs/stats?from=2026-02-01&to=2026-01-01',
        '/api/v1/activity-logs/stats?from=2020-01-01&to=2026-01-01',
        '/api/v1/activity-logs/stats?from=2026-01-01',
        '/api/v1/activity-logs/stats?from[]=2026-01-01&to=2026-02-01',
        '/api/v1/activity-logs/tags?limit=101',
    ] as $uri) {
        [$status] = apiRequest('GET', $uri);
        expect($status)->toBe(422, $uri);
    }
});

it('rejects invalid statistics before calling the backend', function (): void {
    $repository = Mockery::mock(ActivityRepositoryInterface::class);
    $repository->shouldNotReceive('aggregate');
    app()->instance(ActivityRepositoryInterface::class, $repository);

    foreach ([
        '/api/v1/activity-logs/stats?group_by[]=channel',
        '/api/v1/activity-logs/stats?interval[]=day',
        '/api/v1/activity-logs/stats?group_by=',
        '/api/v1/activity-logs/stats?interval=',
        '/api/v1/activity-logs/stats?from=&to=',
        '/api/v1/activity-logs/stats?from=2026-02-30&to=2026-03-01',
        '/api/v1/activity-logs/stats?from=2026-01-01T00%3A00%3A00%2B02%3A99&to=2026-01-02T00%3A00%3A00Z',
        '/api/v1/activity-logs/stats?from=2026-01-01T00%3A00%3A00-14%3A01&to=2026-01-02T00%3A00%3A00Z',
        '/api/v1/activity-logs/stats?from='.str_repeat('2', 65).'&to=2026-03-01',
    ] as $uri) {
        [$status] = apiRequest('GET', $uri);
        expect($status)->toBe(422);
    }
});

it('rejects invalid listing dates before calling the backend', function (): void {
    $repository = Mockery::mock(ActivityRepositoryInterface::class);
    $repository->shouldNotReceive('search');
    app()->instance(ActivityRepositoryInterface::class, $repository);

    foreach ([
        '/api/v1/activity-logs?from=2026-02-30&to=2026-03-01',
        '/api/v1/activity-logs?from=2026-01-01T00%3A00%3A00%2B02%3A99&to=2026-01-02T00%3A00%3A00Z',
        '/api/v1/activity-logs?from=2026-01-01T00%3A00%3A00Z&to=2026-01-01T00%3A00%3A00Z',
        '/api/v1/activity-logs?from=2020-01-01&to=2026-01-01',
        '/api/v1/activity-logs?to=2026-01-01',
        '/api/v1/activity-logs?from[]=2026-01-01&to=2026-02-01',
    ] as $uri) {
        [$status] = apiRequest('GET', $uri);
        expect($status)->toBe(422, $uri);
    }
});

it('uses half-open calendar-day statistics boundaries', function (): void {
    $inside = ActivityEntry::fromStorage([
        ...apiEntry(41)->toStorage(),
        'logged_at'  => '2026-09-08T23:59:59.999999Z',
        'created_at' => '2026-09-08T23:59:59.999999Z',
    ]);
    $nextMidnight = ActivityEntry::fromStorage([
        ...apiEntry(42)->toStorage(),
        'logged_at'  => '2026-09-09T00:00:00.000000Z',
        'created_at' => '2026-09-09T00:00:00.000000Z',
    ]);
    databaseDriver()->writeBatch([$inside, $nextMidnight]);

    [$status, $json] = apiRequest('GET', '/api/v1/activity-logs/stats?group_by=channel&from=2026-09-08&to=2026-09-08');

    expect($status)->toBe(200)
        ->and($json['data']['groups'])->toBe(['http' => 1]);
});

it('uses configured named-timezone calendar days across DST transitions', function (
    string $day,
    string $before,
    string $first,
    string $last,
    string $nextMidnight,
    int $expectedHours,
): void {
    config()->set('app.timezone', 'Europe/Berlin');
    $entries = [
        dstApiEntry(51, $before),
        dstApiEntry(52, $first),
        dstApiEntry(53, $last),
        dstApiEntry(54, $nextMidnight),
    ];
    databaseDriver()->writeBatch($entries);
    $driver = databaseDriver();
    $listingQuery = null;
    $statisticsQuery = null;
    $repository = Mockery::mock(ActivityRepositoryInterface::class);
    $repository->shouldReceive('search')->once()->andReturnUsing(function (ActivityQuery $query) use ($driver, &$listingQuery): QueryResult {
        $listingQuery = clone $query;

        return $driver->search($query);
    });
    $repository->shouldReceive('aggregate')->once()->andReturnUsing(function (ActivityQuery $query, string $groupBy, string $interval) use ($driver, &$statisticsQuery): array {
        $statisticsQuery = clone $query;

        return $driver->aggregate($query, $groupBy, $interval);
    });
    app()->instance(ActivityRepositoryInterface::class, $repository);

    [$listingStatus, $listing] = apiRequest('GET', "/api/v1/activity-logs?from={$day}&to={$day}");
    [$statisticsStatus, $statistics] = apiRequest('GET', "/api/v1/activity-logs/stats?group_by=channel&from={$day}&to={$day}");

    if (! $listingQuery instanceof ActivityQuery || ! $statisticsQuery instanceof ActivityQuery) {
        throw new LogicException('Expected activity date queries.');
    }

    $timezone = new DateTimeZone('Europe/Berlin');
    $expectedFrom = new DateTimeImmutable($day, $timezone);
    $expectedTo = $expectedFrom->modify('+1 day');

    expect($listingStatus)->toBe(200)
        ->and($statisticsStatus)->toBe(200)
        ->and(array_column($listing['data'], 'id'))->toBe([$entries[2]->id, $entries[1]->id])
        ->and($statistics['data']['groups'])->toBe(['http' => 2])
        ->and($listingQuery->from?->getTimestamp())->toBe($expectedFrom->getTimestamp())
        ->and($listingQuery->to?->getTimestamp())->toBe($expectedTo->getTimestamp())
        ->and($statisticsQuery->from?->getTimestamp())->toBe($expectedFrom->getTimestamp())
        ->and($statisticsQuery->to?->getTimestamp())->toBe($expectedTo->getTimestamp())
        ->and($expectedTo->getTimestamp() - $expectedFrom->getTimestamp())->toBe($expectedHours * 3600);
})->with([
    'spring forward day' => [
        '2026-03-29',
        '2026-03-28T22:59:59.999999Z',
        '2026-03-28T23:00:00.000000Z',
        '2026-03-29T21:59:59.999999Z',
        '2026-03-29T22:00:00.000000Z',
        23,
    ],
    'fall back day' => [
        '2026-10-25',
        '2026-10-24T21:59:59.999999Z',
        '2026-10-24T22:00:00.000000Z',
        '2026-10-25T22:59:59.999999Z',
        '2026-10-25T23:00:00.000000Z',
        25,
    ],
]);

it('reports driver-specific unsupported statistics as validation errors', function (): void {
    $repository = new class () implements ActivityRepositoryInterface {
        public int $aggregateCalls = 0;

        public function find(string $id): ?ActivityEntry
        {
            return null;
        }

        public function search(ActivityQuery $query): QueryResult
        {
            return new QueryResult([]);
        }

        public function count(ActivityQuery $query): int
        {
            return 0;
        }

        public function aggregate(ActivityQuery $query, string $groupBy, string $interval = 'day'): array
        {
            $this->aggregateCalls++;

            throw new InvalidArgumentException('The active driver does not support time buckets.');
        }

        public function aggregateTop(ActivityQuery $query, string $groupBy, int $limit): array
        {
            return [];
        }

        /** @return array{buckets: list<DateTimeImmutable>, series: array<string, list<int>>} */
        public function aggregateSeries(ActivityQuery $query, ?string $groupBy, int $stepSeconds): array
        {
            return ['buckets' => [], 'series' => []];
        }

        public function tags(?string $prefix = null, int $limit = 50): array
        {
            return [];
        }

        public function reveal(ActivityEntry $entry, string $path, ?object $viewer = null): string
        {
            return '';
        }
    };
    app()->instance(ActivityRepositoryInterface::class, $repository);
    [$status, $json] = apiRequest('GET', '/api/v1/activity-logs/stats?group_by=logged_at&interval=hour');

    expect($status)->toBe(422)
        ->and($json['message'])->toContain('The active driver does not support time buckets.')
        ->and($repository->aggregateCalls)->toBe(1);
});

it('denies listing when the policy forbids viewing', function (): void {
    Gate::define('viewActivityLog', static fn ($user = null): bool => false);

    [$status] = apiRequest('GET', '/api/v1/activity-logs', new stdClass());
    expect($status)->toBe(403);
});

it('reveals secrets through the policy and audits the reveal', function (): void {
    config()->set('eagle-eye.privacy.paths', ['note' => 'encrypt']);

    $sealed = eagleEye()->record('secret.probe', ['note' => 'hunter2']);
    app(Flusher::class)->flush();

    expect($sealed)->not->toBeNull();

    if (!$sealed instanceof ActivityEntry) {
        return;
    }

    expect($sealed->payload['note'])->toBe(['__secret' => 'payload.note']);

    [$status] = apiRequest('POST', '/api/v1/activity-logs/'.$sealed->id.'/reveal');
    expect($status)->toBe(422);

    [$status, $json] = apiRequest('POST', '/api/v1/activity-logs/'.$sealed->id.'/reveal?path=payload.note');
    expect($status)->toBe(200)
        ->and($json['data']['value'])->toBe('hunter2');

    $audit = databaseDriver()->search(
        (new ActivityQuery())->search('revealed'),
    );
    expect($audit->entries)->toHaveCount(1);
});

it('denies reveal when the policy forbids it', function (): void {
    Gate::define('revealActivityLog', static fn ($user = null, $entry = null, $path = null): bool => false);

    databaseDriver()->write(apiEntry(1));
    [$status] = apiRequest('POST', '/api/v1/activity-logs/'.apiEntry(1)->id.'/reveal?path=payload.note');

    expect($status)->toBe(403);
});

it('answers 501 for live tail on drivers without the capability', function (): void {
    [$status, $json] = apiRequest('GET', '/api/v1/activity-logs/stream');

    expect($status)->toBe(501)
        ->and($json['meta']['reason'])->toContain('live tailing');
});

it('returns the identical envelope on the memory driver', function (): void {
    EagleEye::fake();

    $entry = apiEntry(1);
    app(ActivityLogDriverFactoryInterface::class)->driver()->write($entry);

    [, $json] = apiRequest('GET', '/api/v1/activity-logs');

    expect($json['data'][0])->toHaveKeys([
        'id', 'schema_version', 'logged_at', 'channel', 'action', 'severity',
        'title', 'summary', 'actor', 'subject', 'trace', 'tags', 'request',
        'changes', 'payload', 'sealed', 'payload_bytes', 'environment',
        'hostname', 'memory_kb', 'integrity_hash', 'created_at',
    ])
        ->and($json['meta']['driver'])->toBe('memory');
});
