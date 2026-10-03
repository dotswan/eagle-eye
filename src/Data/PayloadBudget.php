<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Data;

use InvalidArgumentException;

final readonly class PayloadBudget
{
    public function __construct(
        public int $maxBytes = 65_536,
        public int $maxDepth = 8,
        public int $maxArrayItems = 100,
        public int $maxStringLength = 4096,
        public int $maxNodes = 2000,
    ) {
        if (min($this->maxBytes, $this->maxDepth, $this->maxArrayItems, $this->maxStringLength, $this->maxNodes) < 1) {
            throw new InvalidArgumentException('Payload budget values must be positive.');
        }
    }
}
