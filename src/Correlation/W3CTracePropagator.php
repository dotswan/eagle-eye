<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Correlation;

use Dotswan\EagleEye\Contracts\Correlation\TracePropagatorInterface;
use Dotswan\EagleEye\Data\TraceContext;
use Illuminate\Support\Str;

final class W3CTracePropagator implements TracePropagatorInterface
{
    private const int MAX_CARRIER_HEADERS = 128;

    /** @param array<string, mixed> $carrier */
    public function extract(array $carrier): TraceContext
    {
        $traceparent = $this->header($carrier, 'traceparent', 55) ?? '';
        $correlationId = $this->header($carrier, 'x-correlation-id', 128) ?? '';
        $correlationId = 1 === preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/', $correlationId)
            ? $correlationId
            : (string) Str::ulid();

        if (1 === preg_match('/\A00-([a-f0-9]{32})-([a-f0-9]{16})-[a-f0-9]{2}\z/', $traceparent, $matches)
            && str_repeat('0', 32) !== $matches[1]
            && str_repeat('0', 16) !== $matches[2]) {
            return new TraceContext($matches[1], $correlationId, null, $matches[2]);
        }

        return new TraceContext(bin2hex(random_bytes(16)), $correlationId);
    }

    /** @param array<string, mixed> $carrier */
    private function header(array $carrier, string $name, int $maxBytes): ?string
    {
        $visited = 0;

        foreach ($carrier as $key => $value) {
            if ($visited++ >= self::MAX_CARRIER_HEADERS) {
                return null;
            }

            if (mb_strlen($key, '8bit') !== mb_strlen($name, '8bit') || 0 !== strcasecmp($key, $name)) {
                continue;
            }

            return is_string($value) && mb_strlen($value, '8bit') <= $maxBytes ? $value : null;
        }

        return null;
    }

    public function inject(TraceContext $context, array $carrier): array
    {
        $carrier['traceparent'] = sprintf(
            '00-%s-%s-01',
            $context->traceId,
            $context->parentSpanId ?? bin2hex(random_bytes(8)),
        );
        $carrier['x-correlation-id'] = $context->correlationId;

        return $carrier;
    }
}
