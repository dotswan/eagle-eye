<?php

declare(strict_types=1);

use Dotswan\EagleEye\Data\ActivityEntry;
use Dotswan\EagleEye\Data\ActivityDraft;
use Dotswan\EagleEye\Data\TraceContext;
use Dotswan\EagleEye\Contracts\EagleEyeInterface;
use Dotswan\EagleEye\Correlation\TraceManager;
use Dotswan\EagleEye\Facades\EagleEye;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use Dotswan\EagleEye\Capture\QueueCapture;
use Dotswan\EagleEye\Transport\ActivityBuffer;
use Dotswan\EagleEye\Transport\Flusher;
use Illuminate\Auth\AuthManager;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Auth\User;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

final class TraceRecordingJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        eagleEye()->record('job.executed');
    }
}

final class TraceWorkerHandler
{
    /** @param array{fail: bool} $data */
    public function fire(JobContract $job, array $data): void
    {
        eagleEye()->record('job.executed');

        if ($data['fail']) {
            throw new RuntimeException('worker failure');
        }

        $job->delete();
    }
}

it('shares one trace id per request and chains causation ids', function (): void {
    $fake = EagleEye::fake();

    $first = eagleEye()->record('checkout.started');
    $second = eagleEye()->record('payment.requested');

    expect($first)->not->toBeNull()
        ->and($second)->not->toBeNull();

    if (!$first instanceof ActivityEntry || !$second instanceof ActivityEntry) {
        return;
    }

    expect($second->trace->traceId)->toBe($first->trace->traceId)
        ->and($second->trace->correlationId)->toBe($first->trace->correlationId)
        ->and($first->trace->causationId)->toBeNull()
        ->and($second->trace->causationId)->toBe($first->id);

    $chain = eagleEye()->query()->forTrace($first->trace->traceId)->orderByTime('asc')->get();
    expect($chain->entries)->toHaveCount(2)
        ->and($chain->entries[0]->id)->toBe($first->id);

    $fake->assertCount(2);
});

it('propagates the dispatching trace into queued jobs', function (): void {
    config()->set('queue.default', 'sync');
    $fake = EagleEye::fake();

    $requestEntry = eagleEye()->record('checkout.submitted');
    dispatch(new TraceRecordingJob());

    expect($requestEntry)->not->toBeNull();

    if (!$requestEntry instanceof ActivityEntry) {
        return;
    }

    $fake->assertLogged(
        static fn ($entry): bool => 'job.executed' === $entry->action
            && $entry->trace->traceId === $requestEntry->trace->traceId,
    );
    $fake->assertLogged(
        static fn ($entry): bool => 'queue.processed' === $entry->action
            && $entry->trace->traceId === $requestEntry->trace->traceId,
    );
});

it('does not leak trace state across container scopes', function (): void {
    $firstManager = app(TraceManager::class);
    $firstTrace = $firstManager->next();

    app()->forgetScopedInstances();

    $secondManager = app(TraceManager::class);
    $secondTrace = $secondManager->next();

    expect($secondManager)->not->toBe($firstManager)
        ->and($secondTrace->traceId)->not->toBe($firstTrace->traceId);
});

it('keeps an explicitly assigned trace context', function (): void {
    $entry = app(EagleEyeInterface::class)->record(
        ActivityDraft::make('manual.trace')
            ->withTrace(TraceContext::create()),
    );

    expect($entry)->not->toBeNull();
});

it('flushes the final buffered successful queue event', function (): void {
    config()->set('queue.default', 'sync');
    $fake = EagleEye::fake();
    config()->set('eagle-eye.transport.buffer', 100);

    dispatch(new TraceRecordingJob());

    $fake->assertCount(0);
    expect(app(ActivityBuffer::class)->pending())->toBeGreaterThan(0);

    app(Flusher::class)->flush();

    $fake->assertLogged(static fn (ActivityEntry $entry): bool => 'queue.processed' === $entry->action);
    expect(app(ActivityBuffer::class)->pending())->toBe(0);
});

it('flushes the final buffered failed queue event without swallowing the exception', function (): void {
    $fake = EagleEye::fake();
    config()->set('eagle-eye.transport.buffer', 100);

    expect(fn () => app('queue')->connection('sync')->push(TraceWorkerHandler::class.'@fire', ['fail' => true]))
        ->toThrow(RuntimeException::class, 'worker failure');

    $fake->assertCount(0);

    app(Flusher::class)->flush();

    $fake->assertLogged(static fn (ActivityEntry $entry): bool => 'queue.failed' === $entry->action);
    expect(app(ActivityBuffer::class)->pending())->toBe(0);
});

