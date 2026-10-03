<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Contracts\Resolution;

use Dotswan\EagleEye\Data\Subject;

interface SubjectResolverInterface
{
    public function resolve(object $subject): Subject;
}
