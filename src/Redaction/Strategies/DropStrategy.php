<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Redaction\Strategies;

use Dotswan\EagleEye\Contracts\Redaction\RedactionStrategyInterface;

final readonly class DropStrategy implements RedactionStrategyInterface
{
    public function apply(mixed $value, string $path): mixed
    {
        return null;
    }
}
