<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Filter;

final class CompiledFilterMatcher
{
    /** @var array<string, true> */
    private array $exact = [];

    private ?string $wildcardRegex = null;

    private ?string $rawRegex = null;

    /** @param list<string> $patterns */
    public function __construct(array $patterns)
    {
        $wildcards = [];
        $raws = [];

        foreach ($patterns as $pattern) {
            if ('' === $pattern) {
                continue;
            }

            if (self::isRegex($pattern)) {
                $raws[] = '(?:'.mb_substr($pattern, 1, -1).')';
            } elseif (str_contains($pattern, '*')) {
                $wildcards[] = implode('.*', array_map(preg_quote(...), explode('*', $pattern)));
            } else {
                $this->exact[$pattern] = true;
            }
        }

        if ([] !== $wildcards) {
            $this->wildcardRegex = '~\A(?:'.implode('|', $wildcards).')\z~';
        }

        if ([] !== $raws) {
            $this->rawRegex = '~'.implode('|', $raws).'~';
        }
    }

    public static function isRegex(string $pattern): bool
    {
        return mb_strlen($pattern) > 2 && '/' === $pattern[0] && '/' === $pattern[mb_strlen($pattern) - 1];
    }

    public function matches(string $value): bool
    {
        if (isset($this->exact[$value])) {
            return true;
        }

        if (null !== $this->wildcardRegex && 1 === preg_match($this->wildcardRegex, $value)) {
            return true;
        }

        return null !== $this->rawRegex && 1 === preg_match($this->rawRegex, $value);
    }

    public function isEmpty(): bool
    {
        return [] === $this->exact && null === $this->wildcardRegex && null === $this->rawRegex;
    }
}
