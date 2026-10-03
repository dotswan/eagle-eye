<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Data;

use DateTimeImmutable;
use LengthException;

/** @internal */
final class EnvelopeBudget
{
    public const int MAX_ENTRY_BYTES = 131072;

    public const int MAX_PAYLOAD_BYTES = 65536;

    public const int MAX_STRING_BYTES = 8192;

    public const int MAX_NODES = 2000;

    public const int MAX_BATCH_NODES = 200000;

    public const int MAX_SECRETS = 50;

    public const int MAX_TAGS = 50;

    public const int MAX_BATCH_BYTES = 1048576;

    public const int MAX_BUFFER_BYTES = 2097152;

    public const int MAX_AGGREGATE_GROUPS = 1000;

    public const int MAX_SERIES_BUCKETS = 400;

    public const int MAX_SERIES_POINTS = 5000;

    public const int MAX_TAG_RESULT_BYTES = 65536;

    /** @var array<string, int> */
    private const array FIELD_LIMITS = [
        'id'           => 64, 'action' => 191, 'title' => 255, 'summary' => 255,
        'actor_type'   => 32, 'actor_id' => 64, 'actor_label' => 255, 'actor_url' => 2048,
        'subject_type' => 64, 'subject_id' => 64, 'subject_label' => 255, 'subject_class' => 255, 'subject_url' => 2048,
        'trace_id'     => 32, 'correlation_id' => 64, 'causation_id' => 64, 'parent_span_id' => 16,
        'ip'           => 45, 'user_agent' => 255, 'method' => 8, 'url' => 2048, 'route' => 191,
        'environment'  => 32, 'hostname' => 255,
    ];

    public static function size(ActivityEntry $entry): int
    {
        if (count($entry->secrets) > self::MAX_SECRETS || count($entry->tags) > self::MAX_TAGS) {
            throw new LengthException('Activity envelope exceeds reference or tag limits.');
        }

        if ($entry->loggedAt::class !== DateTimeImmutable::class || ($entry->createdAt instanceof DateTimeImmutable && $entry->createdAt::class !== DateTimeImmutable::class)) {
            throw new LengthException('Activity envelope contains unsupported date objects.');
        }

        $storage = $entry->toStorage();
        self::assertFieldLimits($storage);
        self::sizeOfArray($entry->payload, self::MAX_PAYLOAD_BYTES, self::MAX_STRING_BYTES);
        $fields = get_object_vars($entry);
        $fields['actor'] = $entry->actor?->toArray();
        $fields['subject'] = $entry->subject?->toArray();
        $fields['trace'] = $entry->trace->toArray();
        $fields['request'] = $entry->request?->toArray();
        $fields['channel'] = $entry->channel->value;
        $fields['severity'] = $entry->severity->value;
        $fields['loggedAt'] = null;
        $fields['createdAt'] = null;
        $fields['secrets'] = [];

        foreach ($entry->secrets as $path => $secret) {
            $fields['secrets'][$path] = $secret->toArray();
        }

        $bytes = 4096;
        $nodes = self::MAX_NODES;
        self::inspect($fields, $bytes, $nodes, 0, self::MAX_ENTRY_BYTES, self::MAX_STRING_BYTES);
        $size = mb_strlen(json_encode($storage, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR), '8bit');

        if ($size > self::MAX_ENTRY_BYTES) {
            throw new LengthException('Activity envelope exceeds its encoded byte limit.');
        }

        return $size;
    }

    /** @param list<ActivityEntry> $entries */
    public static function batchSize(array $entries): int
    {
        if (count($entries) > 100) {
            throw new LengthException('Activity delivery batch exceeds its entry limit.');
        }

        $bytes = 2;

        foreach ($entries as $index => $entry) {
            $bytes += self::size($entry) + (0 === $index ? 0 : 1);

            if ($bytes > self::MAX_BATCH_BYTES) {
                throw new LengthException('Activity delivery batch exceeds its byte limit.');
            }
        }

        return $bytes;
    }

    /** @param array<array-key, mixed> $row */
    public static function sizeOfArray(
        array $row,
        int $maxBytes = self::MAX_ENTRY_BYTES,
        int $maxStringBytes = self::MAX_STRING_BYTES,
        int $maxNodes = self::MAX_NODES,
    ): int {
        if ($maxBytes < 1 || $maxBytes > self::MAX_BATCH_BYTES || $maxStringBytes < 1 || $maxStringBytes > $maxBytes || $maxNodes < 1 || $maxNodes > self::MAX_BATCH_NODES) {
            throw new LengthException('Activity serialization limits must be finite and internally consistent.');
        }

        $bytes = 0;
        $nodes = $maxNodes;
        self::inspect($row, $bytes, $nodes, 0, $maxBytes, $maxStringBytes);

        $size = mb_strlen(json_encode($row, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR), '8bit');

        if ($size > $maxBytes) {
            throw new LengthException('Activity envelope exceeds its encoded byte limit.');
        }

        return $size;
    }

    private static function inspect(mixed $value, int &$bytes, int &$nodes, int $depth, int $maxBytes, int $maxStringBytes): void
    {
        if (--$nodes < 0 || $depth > 16) {
            throw new LengthException('Activity envelope exceeds structural limits.');
        }

        if (is_string($value)) {
            if (! mb_check_encoding($value, 'UTF-8') || mb_strlen($value, '8bit') > $maxStringBytes) {
                throw new LengthException('Activity envelope contains an oversized string.');
            }

            $bytes += mb_strlen(json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR), '8bit');
        } elseif (is_int($value) || is_bool($value) || null === $value || (is_float($value) && is_finite($value))) {
            $bytes += mb_strlen(json_encode($value, JSON_THROW_ON_ERROR), '8bit');
        } elseif (is_array($value)) {
            if (count($value) > $nodes) {
                throw new LengthException('Activity envelope contains too many values.');
            }

            $bytes += 2;

            foreach ($value as $key => $item) {
                self::inspect((string) $key, $bytes, $nodes, $depth + 1, $maxBytes, $maxStringBytes);
                $bytes += 2;
                self::inspect($item, $bytes, $nodes, $depth + 1, $maxBytes, $maxStringBytes);
            }
        } else {
            throw new LengthException('Activity envelope contains an unsupported value.');
        }

        if ($bytes > $maxBytes) {
            throw new LengthException('Activity envelope exceeds its admitted byte limit.');
        }
    }

    /** @param array<string, mixed> $storage */
    private static function assertFieldLimits(array $storage): void
    {
        foreach (self::FIELD_LIMITS as $field => $limit) {
            $value = $storage[$field] ?? null;

            if (is_string($value) && (! mb_check_encoding($value, 'UTF-8') || mb_strlen($value, '8bit') > $limit)) {
                throw new LengthException("Activity envelope field [{$field}] exceeds its storage limit.");
            }
        }
    }
}
