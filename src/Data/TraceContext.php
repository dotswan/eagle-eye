<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Data;

use Dotswan\EagleEye\Contracts\Correlation\TraceContextInterface;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class TraceContext implements TraceContextInterface
{
    public function __construct(
        public string $traceId,
        public string $correlationId,
        public ?string $causationId = null,
        public ?string $parentSpanId = null,
    ) {
        if (1 !== preg_match('/^[a-f0-9]{32}$/', $this->traceId) || $this->traceId === str_repeat('0', 32)) {
            throw new InvalidArgumentException('Trace ID must be 32 lowercase hexadecimal characters and cannot be all zeros.');
        }

        if ('' === $this->correlationId) {
            throw new InvalidArgumentException('Correlation ID cannot be empty.');
        }
    }

    public static function create(?string $causationId = null, ?string $parentSpanId = null): self
    {
        return new self(
            traceId: bin2hex(random_bytes(16)),
            correlationId: (string) Str::ulid(),
            causationId: $causationId,
            parentSpanId: $parentSpanId,
        );
    }

    public function traceId(): string
    {
        return $this->traceId;
    }

    public function correlationId(): string
    {
        return $this->correlationId;
    }

    public function causationId(): ?string
    {
        return $this->causationId;
    }

    public function parentSpanId(): ?string
    {
        return $this->parentSpanId;
    }

    /** @return array{trace_id: string, correlation_id: string, causation_id: ?string, parent_span_id: ?string} */
    public function toArray(): array
    {
        return [
            'trace_id'       => $this->traceId,
            'correlation_id' => $this->correlationId,
            'causation_id'   => $this->causationId,
            'parent_span_id' => $this->parentSpanId,
        ];
    }
}
