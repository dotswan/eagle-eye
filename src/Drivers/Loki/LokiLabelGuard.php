<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Drivers\Loki;

use Dotswan\EagleEye\Exceptions\InvalidLabelException;

/**
 * Enforces the stream-label allowlist at boot. Loki indexes only labels;
 * every disallowed label is a cardinality bomb for a shared cluster.
 *
 * @internal
 */
final class LokiLabelGuard
{
    public const array ALLOWED = ['app', 'env', 'channel', 'severity', 'service', 'tenant'];

    private const int MAX_STRUCTURED_METADATA = 128;

    /**
     * @param array<string, string> $configured
     * @param list<string>         $structuredMetadata
     *
     * @throws InvalidLabelException
     */
    public function validate(array $configured, array $structuredMetadata): void
    {
        foreach (array_keys($configured) as $label) {
            if (! in_array($label, self::ALLOWED, true)) {
                throw new InvalidLabelException(sprintf(
                    'Eagle Eye Loki label [%s] is not allowed. Stream labels are indexed by Loki and must stay bounded. Allowed: %s. High-cardinality values belong in structured metadata.',
                    $label,
                    implode(', ', self::ALLOWED),
                ));
            }
        }

        if (count($structuredMetadata) > self::MAX_STRUCTURED_METADATA) {
            throw new InvalidLabelException(sprintf(
                'Eagle Eye Loki structured metadata holds %d keys; Loki allows at most %d per line.',
                count($structuredMetadata),
                self::MAX_STRUCTURED_METADATA,
            ));
        }

        foreach ($structuredMetadata as $key) {
            if (in_array($key, self::ALLOWED, true)) {
                throw new InvalidLabelException(sprintf(
                    'Eagle Eye Loki structured metadata key [%s] collides with a stream label name.',
                    $key,
                ));
            }
        }
    }
}
