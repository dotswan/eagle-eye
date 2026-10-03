<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Contracts\Security;

use Dotswan\EagleEye\Data\ActivityEntry;

interface DurableRevealAuditInterface
{
    public function record(ActivityEntry $entry, string $path, ?object $viewer = null): void;
}
