<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Tests\Support;

use Dotswan\EagleEye\Filter\CompiledFilterMatcher;
use Illuminate\Support\Str;

final readonly class NaiveFilterMatcher
{
    /** @param list<string> $patterns */
    public function __construct(private array $patterns)
    {
    }

    public function matches(string $value): bool
    {
        foreach ($this->patterns as $pattern) {
            if (CompiledFilterMatcher::isRegex($pattern)) {
                if (1 === preg_match($pattern, $value)) {
                    return true;
                }
            } elseif (str_contains($pattern, '*')) {
                if (Str::is($pattern, $value)) {
                    return true;
                }
            } elseif ($pattern === $value) {
                return true;
            }
        }

        return false;
    }
}
