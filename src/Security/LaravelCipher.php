<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Security;

use Dotswan\EagleEye\Contracts\Security\CipherInterface;
use Dotswan\EagleEye\Data\SecretRef;
use Illuminate\Contracts\Encryption\Encrypter;
use UnexpectedValueException;

final readonly class LaravelCipher implements CipherInterface
{
    public function __construct(
        private Encrypter $encrypter,
        private string $key,
        private string $cipher,
    ) {
    }

    public function encrypt(string $plaintext): SecretRef
    {
        return new SecretRef(
            algorithm: 'laravel:'.$this->cipher,
            keyFingerprint: mb_substr(hash('sha256', $this->key), 0, 8),
            ciphertext: $this->encrypter->encrypt($plaintext, false),
        );
    }

    public function decrypt(SecretRef $secret): string
    {
        $plaintext = $this->encrypter->decrypt($secret->ciphertext, false);

        if (! is_string($plaintext)) {
            throw new UnexpectedValueException('Decrypted Eagle Eye secret must be a string.');
        }

        return $plaintext;
    }
}
