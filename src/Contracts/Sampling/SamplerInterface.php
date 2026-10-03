<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Contracts\Sampling;

use Dotswan\EagleEye\Data\ActivityDraft;

interface SamplerInterface
{
    public function passes(ActivityDraft $draft): bool;
}
