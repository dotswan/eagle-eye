<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Filter\Rules;

use Dotswan\EagleEye\Contracts\Filter\FilterRuleInterface;
use Dotswan\EagleEye\Data\ActivityDraft;
use Dotswan\EagleEye\Enums\FilterDecision;
use Dotswan\EagleEye\Filter\CompiledFilterMatcher;

abstract class StringListRule implements FilterRuleInterface
{
    private readonly CompiledFilterMatcher $only;

    private readonly CompiledFilterMatcher $except;

    /**
     * @param list<string> $only
     * @param list<string> $except
     */
    public function __construct(array $only = [], array $except = [])
    {
        $this->only = new CompiledFilterMatcher($only);
        $this->except = new CompiledFilterMatcher($except);
    }

    public function decide(ActivityDraft $draft): FilterDecision
    {
        $values = $this->values($draft);

        foreach ($values as $value) {
            if ($this->except->matches($value)) {
                return FilterDecision::Deny;
            }
        }

        if ($this->only->isEmpty()) {
            return FilterDecision::Abstain;
        }

        foreach ($values as $value) {
            if ($this->only->matches($value)) {
                return FilterDecision::Allow;
            }
        }

        return [] === $values ? FilterDecision::Abstain : FilterDecision::Deny;
    }

    /** @return list<string> */
    abstract protected function values(ActivityDraft $draft): array;
}
