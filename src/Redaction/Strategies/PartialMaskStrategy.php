<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Redaction\Strategies;

use Dotswan\EagleEye\Contracts\Redaction\RedactionStrategyInterface;

final readonly class PartialMaskStrategy implements RedactionStrategyInterface
{
    private const int MAX_EMAIL_LENGTH = 254;

    public function apply(mixed $value, string $path): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        return $this->maskString($value);
    }

    public function isEmail(string $value): bool
    {
        return str_contains($value, '@')
            && mb_strlen($value, '8bit') <= self::MAX_EMAIL_LENGTH
            && false !== filter_var($value, FILTER_VALIDATE_EMAIL, FILTER_FLAG_EMAIL_UNICODE);
    }

    private function maskString(string $value): string
    {
        if ('' === $value) {
            return $value;
        }

        if ($this->isEmail($value)) {
            return $this->maskEmail($value);
        }

        $digits = preg_replace('/[\s\-]/', '', $value);

        if (is_string($digits) && preg_match('/^\+?\d{7,15}$/', $digits)) {
            return $this->maskPhone($digits);
        }

        if (mb_strlen($value) <= 2) {
            return '**';
        }

        return preg_replace_callback(
            '/\S+/u',
            static fn (array $match): string => mb_substr($match[0], 0, 1).'***',
            $value,
        ) ?? '***';
    }

    private function maskEmail(string $address): string
    {
        $at = mb_strpos($address, '@');

        if (false === $at || 0 === $at) {
            return '***';
        }

        return mb_substr($address, 0, 1).'***'.mb_substr($address, $at);
    }

    private function maskPhone(string $digits): string
    {
        $prefix = str_starts_with($digits, '+') ? '+' : '';
        $digits = mb_ltrim($digits, '+');
        $length = mb_strlen($digits);
        $keep = min(3, max(1, $length - 2));

        return $prefix.mb_substr($digits, 0, $keep).str_repeat('*', $length - $keep - 2).mb_substr($digits, -2);
    }
}
