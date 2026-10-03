<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Capture;

use Closure;

final class CaptureSuppression
{
    private int $depth = 0;

    public function active(): bool
    {
        return $this->depth > 0;
    }

    /**
     * @template T
     * @param Closure(): T $callback
     * @return T
     */
    public function run(Closure $callback): mixed
    {
        $this->depth++;

        try {
            return $callback();
        } finally {
            $this->depth--;
        }
    }
}
