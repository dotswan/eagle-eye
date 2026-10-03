<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Data;

final readonly class IntegrityVerificationResult
{
    /** @param list<string> $invalidEntryIds */
    public function __construct(
        public int $checked,
        public array $invalidEntryIds,
        public int $invalidCount,
        public int $pendingCount = 0,
    ) {
    }

    public function valid(): bool
    {
        return 0 === $this->invalidCount && [] === $this->invalidEntryIds && 0 === $this->pendingCount;
    }

    public function truncated(): bool
    {
        return $this->invalidCount > count($this->invalidEntryIds);
    }
}
