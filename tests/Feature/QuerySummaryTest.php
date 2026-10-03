<?php

declare(strict_types=1);

use Dotswan\EagleEye\Capture\HttpCapture;
use Dotswan\EagleEye\Capture\QueueCapture;
use Dotswan\EagleEye\Capture\CaptureSuppression;
use Dotswan\EagleEye\Capture\Query\QuerySummaryCollector;
use Dotswan\EagleEye\Capture\Query\SqlFingerprint;
use Dotswan\EagleEye\Data\ActivityDraft;
use Dotswan\EagleEye\Data\RequestSnapshot;
use Dotswan\EagleEye\Enums\ActivityChannel;
use Dotswan\EagleEye\Enums\FilterDecision;
use Dotswan\EagleEye\Facades\EagleEye;
use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Mockery\MockInterface;

it('normalises literals and in-lists out of a fingerprint', function (): void {
    $fingerprint = new SqlFingerprint();

    expect($fingerprint->normalise("select * from users where id = 42 and name = 'bob'"))
        ->toBe('select * from users where id = ? and name = ?')
        ->and($fingerprint->normalise('select * from users where id in (1, 2, 3)'))
        ->toBe('select * from users where id in (?)')
        ->and($fingerprint->normalise('select * from users where id in (?, ?, ?)'))
        ->toBe('select * from users where id in (?)');
});

it('summarises counts, duplicates and slow queries without binding values', function (): void {
    $collector = new QuerySummaryCollector(new SqlFingerprint(), 100);

    for ($i = 0; $i < 3; $i++) {
        $collector->record('select * from users where id = ?', 1.0 + $i);
    }

    $collector->record("select * from orders where reference = 'secret'", 250.0);

    $summary = $collector->peek(QuerySummaryCollector::ROOT);

    if (! is_array($summary)) {
        throw new RuntimeException('Expected a query summary.');
    }

    expect($summary['count'])->toBe(4)
        ->and($summary['total_ms'])->toBeFloat()
        ->and($summary['duplicates'])->toHaveCount(1)
        ->and($summary['duplicates'][0]['count'])->toBe(3)
        ->and($summary['duplicates'][0]['total_ms'])->toBeFloat()
        ->and($summary['slow'])->toHaveCount(1)
        ->and($summary['slow'][0]['ms'])->toBe(250.0)
        ->and(json_encode($summary))->not->toContain('secret');
});

it('reports an empty slow list when no query reaches the threshold', function (): void {
    $collector = new QuerySummaryCollector(new SqlFingerprint(), 100);
    $collector->record('select * from fast', 5.0);

    $summary = $collector->peek(QuerySummaryCollector::ROOT);

    if (! is_array($summary)) {
        throw new RuntimeException('Expected a query summary.');
    }

    expect($summary['slow'])->toBe([]);
});

it('caps retained fingerprints and reports truncation', function (): void {
    $collector = new QuerySummaryCollector(new SqlFingerprint(), 100);

    for ($i = 0; $i < 60; $i++) {
        $collector->record('select * from t'.$i, 1.0);
    }

    $summary = $collector->peek(QuerySummaryCollector::ROOT);

    if (! is_array($summary)) {
        throw new RuntimeException('Expected a query summary.');
    }

    expect($summary['count'])->toBe(60)
        ->and($summary['truncated'])->toBeTrue();
});

it('merges a closed child frame into the parent nested totals', function (): void {
    $collector = new QuerySummaryCollector(new SqlFingerprint(), 100);
    $collector->record('root.query', 1.5);
    $collector->open('job:alpha');
    $collector->record('job.query.one', 2.0);
    $collector->record('job.query.two', 3.5);

    $child = $collector->close('job:alpha');
    $root = $collector->peek(QuerySummaryCollector::ROOT);

    expect($child['count'] ?? null)->toBe(2)
        ->and($child['total_ms'] ?? null)->toBe(5.5)
        ->and($root['count'] ?? null)->toBe(1)
        ->and($root['nested']['count'] ?? null)->toBe(2)
        ->and($root['nested']['total_ms'] ?? null)->toBe(5.5)
        ->and($collector->depth())->toBe(1)
        ->and($collector->activeKey())->toBe(QuerySummaryCollector::ROOT);
});

it('bounds frame depth and flags truncation for deeper work', function (): void {
    $collector = new QuerySummaryCollector(new SqlFingerprint(), 100);

    for ($depth = 0; $depth < 12; $depth++) {
        $collector->open('frame:'.$depth);
    }

    $collector->record('select 1', 1.0);

    expect($collector->depth())->toBe(8)
        ->and($collector->peek('frame:11'))->toBeNull()
        ->and($collector->peek('frame:6')['truncated'] ?? null)->toBeTrue();
});

