<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Contracts\Security;

use Dotswan\EagleEye\Data\ActivityEntry;

interface LogAccessPolicyInterface
{
    public function canView(?object $viewer = null): bool;

    public function canReveal(ActivityEntry $entry, string $path, ?object $viewer = null): bool;
}
