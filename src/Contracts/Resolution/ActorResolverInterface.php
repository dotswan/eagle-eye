<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Contracts\Resolution;

use Dotswan\EagleEye\Data\Actor;

interface ActorResolverInterface
{
    public function resolve(): ?Actor;
}
