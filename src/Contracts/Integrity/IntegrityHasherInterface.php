<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Contracts\Integrity;

use Dotswan\EagleEye\Data\ActivityEntry;

interface IntegrityHasherInterface
{
    public function hash(ActivityEntry $entry, ?string $previousHash = null): string;
}
