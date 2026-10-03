<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Contracts\Security;

use Dotswan\EagleEye\Data\SecretRef;

interface CipherInterface
{
    public function encrypt(string $plaintext): SecretRef;

    public function decrypt(SecretRef $secret): string;
}