it('runs the SQL fingerprint once per distinct statement', function (): void {
    /** @var SqlFingerprint&MockInterface $fingerprint */
    $fingerprint = Mockery::mock(SqlFingerprint::class);
    $fingerprint->shouldReceive('normalise')->times(2)->andReturnUsing(
        static fn (string $sql): string => $sql,
    );
    $collector = new QuerySummaryCollector($fingerprint, 100);

    $collector->record('select distinct a', 1.0);
    $collector->record('select distinct a', 1.0);
    $collector->record('select distinct b', 1.0);

    expect($collector->peek(QuerySummaryCollector::ROOT)['count'] ?? null)->toBe(3);
});

it('attributes nested job queries to the job frame and the parent request', function (): void {
    $fake = EagleEye::fake();
    config()->set('eagle-eye.capture.query.summary', true);
    app(QuerySummaryCollector::class)->reset();

    /** @var JobContract&MockInterface $job */
    $job = Mockery::mock(JobContract::class);
    $job->shouldReceive('resolveName')->andReturn('App\\Jobs\\TestJob');
    $job->shouldReceive('getQueue')->andReturn('default');
    $job->shouldReceive('uuid')->andReturn('uuid-1');
    $job->shouldReceive('attempts')->andReturn(1);
    $job->shouldReceive('payload')->andReturn([]);

    DB::select('select 1');
    Event::dispatch(new JobProcessing('redis', $job));
    DB::select('select 2');
    DB::select('select 3');
    Event::dispatch(new JobProcessed('redis', $job));

    $processed = null;

    foreach ($fake->recorded() as $entry) {
        if ('queue.processed' === $entry->action) {
            $processed = $entry;
        }
    }

    expect($processed?->payload['queries']['count'] ?? null)->toBe(2)
        ->and(app(QuerySummaryCollector::class)->depth())->toBe(1);

    $draft = ActivityDraft::make('http.request', ActivityChannel::Http);
    $draft->request = new RequestSnapshot('GET', '/orders', 'orders.index', 200);
    $request = eagleEye()->record($draft);

    expect($request?->payload['queries']['count'] ?? null)->toBe(1)
        ->and($request?->payload['queries']['nested']['count'] ?? null)->toBe(2);
});

it('resets the root frame at the start of each request scope', function (): void {
    EagleEye::fake();
    $collector = app(QuerySummaryCollector::class);

    app(HttpCapture::class)->begin(Request::create('/orders'));
    DB::select('select 1');
    DB::select('select 1');
    $first = eagleEye()->record(makeQuerySummaryHttpDraft());

    app(HttpCapture::class)->begin(Request::create('/orders'));
    DB::select('select 1');
    $second = eagleEye()->record(makeQuerySummaryHttpDraft());

    expect($first?->payload['queries']['count'] ?? null)->toBe(2)
        ->and($second?->payload['queries']['count'] ?? null)->toBe(1)
        ->and($collector->depth())->toBe(1);
});

function makeQuerySummaryHttpDraft(): ActivityDraft
{
    $draft = ActivityDraft::make('http.request', ActivityChannel::Http);
    $draft->request = new RequestSnapshot('GET', '/orders', 'orders.index', 200);

    return $draft;
}

it('keeps query summary overhead bounded for a thousand queries', function (): void {
    $measure = static function (): float {
        $collector = new QuerySummaryCollector(new SqlFingerprint(), 100);
        $start = microtime(true);

        for ($i = 0; $i < 1000; $i++) {
            $collector->record('select * from t'.$i, (float) ($i % 10));
        }

        return microtime(true) - $start;
    };

    $baseline = $measure();

    expect($baseline)->toBeLessThan(0.5);
})->group('performance');

it('closes a job frame on success even when only the failed event is enabled', function (): void {
    EagleEye::fake();
    config()->set('eagle-eye.capture.query.summary', true);
    config()->set('eagle-eye.capture.queue.events', ['failed']);
    app(QuerySummaryCollector::class)->reset();

    $listeners = queueCaptureListeners();
    $job = makeQueueJob('uuid-c1a');
    $listeners[JobProcessing::class](new JobProcessing('redis', $job));
    DB::select('select 1');
    $listeners[JobProcessed::class](new JobProcessed('redis', $job));

    expect(app(QuerySummaryCollector::class)->depth())->toBe(1);
});

it('closes the job frame when a job is released for retry', function (): void {
    EagleEye::fake();
    config()->set('eagle-eye.capture.query.summary', true);
    app(QuerySummaryCollector::class)->reset();

    $listeners = queueCaptureListeners();
    $job = makeQueueJob('uuid-c1b', released: true);
    $listeners[JobProcessing::class](new JobProcessing('redis', $job));
    DB::select('select 1');
    $listeners[JobExceptionOccurred::class](new JobExceptionOccurred('redis', $job, new RuntimeException('boom')));

    expect(app(QuerySummaryCollector::class)->depth())->toBe(1);
});

