<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Drivers;

/**
 * Scope-free readers for the `eagle-eye.drivers.*` configuration tree.
 *
 * Driver factories are registered as manager closures, which recent Laravel
 * releases bind to the manager instance. The readers therefore live on a
 * dedicated class instead of the service provider so factories can use them
 * without depending on the closure's bound scope.
 */
final class DriverSettings
{
    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public static function array(array $settings, string $key): array
    {
        $value = $settings[$key] ?? [];

        return is_array($value) ? $value : [];
    }

    /** @param array<string, mixed> $settings */
    public static function stringOrNull(array $settings, string $key): ?string
    {
        $value = $settings[$key] ?? null;

        return is_string($value) && '' !== $value ? $value : null;
    }
}
