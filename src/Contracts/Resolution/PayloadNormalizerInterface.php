<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Contracts\Resolution;

interface PayloadNormalizerInterface
{
    public function normalize(mixed $value): mixed;
}
