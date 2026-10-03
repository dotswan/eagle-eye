<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Security;

use Dotswan\EagleEye\Contracts\Security\BlindIndexInterface;

final readonly class HmacBlindIndex implements BlindIndexInterface
{
    public function __construct(private string $key)
    {
    }

    public function hash(string $value): string
    {
        return hash_hmac('sha256', $value, $this->key);
    }
}
