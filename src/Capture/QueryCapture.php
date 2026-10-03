<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Capture;

use Dotswan\EagleEye\Capture\Query\QuerySummaryCollector;
use Dotswan\EagleEye\Contracts\Capture\CaptureAdapterInterface;
use Dotswan\EagleEye\Contracts\EagleEyeInterface;
use Dotswan\EagleEye\Data\ActivityDraft;
use Dotswan\EagleEye\Enums\ActivityChannel;
use Dotswan\EagleEye\Enums\ActivitySeverity;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Events\QueryExecuted;
use Throwable;

final readonly class QueryCapture implements CaptureAdapterInterface
{
    public function __construct(
        private Container $container,
    ) {
    }

    public function register(): void
    {
        if (! class_exists(QueryExecuted::class) || ! $this->container->bound('db')) {
            return;
        }

        $config = $this->container->make(ConfigRepository::class);
        $slowEvents = (bool) $config->get('eagle-eye.capture.query.enabled', false);
        $summary = (bool) $config->get('eagle-eye.capture.query.summary', true);

        if (! $slowEvents && ! $summary) {
            return;
        }

        $threshold = max(0, (int) $config->get('eagle-eye.capture.query.slow_threshold_ms', 500));

        $this->container->make('db')->listen(function (QueryExecuted $event) use ($slowEvents, $summary, $threshold): void {
            $suppression = app(CaptureSuppression::class);

            if ($suppression->active()) {
                return;
            }

            try {
                $this->handle($event, $slowEvents, $summary, $threshold);
            } catch (Throwable $exception) {
                try {
                    $suppression->run(static function () use ($exception): void {
                        report($exception);
                    });
                } catch (Throwable) {
                }
            }
        });
    }

    private function handle(QueryExecuted $event, bool $slowEvents, bool $summary, int $threshold): void
    {
        $time = (float) $event->time;

        if ($summary) {
            $this->container->make(QuerySummaryCollector::class)->record($event->sql, $time);
        }

        if (! $slowEvents || $time < $threshold) {
            return;
        }

        app(EagleEyeInterface::class)->record(
            ActivityDraft::make('query.slow', ActivityChannel::Query, ActivitySeverity::Warning)->withPayload([
                'sql'            => $event->sql,
                'duration_ms'    => (int) round($time),
                'connection'     => $event->connectionName,
                'bindings_count' => count($event->bindings),
            ]),
        );
    }
}
