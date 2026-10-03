<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Privacy;

use Dotswan\EagleEye\Contracts\Driver\ActivityLogDriverFactoryInterface;
use Dotswan\EagleEye\Contracts\Driver\ActivityLogMaintenanceDriverInterface;
use Dotswan\EagleEye\Contracts\Privacy\SubjectEraserInterface;
use Dotswan\EagleEye\Contracts\RecorderInterface;
use Dotswan\EagleEye\Data\ActivityDraft;
use Dotswan\EagleEye\Data\ErasureResult;
use Dotswan\EagleEye\Enums\ActivityChannel;
use Dotswan\EagleEye\Enums\ActivitySeverity;
use LogicException;
use RuntimeException;
use Throwable;

final readonly class SubjectEraser implements SubjectEraserInterface
{
    public function __construct(
        private ActivityLogDriverFactoryInterface $drivers,
        private RecorderInterface $recorder,
        private ErasureRegistry $registry,
    ) {
    }

    public function erase(string $subjectType, string $subjectId): ErasureResult
    {
        $driver = $this->drivers->driver();

        if (! $driver instanceof ActivityLogMaintenanceDriverInterface) {
            throw new LogicException("Driver [{$driver->name()}] does not support erasure.");
        }

        $requestId = $this->registry->begin($subjectType, $subjectId);

        try {
            $this->registry->assertDrained($subjectType, $subjectId);
            $result = $driver->forget($subjectType, $subjectId);
        } catch (Throwable $exception) {
            $this->registry->finish($requestId, $subjectType, $subjectId, ErasureResult::failed($driver->name()));

            throw $exception;
        }

        $this->registry->finish($requestId, $subjectType, $subjectId, $result);

        if (! $result->accepted()) {
            throw new RuntimeException("Driver [{$driver->name()}] did not accept the erasure request completely; state [{$result->state}].");
        }

        $this->recorder->record(
            ActivityDraft::make('completed' === $result->state ? 'privacy.subject.forgotten' : 'privacy.subject.erasure_requested', ActivityChannel::Custom, ActivitySeverity::Notice)
                ->withPayload([
                    'subject_type'   => $subjectType,
                    'subject_digest' => $this->registry->digest($subjectType, $subjectId),
                    'request_id'     => $requestId,
                    'removed'        => $result->removed,
                    'outcome'        => $result->state,
                ]),
        );

        return $result;
    }
}
