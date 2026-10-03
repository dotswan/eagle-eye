<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Redaction\Strategies;

use Dotswan\EagleEye\Contracts\Redaction\RedactionStrategyInterface;
use Dotswan\EagleEye\Contracts\Security\BlindIndexInterface;

final readonly class HashStrategy implements RedactionStrategyInterface
{
    public function __construct(private BlindIndexInterface $blindIndex)
    {
    }

    public function apply(mixed $value, string $path): mixed
    {
        $text = is_string($value) ? $value : (string) json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE);

        return $this->blindIndex->hash($text);
    }
}
