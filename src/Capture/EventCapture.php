<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Capture;

use Dotswan\EagleEye\Contracts\Capture\CaptureAdapterInterface;
use Dotswan\EagleEye\Contracts\EagleEyeInterface;
use Dotswan\EagleEye\Data\ActivityDraft;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Throwable;
use Closure;

abstract class EventCapture implements CaptureAdapterInterface
{
    public function __construct(
        protected readonly Dispatcher $events,
        protected readonly Container $container,
    ) {
    }

    /** @return array<string, Closure> */
    abstract protected function listeners(): array;

    public function register(): void
    {
        foreach ($this->listeners() as $eventClass => $handler) {
            if (! class_exists($eventClass) && ! interface_exists($eventClass)) {
                continue;
            }

            $this->events->listen($eventClass, function (object $event) use ($handler): void {
                $suppression = app(CaptureSuppression::class);

                if ($suppression->active()) {
                    return;
                }

                try {
                    $handler($event);
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
    }

    protected function record(ActivityDraft $draft): void
    {
        app(EagleEyeInterface::class)->record($draft);
    }

    protected function dropsEagleEyeWork(string $classOrJob): bool
    {
        return str_starts_with($classOrJob, 'Dotswan\\EagleEye\\');
    }
}
