<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Redaction\Strategies;

use Dotswan\EagleEye\Contracts\Redaction\RedactionStrategyInterface;

final readonly class MaskStrategy implements RedactionStrategyInterface
{
    public function __construct(
        private string $char = '*',
        private int $keepPrefix = 0,
        private int $keepSuffix = 0,
    ) {
    }

    public function apply(mixed $value, string $path): mixed
    {
        $text = is_string($value) ? $value : (string) json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE);
        $length = mb_strlen($text);

        if (0 === $length) {
            return $text;
        }

        $prefix = min($this->keepPrefix, $length);
        $suffix = min($this->keepSuffix, $length - $prefix);
        $masked = max(0, $length - $prefix - $suffix);

        return mb_substr($text, 0, $prefix)
            .str_repeat($this->char, $masked)
            .($suffix > 0 ? mb_substr($text, -$suffix) : '');
    }
}
