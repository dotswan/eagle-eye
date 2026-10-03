<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Correlation;

use Dotswan\EagleEye\Data\TraceContext;

final class TraceManager
{
    private ?TraceContext $base = null;

    private ?string $lastEntryId = null;

    public function adopt(TraceContext $context): void
    {
        $this->base = $context;
        $this->lastEntryId = null;
    }

    public function next(): TraceContext
    {
        $base = $this->base ??= TraceContext::create();

        $causationId = $this->lastEntryId ?? $base->causationId;

        if (null === $causationId) {
            return $base;
        }

        return new TraceContext($base->traceId, $base->correlationId, $causationId, $base->parentSpanId);
    }

    public function recorded(string $entryId): void
    {
        $this->lastEntryId = $entryId;
    }

    public function reset(): void
    {
        $this->base = null;
        $this->lastEntryId = null;
    }
}
