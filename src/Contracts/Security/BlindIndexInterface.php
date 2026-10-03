<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Contracts\Security;

interface BlindIndexInterface
{
    public function hash(string $value): string;
}
