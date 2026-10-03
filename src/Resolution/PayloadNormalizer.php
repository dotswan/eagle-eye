<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Resolution;

use BackedEnum;
use Closure;
use DateTimeInterface;
use Dotswan\EagleEye\Contracts\Resolution\PayloadNormalizerInterface;
use Dotswan\EagleEye\Data\PayloadBudget;
use Illuminate\Http\UploadedFile;
use JsonSerializable;
use Stringable;
use Throwable;

final readonly class PayloadNormalizer implements PayloadNormalizerInterface
{
    private const string TRUNCATED = '[truncated]';

    public function __construct(
        private PayloadBudget $budget = new PayloadBudget(),
        private bool $serializeObjects = true,
    ) {
    }

    public function normalize(mixed $value): mixed
    {
        $remainingNodes = $this->budget->maxNodes;
        $markerBytes = is_array($value) && $this->budget->maxBytes >= 26 ? 24 : 0;
        $remainingBytes = $this->budget->maxBytes - $markerBytes;
        $truncated = false;
        $normalized = $this->normalizeValue($value, 0, $remainingNodes, $remainingBytes, $truncated);

        if ($truncated && is_array($normalized) && $markerBytes > 0) {
            if (array_is_list($normalized) && array_is_list($value)) {
                $normalized[] = self::TRUNCATED;
            } else {
                $normalized['__truncated'] = true;
            }
        }

        return $normalized;
    }

    private function normalizeValue(mixed $value, int $depth, int &$remainingNodes, int &$remainingBytes, bool &$truncated): mixed
    {
        if ($remainingNodes-- <= 0) {
            $truncated = true;

            return $this->retainScalar(self::TRUNCATED, $remainingBytes, $truncated);
        }

        if ($depth >= $this->budget->maxDepth) {
            $truncated = true;

            return $this->retainScalar('[max-depth]', $remainingBytes, $truncated);
        }

        if (null === $value || is_bool($value) || is_int($value)) {
            return $this->retainScalar($value, $remainingBytes, $truncated);
        }

        if (is_float($value)) {
            return $this->retainScalar(is_finite($value) ? $value : '[non-finite]', $remainingBytes, $truncated);
        }

        if (is_string($value)) {
            $limit = min($this->budget->maxStringLength, max(0, intdiv($remainingBytes - 2, 6)));

            return $this->retainScalar($this->normalizeString($value, $limit, $truncated), $remainingBytes, $truncated);
        }

        if (is_array($value)) {
            return $this->normalizeIterable($value, $depth, $remainingNodes, $remainingBytes, $truncated);
        }

        if (is_resource($value)) {
            return $this->retainScalar('[resource:'.get_resource_type($value).']', $remainingBytes, $truncated);
        }

        if ($value instanceof BackedEnum) {
            return $this->normalizeValue($value->value, $depth, $remainingNodes, $remainingBytes, $truncated);
        }

        if ($value instanceof DateTimeInterface) {
            return $this->normalizeValue($value->format(DateTimeInterface::ATOM), $depth, $remainingNodes, $remainingBytes, $truncated);
        }

        if ($value instanceof UploadedFile) {
            return $this->normalizeIterable([
                'name'  => $value->getClientOriginalName(),
                'mime'  => $value->getClientMimeType(),
                'size'  => $value->getSize(),
                'error' => $value->getError(),
            ], $depth, $remainingNodes, $remainingBytes, $truncated);
        }

        if ($value instanceof Closure) {
            return $this->retainScalar('[closure]', $remainingBytes, $truncated);
        }

        if ($this->serializeObjects && $value instanceof JsonSerializable) {
            return $this->normalizeValue($value->jsonSerialize(), $depth, $remainingNodes, $remainingBytes, $truncated);
        }

        if ($this->serializeObjects && $value instanceof Stringable) {
            try {
                return $this->normalizeValue((string) $value, $depth, $remainingNodes, $remainingBytes, $truncated);
            } catch (Throwable) {
                return $this->retainScalar('[object]', $remainingBytes, $truncated);
            }
        }

        if (is_object($value)) {
            return $this->retainScalar('[object]', $remainingBytes, $truncated);
        }

        return $this->retainScalar('[unsupported]', $remainingBytes, $truncated);
    }

    private function retainScalar(string|int|float|bool|null $value, int &$remainingBytes, bool &$truncated): string|int|float|bool|null
    {
        $bytes = mb_strlen(json_encode($value, JSON_THROW_ON_ERROR), '8bit');

        if ($bytes > $remainingBytes) {
            $truncated = true;
            $remainingBytes--;

            return 0;
        }

        $remainingBytes -= $bytes;

        return $value;
    }

    /**
     * @param array<array-key, mixed> $values
     * @return array<array-key, mixed>|int
     */
    private function normalizeIterable(array $values, int $depth, int &$remainingNodes, int &$remainingBytes, bool &$truncated): array|int
    {
        if ($remainingBytes < 2) {
            $truncated = true;
            $remainingBytes--;

            return 0;
        }

        $remainingBytes -= 2;
        $normalized = [];
        $count = 0;

        foreach ($values as $key => $value) {
            if ($count++ >= $this->budget->maxArrayItems || $remainingNodes <= 0 || $remainingBytes < 2) {
                $truncated = true;

                break;
            }

            if (is_string($key) && (mb_strlen($key, '8bit') > $this->budget->maxStringLength || 1 !== preg_match('//u', $key))) {
                $truncated = true;
                $remainingNodes--;

                continue;
            }

            $keyBytes = mb_strlen((string) $key, '8bit') * 6 + 4;

            if ($keyBytes + 1 > $remainingBytes) {
                $truncated = true;

                break;
            }

            $remainingBytes -= mb_strlen(json_encode((string) $key, JSON_THROW_ON_ERROR), '8bit') + 2;
            $normalized[$key] = $this->normalizeValue($value, $depth + 1, $remainingNodes, $remainingBytes, $truncated);
        }

        return $normalized;
    }

    private function normalizeString(string $value, int $limit, bool &$truncated): string
    {
        $oversized = mb_strlen($value, '8bit') > $limit;
        $value = mb_strcut($value, 0, $limit, 'UTF-8');

        if (1 !== preg_match('//u', $value)) {
            $encoded = json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE);
            $decoded = false === $encoded ? null : json_decode($encoded, true);
            $value = is_string($decoded) ? $decoded : '[invalid-utf8]';
        }

        if (! $oversized && mb_strlen($value, '8bit') <= $limit) {
            return $value;
        }

        $truncated = true;

        if ($limit <= 3) {
            return str_repeat('.', $limit);
        }

        return mb_strcut($value, 0, $limit - 3, 'UTF-8').'...';
    }
}
