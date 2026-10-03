<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Transport;

use Closure;

final readonly class FlushDeferral
{
    /** @param Closure(Closure(): void): void $afterCommit */
    public function __construct(
        private Closure $afterCommit,
    ) {
    }

    /**
     * Registers a commit callback without any class-level state: Laravel discards
     * callbacks on rollback (including the level-0 rollback path that skips the
     * rollback callbacks), so a stranded flag could silently stop delivery.
     *
     * @param Closure(): void $deliver
     */
    public function schedule(Closure $deliver): void
    {
        ($this->afterCommit)(static function () use ($deliver): void {
            $deliver();
        });
    }
}
