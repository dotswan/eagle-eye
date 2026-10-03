<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Contracts\Resolution;

use Dotswan\EagleEye\Data\Actor;
use Dotswan\EagleEye\Data\Subject;

interface ActivityIdentityResolverInterface
{
    /**
     * @param array<string, Actor> $actors
     * @return array<string, Actor>
     */
    public function resolveActors(array $actors): array;

    /**
     * @param array<string, Subject> $subjects
     * @return array<string, Subject>
     */
    public function resolveSubjects(array $subjects): array;
}
