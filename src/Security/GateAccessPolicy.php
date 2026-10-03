<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Security;

use Dotswan\EagleEye\Contracts\Security\LogAccessPolicyInterface;
use Dotswan\EagleEye\Data\ActivityEntry;
use Illuminate\Contracts\Auth\Access\Gate;

final readonly class GateAccessPolicy implements LogAccessPolicyInterface
{
    public function __construct(
        private Gate $gate,
    ) {
    }

    public function canView(?object $viewer = null): bool
    {
        return null === $viewer
            ? $this->gate->allows('viewActivityLog')
            : $this->gate->forUser($viewer)->allows('viewActivityLog');
    }

    public function canReveal(ActivityEntry $entry, string $path, ?object $viewer = null): bool
    {
        $arguments = [$entry, $path];

        return null === $viewer
            ? $this->gate->allows('revealActivityLog', $arguments)
            : $this->gate->forUser($viewer)->allows('revealActivityLog', $arguments);
    }
}
