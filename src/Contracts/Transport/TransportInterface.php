<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Contracts\Transport;

use Dotswan\EagleEye\Data\ActivityEntry;

interface TransportInterface
{
    /** @param list<ActivityEntry> $entries */
    public function send(array $entries): void;
}
