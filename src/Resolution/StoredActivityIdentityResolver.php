<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Resolution;

use Dotswan\EagleEye\Contracts\Resolution\ActivityIdentityResolverInterface;

final readonly class StoredActivityIdentityResolver implements ActivityIdentityResolverInterface
{
    public function resolveActors(array $actors): array
    {
        return $actors;
    }

    public function resolveSubjects(array $subjects): array
    {
        return $subjects;
    }
}
