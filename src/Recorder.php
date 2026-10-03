<?php

declare(strict_types=1);

namespace Dotswan\EagleEye;

use Dotswan\EagleEye\Capture\CaptureSuppression;
use Dotswan\EagleEye\Contracts\RecorderInterface;
use Dotswan\EagleEye\Correlation\TraceManager;
use Dotswan\EagleEye\Data\ActivityDraft;
use Dotswan\EagleEye\Data\ActivityEntry;
use Dotswan\EagleEye\Filter\Gate;
use Dotswan\EagleEye\Pipeline\PipelineRunner;
use Dotswan\EagleEye\Transport\ActivityBuffer;
use Dotswan\EagleEye\Transport\Flusher;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Throwable;

final readonly class Recorder implements RecorderInterface
{
    public function __construct(
        private ExceptionHandler $exceptions,
        private ConfigRepository $config,
        private bool $environmentAllowed,
        private Gate $gate,
        private PipelineRunner $pipeline,
        private TraceManager $traces,
        private ActivityBuffer $buffer,
        private Flusher $flusher,
    ) {
    }

    public function record(ActivityDraft $draft): ?ActivityEntry
    {
        try {
            if (app(CaptureSuppression::class)->active()) {
                return null;
            }

            if (! $this->environmentAllowed || ! (bool) $this->config->get('eagle-eye.enabled', true)) {
                return null;
            }

            if (! $this->gate->allows($draft)) {
                return null;
            }

            $draft = app(CaptureSuppression::class)->run(fn (): ?ActivityDraft => $this->pipeline->run($draft));

            if (!$draft instanceof ActivityDraft) {
                return null;
            }

            $entry = $draft->freeze();
            $this->traces->recorded($entry->id);

            if ($this->buffer->wouldOverflow($entry)) {
                $this->flusher->flush();
            }

            if ($this->buffer->push($entry)) {
                $this->flusher->flush();
            }

            return $entry;
        } catch (Throwable $exception) {
            try {
                app(CaptureSuppression::class)->run(fn () => $this->exceptions->report($exception));
            } catch (Throwable) {
            }

            return null;
        }
    }
}
