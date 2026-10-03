<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Contracts\Filter;

use Dotswan\EagleEye\Data\ActivityDraft;
use Dotswan\EagleEye\Enums\FilterDecision;

interface FilterRuleInterface
{
    public function decide(ActivityDraft $draft): FilterDecision;
}
