<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Drivers\Loki;

final readonly class LokiStream
{
    /**
     * @param array<string, string> $labels
     * @param list<array{0: string, 1: string, 2: array<string, string>}> $values [unix_ns, line, structured_metadata]
     */
    public function __construct(
        public array $labels,
        public array $values = [],
    ) {
    }

    /** @param array<string, string> $metadata */
    public function add(string $nanoseconds, string $line, array $metadata): self
    {
        $values = $this->values;
        $values[] = [$nanoseconds, $line, $metadata];

        return new self($this->labels, $values);
    }
}
