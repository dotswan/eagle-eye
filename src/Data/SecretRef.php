<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Data;

final readonly class SecretRef
{
    public function __construct(
        public string $algorithm,
        public string $keyFingerprint,
        public string $ciphertext,
        public ?string $blindIndex = null,
    ) {
    }

    /** @return array{alg: string, key_fingerprint: string, ciphertext: string, bi: ?string} */
    public function toArray(): array
    {
        return [
            'alg'             => $this->algorithm,
            'key_fingerprint' => $this->keyFingerprint,
            'ciphertext'      => $this->ciphertext,
            'bi'              => $this->blindIndex,
        ];
    }

    /** @param array{alg: string, key_fingerprint: string, ciphertext: string, bi?: ?string} $data */
    public static function fromArray(array $data): self
    {
        return new self(
            algorithm: $data['alg'],
            keyFingerprint: $data['key_fingerprint'],
            ciphertext: $data['ciphertext'],
            blindIndex: $data['bi'] ?? null,
        );
    }
}
