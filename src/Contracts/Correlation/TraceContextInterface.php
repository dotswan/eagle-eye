<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Contracts\Correlation;

interface TraceContextInterface
{
    public function traceId(): string;

    public function correlationId(): string;

    public function causationId(): ?string;

    public function parentSpanId(): ?string;
}