it('flushes retry attempts and reported exceptions across actual daemon scopes', function (int $maxJobs): void {
    $fake = EagleEye::fake();
    config()->set('eagle-eye.transport.buffer', 100);
    config()->set('eagle-eye.capture.queue.events', ['exception']);
    (new QueueCapture(app('events'), app()))->register();
    $payload = json_encode([
        'job'         => TraceWorkerHandler::class.'@fire',
        'displayName' => TraceWorkerHandler::class,
        'data'        => ['fail' => true],
    ], JSON_THROW_ON_ERROR);
    $application = app(Application::class);
    $jobs = [
        new SyncJob($application, $payload, 'sync', 'default'),
        new SyncJob($application, $payload, 'sync', 'default'),
    ];
    $worker = app('queue.worker');
    $worker->setName('eagle-eye-trace-test');
    Worker::popUsing('eagle-eye-trace-test', static function () use (&$jobs): ?SyncJob {
        return array_shift($jobs);
    });

    try {
        $status = $worker->daemon('sync', 'default', new WorkerOptions(
            memory: 1024,
            timeout: 0,
            sleep: 0,
            maxTries: 3,
            stopWhenEmpty: true,
            maxJobs: $maxJobs,
        ));
    } finally {
        Worker::popUsing('eagle-eye-trace-test', static fn (): null => null);
    }

    $exceptions = array_values(array_filter($fake->recorded(), static fn (ActivityEntry $entry): bool => 'exception.reported' === $entry->action));
    $retries = array_filter($fake->recorded(), static fn (ActivityEntry $entry): bool => 'queue.exception' === $entry->action);

    expect($status)->toBe(0)
        ->and($exceptions)->toHaveCount(2)
        ->and($retries)->toHaveCount(2)
        ->and($exceptions[0]->trace->traceId)->not->toBe($exceptions[1]->trace->traceId)
        ->and(app(ActivityBuffer::class)->pending())->toBe(0);
})->with(['worker stopping' => [2], 'next worker loop' => [0]]);

it('uses the active sandbox when creating and adopting queued trace context', function (): void {
    config()->set('queue.default', 'sync');
    $fake = EagleEye::fake();
    $original = app(TraceManager::class)->next();
    $application = app(Application::class);
    $sandbox = clone $application;
    $sandbox->forgetScopedInstances();
    Container::setInstance($sandbox);

    try {
        $active = app(TraceManager::class)->next();
        dispatch(new TraceRecordingJob());
    } finally {
        Container::setInstance($application);
    }

    expect($active->traceId)->not->toBe($original->traceId);
    $fake->assertLogged(static fn (ActivityEntry $entry): bool => 'job.executed' === $entry->action && $entry->trace->traceId === $active->traceId);
    expect(app(TraceManager::class)->next()->traceId)->toBe($original->traceId);
});

it('rejects malformed queued trace envelopes without breaking processing', function (mixed $trace): void {
    EagleEye::fake();
    $original = app(TraceManager::class)->next();
    $payload = json_encode([
        'job'           => TraceWorkerHandler::class.'@fire',
        'displayName'   => TraceWorkerHandler::class,
        'data'          => ['fail' => false],
        'eagleEyeTrace' => $trace,
    ], JSON_THROW_ON_ERROR);

    event(new JobProcessing('sync', new SyncJob(app(Application::class), $payload, 'sync', 'default')));

    $current = app(TraceManager::class)->next();
    expect($current->traceId)->not->toBe($original->traceId)
        ->and($current->correlationId)->toMatch('/\A[A-Za-z0-9._:-]{1,128}\z/')
        ->and($current->parentSpanId)->toBeNull();
})->with([
    'array trace id'        => [['trace_id' => [], 'correlation_id' => 'req-123']],
    'array correlation id'  => [['trace_id' => '4bf92f3577b34da6a3ce929d0e0e4736', 'correlation_id' => []]],
    'oversized correlation' => [['trace_id' => '4bf92f3577b34da6a3ce929d0e0e4736', 'correlation_id' => str_repeat('a', 129)]],
    'zero trace'            => [['trace_id' => str_repeat('0', 32), 'correlation_id' => 'req-123']],
    'zero span'             => [['trace_id' => '4bf92f3577b34da6a3ce929d0e0e4736', 'correlation_id' => 'req-123', 'parent_span_id' => str_repeat('0', 16)]],
    'oversized causation'   => [['trace_id' => '4bf92f3577b34da6a3ce929d0e0e4736', 'correlation_id' => 'req-123', 'causation_id' => str_repeat('a', 129)]],
    'scalar envelope'       => ['invalid'],
]);

it('captures auth and model events in the active sandbox without inheriting paused state', function (): void {
    $fake = EagleEye::fake();
    $parentUser = new GenericUser(['id' => 41, 'name' => 'Parent']);
    app('auth')->guard()->setUser($parentUser);
    eagleEye()->record('parent.primer');
    EagleEye::pause();
    $originalTrace = app(TraceManager::class)->next();
    $application = app(Application::class);
    $sandbox = clone $application;
    $sandbox->forgetScopedInstances();
    $sandbox->instance('app', $sandbox);
    $sandbox->instance('request', Request::create('/sandbox', server: ['REMOTE_ADDR' => '192.0.2.77']));
    $sandbox->instance('auth', new AuthManager($sandbox));
    Container::setInstance($sandbox);

    try {
        $active = app(TraceManager::class)->next();
        $user = new GenericUser(['id' => 42, 'name' => 'Sandbox']);
        app('auth')->guard()->setUser($user);
        event(new Login('web', $user, false));
        $model = new User();
        $model->forceFill(['id' => 99, 'name' => 'Subject']);
        event('eloquent.created: '.User::class, [$model]);
    } finally {
        Container::setInstance($application);
    }

    $fake->assertLogged(static fn (ActivityEntry $entry): bool => 'auth.login' === $entry->action
        && '42' === $entry->actor?->id
        && '192.0.2.0' === $entry->request?->ip
        && $active->traceId === $entry->trace->traceId);
    $fake->assertLogged(static fn (ActivityEntry $entry): bool => 'model.created' === $entry->action
        && '42' === $entry->actor?->id
        && '99' === $entry->subject?->id
        && $active->traceId === $entry->trace->traceId);
    expect(EagleEye::isPaused())->toBeTrue()
        ->and(app('auth')->guard()->user())->toBe($parentUser)
        ->and(app(TraceManager::class)->next()->traceId)->toBe($originalTrace->traceId);
});