it('keeps the job frame open through an exception so the failure draft carries queries', function (): void {
    $fake = EagleEye::fake();
    config()->set('eagle-eye.capture.query.summary', true);
    app(QuerySummaryCollector::class)->reset();

    $listeners = queueCaptureListeners();
    $job = makeQueueJob('uuid-c2', failed: true);
    $listeners[JobProcessing::class](new JobProcessing('redis', $job));
    DB::select('select 9');
    $listeners[JobExceptionOccurred::class](new JobExceptionOccurred('redis', $job, new RuntimeException('boom')));
    $listeners[JobFailed::class](new JobFailed('redis', $job, new RuntimeException('boom')));

    $failed = null;

    foreach ($fake->recorded() as $entry) {
        if ('queue.failed' === $entry->action) {
            $failed = $entry;
        }
    }

    expect($failed?->payload['queries']['count'] ?? null)->toBe(1)
        ->and(app(QuerySummaryCollector::class)->depth())->toBe(1);
});

it('gives each worker job its own queries after scoped instances reset', function (): void {
    $fake = EagleEye::fake();
    config()->set('eagle-eye.capture.query.summary', true);
    config()->set('eagle-eye.capture.queue.events', ['processed']);
    app(QuerySummaryCollector::class)->reset();

    $listeners = queueCaptureListeners();

    foreach (['uuid-b2a', 'uuid-b2b'] as $uuid) {
        app()->forgetScopedInstances();
        $job = makeQueueJob($uuid);
        $listeners[JobProcessing::class](new JobProcessing('redis', $job));
        DB::select('select 1');
        $listeners[JobProcessed::class](new JobProcessed('redis', $job));
    }

    $counts = [];

    foreach ($fake->recorded() as $entry) {
        if ('queue.processed' === $entry->action) {
            $counts[] = $entry->payload['queries']['count'] ?? null;
        }
    }

    expect($counts)->toBe([1, 1]);
});

it('closes a job frame even when the processed draft is filtered out', function (): void {
    EagleEye::fake();
    config()->set('eagle-eye.capture.query.summary', true);
    config()->set('eagle-eye.filters.rules', [
        fn (ActivityDraft $draft): FilterDecision => 'queue.processed' === $draft->action
            ? FilterDecision::Deny
            : FilterDecision::Abstain,
    ]);
    app(QuerySummaryCollector::class)->reset();

    $listeners = queueCaptureListeners();
    $job = makeQueueJob('uuid-b3');
    $listeners[JobProcessing::class](new JobProcessing('redis', $job));
    DB::select('select 1');
    $listeners[JobProcessed::class](new JobProcessed('redis', $job));

    expect(app(QuerySummaryCollector::class)->depth())->toBe(1);
});

/**
 * @return array<class-string, callable>
 */
function queueCaptureListeners(): array
{
    $reflection = new ReflectionMethod(QueueCapture::class, 'listeners');

    /** @var array<class-string, callable> $listeners */
    $listeners = $reflection->invoke(app(QueueCapture::class));

    return $listeners;
}

function makeQueueJob(string $uuid, bool $released = false, bool $failed = false): JobContract
{
    /** @var JobContract&MockInterface $job */
    $job = Mockery::mock(JobContract::class);
    $job->shouldReceive('resolveName')->andReturn('App\\Jobs\\TestJob');
    $job->shouldReceive('getQueue')->andReturn('default');
    $job->shouldReceive('uuid')->andReturn($uuid);
    $job->shouldReceive('attempts')->andReturn(1);
    $job->shouldReceive('payload')->andReturn([]);
    $job->shouldReceive('isReleased')->andReturn($released);
    $job->shouldReceive('hasFailed')->andReturn($failed);

    return $job;
}

it('bounds frame memo memory for large statements', function (): void {
    $collector = new QuerySummaryCollector(new SqlFingerprint(), 100);
    $sql = 'select * from t where payload = '.str_repeat('a', 20_000).' and id = ';
    $before = memory_get_usage();

    for ($frame = 0; $frame < 8; $frame++) {
        $collector->open('frame-'.$frame);

        for ($i = 0; $i < 50; $i++) {
            $collector->record($sql.$i, 1.0);
        }
    }

    $after = memory_get_usage();

    expect($collector->depth())->toBe(8)
        ->and($after - $before)->toBeLessThan(1_048_576);
});

it('keeps query listener overhead within a bounded per-query cost of a paused listener', function (): void {
    EagleEye::fake();
    config()->set('eagle-eye.capture.query.summary', true);
    $collector = app(QuerySummaryCollector::class);

    $measure = function () use ($collector): float {
        $collector->reset();
        DB::select('select 1');
        $start = hrtime(true);

        for ($i = 0; $i < 1000; $i++) {
            DB::select('select 1');
        }

        return (hrtime(true) - $start) / 1_000_000;
    };

    $paused = INF;
    $active = INF;

    for ($sample = 0; $sample < 9; $sample++) {
        $paused = min($paused, app(CaptureSuppression::class)->run($measure(...)));
        $active = min($active, $measure());
    }

    $overheadMicrosecondsPerQuery = max(0.0, $active - $paused);

    expect($overheadMicrosecondsPerQuery)->toBeLessThan(10.0)
        ->and($active)->toBeLessThanOrEqual($paused * 1.5);
})->group('performance');
