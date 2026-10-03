<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Redaction\Strategies;

use Dotswan\EagleEye\Contracts\Redaction\RedactionStrategyInterface;
use Dotswan\EagleEye\Contracts\Security\BlindIndexInterface;
use Dotswan\EagleEye\Contracts\Security\CipherInterface;
use Dotswan\EagleEye\Data\SecretRef;
use Dotswan\EagleEye\Data\PayloadBudget;
use Dotswan\EagleEye\Resolution\PayloadNormalizer;

final readonly class EncryptStrategy implements RedactionStrategyInterface
{
    public function __construct(
        private CipherInterface $cipher,
        private BlindIndexInterface $blindIndex,
    ) {
    }

    /**
     * Produces a {@see SecretRef}; the coordinating redactor replaces the
     * payload value with an `__secret` marker and stores the reference.
     */
    public function apply(mixed $value, string $path): mixed
    {
        if (is_object($value)) {
            return '[omitted]';
        }

        $normalized = (new PayloadNormalizer(new PayloadBudget(maxBytes: 4096, maxStringLength: 4096)))->normalize($value);

        if ($normalized !== $value) {
            return '[omitted]';
        }

        $text = is_string($normalized) ? $normalized : json_encode($normalized, JSON_THROW_ON_ERROR);

        $secret = $this->cipher->encrypt($text);

        return new SecretRef(
            algorithm: $secret->algorithm,
            keyFingerprint: $secret->keyFingerprint,
            ciphertext: $secret->ciphertext,
            blindIndex: $this->blindIndex->hash($text),
        );
    }
}
