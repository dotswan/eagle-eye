<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Contracts\Redaction;

interface RedactionStrategyInterface
{
    public function apply(mixed $value, string $path): mixed;
}
