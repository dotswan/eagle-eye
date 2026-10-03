<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Filter\Rules;

use Closure;
use Dotswan\EagleEye\Contracts\Filter\FilterRuleInterface;
use Dotswan\EagleEye\Data\ActivityDraft;
use Dotswan\EagleEye\Enums\FilterDecision;

final readonly class ClosureRule implements FilterRuleInterface
{
    /** @param Closure(ActivityDraft): mixed $decider */
    public function __construct(private Closure $decider)
    {
    }

    public function decide(ActivityDraft $draft): FilterDecision
    {
        $decision = ($this->decider)($draft);

        return $decision instanceof FilterDecision ? $decision : FilterDecision::Abstain;
    }
}
