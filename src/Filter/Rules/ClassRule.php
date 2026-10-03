<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Filter\Rules;

use Dotswan\EagleEye\Contracts\Filter\FilterRuleInterface;
use Dotswan\EagleEye\Data\ActivityDraft;
use Dotswan\EagleEye\Enums\FilterDecision;
use Dotswan\EagleEye\Filter\CompiledFilterMatcher;

final readonly class ClassRule implements FilterRuleInterface
{
    private CompiledFilterMatcher $only;

    private CompiledFilterMatcher $except;

    /**
     * @param list<string> $onlyList
     * @param list<string> $exceptList
     */
    public function __construct(
        private array $onlyList = [],
        private array $exceptList = [],
    ) {
        $this->only = new CompiledFilterMatcher($onlyList);
        $this->except = new CompiledFilterMatcher($exceptList);
    }

    public function decide(ActivityDraft $draft): FilterDecision
    {
        $class = $draft->subject?->className;

        if (null === $class || '' === $class) {
            return FilterDecision::Abstain;
        }

        if ($this->matches($class, $this->exceptList, $this->except)) {
            return FilterDecision::Deny;
        }

        if ([] === $this->onlyList) {
            return FilterDecision::Abstain;
        }

        return $this->matches($class, $this->onlyList, $this->only)
            ? FilterDecision::Allow
            : FilterDecision::Deny;
    }

    /** @param list<string> $patterns */
    private function matches(string $class, array $patterns, CompiledFilterMatcher $matcher): bool
    {
        foreach ($patterns as $pattern) {
            if ((class_exists($pattern) || interface_exists($pattern)) && is_a($class, $pattern, true)) {
                return true;
            }
        }

        return $matcher->matches($class);
    }
}
