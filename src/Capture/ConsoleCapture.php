<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Capture;

use Dotswan\EagleEye\Capture\Concerns\ReadsCaptureConfig;
use Dotswan\EagleEye\Capture\Query\QuerySummaryCollector;
use Dotswan\EagleEye\Data\ActivityDraft;
use Dotswan\EagleEye\Enums\ActivityChannel;
use Dotswan\EagleEye\Enums\ActivitySeverity;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Symfony\Component\Console\Input\InputInterface;

final class ConsoleCapture extends EventCapture
{
    use ReadsCaptureConfig;

    private const string SELF_PREFIX = 'activity-log:';

    private const int MAX_STARTED = 1000;

    /** @var array<int, float> */
    private array $started = [];

    protected function listeners(): array
    {
        $events = $this->captureEvents('console', ['finished']);
        $captureStarted = in_array('started', $events, true);
        $captureFinished = in_array('finished', $events, true);
        $listeners = [];

        if ($captureStarted || $captureFinished) {
            $listeners[CommandStarting::class] = fn (CommandStarting $event) => $this->onStarting($event, $captureStarted, $captureFinished);
        }

        if ($captureFinished) {
            $listeners[CommandFinished::class] = $this->onFinished(...);
        }

        if (class_exists(ScheduledTaskStarting::class)) {
            $listeners[ScheduledTaskStarting::class] = fn (ScheduledTaskStarting $event) => $this->onSchedule($event->task, 'schedule.started', ActivitySeverity::Info);
            $listeners[ScheduledTaskFinished::class] = fn (ScheduledTaskFinished $event) => $this->onSchedule($event->task, 'schedule.finished', ActivitySeverity::Info, ['duration_ms' => (int) round($event->runtime * 1000)]);
            $listeners[ScheduledTaskFailed::class] = fn (ScheduledTaskFailed $event) => $this->onSchedule($event->task, 'schedule.failed', ActivitySeverity::Error, ['error' => $event->exception->getMessage()]);
            $listeners[ScheduledTaskSkipped::class] = fn (ScheduledTaskSkipped $event) => $this->onSchedule($event->task, 'schedule.skipped', ActivitySeverity::Debug);
        }

        return $listeners;
    }

    private function onStarting(CommandStarting $event, bool $emit, bool $frame): void
    {
        if ($this->excluded($event->command)) {
            return;
        }

        $this->rememberStart($event->input);

        if ($frame) {
            $this->openFrame($event->input);
        }

        if (! $emit) {
            return;
        }

        $payload = $this->commandPayload($event->input, $event->command);
        $this->record(ActivityDraft::make('console.started', ActivityChannel::Console, ActivitySeverity::Info)->withPayload($payload));
    }

    private function onFinished(CommandFinished $event): void
    {
        if ($this->excluded($event->command)) {
            return;
        }

        $severity = 0 === $event->exitCode ? ActivitySeverity::Info : ActivitySeverity::Warning;
        $payload = $this->commandPayload($event->input, $event->command);
        $payload['exit_code'] = $event->exitCode;

        $duration = $this->forgetDuration($event->input);

        if (null !== $duration) {
            $payload['duration_ms'] = $duration;
        }

        $payload['memory_peak_mb'] = (int) round(memory_get_peak_usage(true) / 1048576);

        $draft = ActivityDraft::make('console.finished', ActivityChannel::Console, $severity)->withPayload($payload);
        $draft->queryFrameKey = $this->frameKey($event->input);
        $this->record($draft);
        $this->closeFrame($event->input);
    }

    /** @return array<string, mixed> */
    private function commandPayload(?InputInterface $input, string $command): array
    {
        $payload = ['command' => $command];

        if ($input instanceof InputInterface) {
            $payload['arguments'] = $this->normalise($input->getArguments());
            $payload['options'] = $this->normalise($input->getOptions());
        }

        return $payload;
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private function normalise(array $values): array
    {
        unset($values['command']);

        return $values;
    }

    private function rememberStart(?InputInterface $input): void
    {
        if (! $input instanceof InputInterface) {
            return;
        }

        if (count($this->started) >= self::MAX_STARTED) {
            unset($this->started[array_key_first($this->started)]);
        }

        $this->started[spl_object_id($input)] = microtime(true);
    }

    private function frameKey(?InputInterface $input): ?string
    {
        return $input instanceof InputInterface ? 'cmd:'.spl_object_id($input) : null;
    }

    private function openFrame(?InputInterface $input): void
    {
        $key = $this->frameKey($input);

        if (null !== $key) {
            $this->container->make(QuerySummaryCollector::class)->open($key);
        }
    }

    private function closeFrame(?InputInterface $input): void
    {
        $key = $this->frameKey($input);

        if (null !== $key) {
            $this->container->make(QuerySummaryCollector::class)->close($key);
        }
    }

    private function forgetDuration(?InputInterface $input): ?int
    {
        if (! $input instanceof InputInterface) {
            return null;
        }

        $id = spl_object_id($input);

        if (! isset($this->started[$id])) {
            return null;
        }

        $duration = (int) round((microtime(true) - $this->started[$id]) * 1000);
        unset($this->started[$id]);

        return $duration;
    }

    private function excluded(string $command): bool
    {
        if (str_starts_with($command, self::SELF_PREFIX)) {
            return true;
        }

        $patterns = $this->captureConfig('console.except', []);
        $patterns = is_array($patterns) ? $patterns : [];

        foreach ($patterns as $pattern) {
            if (is_string($pattern) && '' !== $pattern && fnmatch($pattern, $command)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $extra */
    private function onSchedule(object $task, string $action, ActivitySeverity $severity, array $extra = []): void
    {
        $payload = array_merge([
            'command' => property_exists($task, 'command') ? (string) $task->command : 'unknown',
        ], $extra);

        $this->record(ActivityDraft::make($action, ActivityChannel::Schedule, $severity)->withPayload($payload));
    }
}
