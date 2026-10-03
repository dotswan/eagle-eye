<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Redaction;

final class PathMatcher
{
    public static function matches(string $pattern, string $path): bool
    {
        if (mb_strlen($pattern, '8bit') > 1024 || mb_strlen($path, '8bit') > 8192) {
            return false;
        }

        $patterns = explode('.', $pattern);
        $segments = explode('.', $path);

        if (count($patterns) > 32 || count($segments) > 64) {
            return false;
        }

        return self::matchSegments($patterns, $segments, 0, 0);
    }

    /**
     * @param list<string> $patterns
     * @param list<string> $segments
     */
    private static function matchSegments(array $patterns, array $segments, int $patternIndex, int $segmentIndex): bool
    {
        while ($patternIndex < count($patterns)) {
            $pattern = $patterns[$patternIndex];

            if ('**' === $pattern) {
                if ($patternIndex === count($patterns) - 1) {
                    return true;
                }
                $counter = count($segments);

                for ($index = $segmentIndex; $index <= $counter; $index++) {
                    if (self::matchSegments($patterns, $segments, $patternIndex + 1, $index)) {
                        return true;
                    }
                }

                return false;
            }

            if ($segmentIndex >= count($segments) || ('*' !== $pattern && $pattern !== $segments[$segmentIndex])) {
                return false;
            }

            $patternIndex++;
            $segmentIndex++;
        }

        return $segmentIndex === count($segments);
    }
}
