<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Data;

/** @internal Transport diagnostics value used by batch writers and `activity-log:doctor`. */
final readonly class WriteFailure
{
    public function __construct(
        public string $entryId,
        public string $message,
        public ?string $code = null,
    ) {
    }
}
