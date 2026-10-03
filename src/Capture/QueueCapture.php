<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Capture;

use Dotswan\EagleEye\Capture\Concerns\ReadsCaptureConfig;
use Dotswan\EagleEye\Capture\Query\QuerySummaryCollector;
use Dotswan\EagleEye\Data\ActivityDraft;
use Dotswan\EagleEye\Enums\ActivityChannel;
use Dotswan\EagleEye\Enums\ActivitySeverity;
use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Throwable;

final class QueueCapture extends EventCapture
{
    use ReadsCaptureConfig;

    private const int MAX_STARTED = 1000;

    /** @var array<string, float> */
    private array $started = [];

    protected function listeners(): array
    {
        $events = $this->captureEvents('queue', ['queued', 'processed', 'failed']);
        $summary = (bool) $this->captureConfig('query.summary', true);
        $emitQueued = in_array('queued', $events, true);
        $emitProcessing = in_array('processing', $events, true);
        $emitProcessed = in_array('processed', $events, true);
        $emitFailed = in_array('failed', $events, true);
        $emitException = in_array('exception', $events, true);
        $listeners = [];

        if ($emitQueued) {
            $listeners[JobQueued::class] = $this->onQueued(...);
        }

        if ($emitProcessing || $emitProcessed || $emitFailed || $emitException || $summary) {
            $listeners[JobProcessing::class] = function (JobProcessing $event) use ($emitProcessing): void {
                $job = $this->jobName($event->job);

                if (null === $job) {
                    return;
                }

                $this->rememberStart($event->job);
                $this->openFrame($event->job);

                if (! $emitProcessing) {
                    return;
                }

                $this->record($this->draft('queue.processing', ActivitySeverity::Debug, [
                    'job'        => $job,
                    'connection' => (string) $event->connectionName,
                    'queue'      => $this->queue($event->job),
                    'job_uuid'   => $this->uuid($event->job),
                    'attempts'   => $this->attempts($event->job),
                ]));
            };
        }

        $listeners[JobProcessed::class] = fn (JobProcessed $event) => $this->onProcessed($event, $emitProcessed);

        $listeners[JobFailed::class] = fn (JobFailed $event) => $this->onFailed(
            $event->connectionName,
            $event->job,
            $event->exception,
            $emitFailed,
        );

        $listeners[JobExceptionOccurred::class] = fn (JobExceptionOccurred $event) => $this->onException(
            $event->connectionName,
            $event->job,
            $event->exception,
            $emitException,
        );

        return $listeners;
    }

    private function onQueued(JobQueued $event): void
    {
        $job = is_object($event->job) ? $event->job::class : (string) $event->job;

        if ($this->dropsEagleEyeWork($job)) {
            return;
        }

        $this->record($this->draft('queue.queued', ActivitySeverity::Info, [
            'job'        => $job,
            'connection' => (string) $event->connectionName,
            'queue'      => is_scalar($event->queue) ? (string) $event->queue : 'default',
        ]));
    }

    private function onProcessed(JobProcessed $event, bool $emit): void
    {
        $job = $this->jobName($event->job);

        if (null === $job) {
            return;
        }

        $payload = [
            'job'        => $job,
            'connection' => (string) $event->connectionName,
            'queue'      => $this->queue($event->job),
            'job_uuid'   => $this->uuid($event->job),
            'attempts'   => $this->attempts($event->job),
        ];

        $duration = $this->forgetDuration($event->job);

        if (null !== $duration) {
            $payload['duration_ms'] = $duration;
        }

        if ($emit) {
            $draft = $this->draft('queue.processed', ActivitySeverity::Info, $payload);
            $draft->queryFrameKey = $this->frameKey($event->job);
            $this->record($draft);
        }

        $this->closeFrame($event->job);
    }

    private function onFailed(string $connection, JobContract $job, ?Throwable $exception, bool $emit): void
    {
        $name = $this->jobName($job);

        if (null === $name) {
            return;
        }

        if ($emit) {
            $payload = [
                'job'        => $name,
                'connection' => $connection,
                'queue'      => $this->queue($job),
                'job_uuid'   => $this->uuid($job),
                'attempts'   => $this->attempts($job),
            ];

            $duration = $this->forgetDuration($job);

            if (null !== $duration) {
                $payload['duration_ms'] = $duration;
            }

            if ($exception instanceof Throwable) {
                $payload['exception'] = $exception::class;
                $payload['error'] = $exception->getMessage();
            }

            $draft = $this->draft('queue.failed', ActivitySeverity::Error, $payload);
            $draft->queryFrameKey = $this->frameKey($job);
            $this->record($draft);
        }

        $this->closeFrame($job);
    }

    private function onException(string $connection, JobContract $job, ?Throwable $exception, bool $emit): void
    {
        $name = $this->jobName($job);

        if (null === $name) {
            return;
        }

        if ($emit) {
            $payload = [
                'job'        => $name,
                'connection' => $connection,
                'queue'      => $this->queue($job),
                'job_uuid'   => $this->uuid($job),
                'attempts'   => $this->attempts($job),
            ];

            if ($exception instanceof Throwable) {
                $payload['exception'] = $exception::class;
                $payload['error'] = $exception->getMessage();
            }

            $draft = $this->draft('queue.exception', ActivitySeverity::Error, $payload);
            $draft->queryFrameKey = $this->frameKey($job);
            $this->record($draft);
        }

        if ($job->isReleased() || ! $job->hasFailed()) {
            $this->closeFrame($job);
        }
    }

    private function frameKey(JobContract $job): ?string
    {
        $uuid = $this->uuid($job);

        return null === $uuid ? null : 'job:'.$uuid;
    }

    private function openFrame(JobContract $job): void
    {
        $key = $this->frameKey($job);

        if (null !== $key) {
            $this->container->make(QuerySummaryCollector::class)->open($key);
        }
    }

    private function closeFrame(JobContract $job): void
    {
        $key = $this->frameKey($job);

        if (null !== $key) {
            $this->container->make(QuerySummaryCollector::class)->close($key);
        }
    }

    private function jobName(JobContract $job): ?string
    {
        $name = $job->resolveName();

        return $this->dropsEagleEyeWork($name) ? null : $name;
    }

    private function queue(JobContract $job): string
    {
        $queue = $job->getQueue();

        return '' !== $queue ? $queue : 'default';
    }

    private function uuid(JobContract $job): ?string
    {
        try {
            return $job->uuid();
        } catch (Throwable) {
            return null;
        }
    }

    private function attempts(JobContract $job): int
    {
        return max(0, $job->attempts());
    }

    private function rememberStart(JobContract $job): void
    {
        $uuid = $this->uuid($job);

        if (null === $uuid) {
            return;
        }

        if (count($this->started) >= self::MAX_STARTED) {
            array_shift($this->started);
        }

        $this->started[$uuid] = microtime(true);
    }

    private function forgetDuration(JobContract $job): ?int
    {
        $uuid = $this->uuid($job);

        if (null === $uuid || ! isset($this->started[$uuid])) {
            return null;
        }

        $duration = (int) round((microtime(true) - $this->started[$uuid]) * 1000);
        unset($this->started[$uuid]);

        return $duration;
    }

    /** @param array<string, mixed> $payload */
    private function draft(string $action, ActivitySeverity $severity, array $payload): ActivityDraft
    {
        return ActivityDraft::make($action, ActivityChannel::Queue, $severity)->withPayload($payload);
    }
}
