<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Capture;

final class ExceptionDeduplicator
{
    private const int MAX_FINGERPRINTS = 256;

    /** @var array<string, true> */
    private array $seen = [];

    public function isDuplicate(string $fingerprint): bool
    {
        $key = hash('sha256', $fingerprint);

        if (isset($this->seen[$key])) {
            return true;
        }

        if (count($this->seen) >= self::MAX_FINGERPRINTS) {
            array_shift($this->seen);
        }

        $this->seen[$key] = true;

        return false;
    }
}
