<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Drivers\Loki;

use Dotswan\EagleEye\Data\Actor;
use Dotswan\EagleEye\Data\Subject;
use Dotswan\EagleEye\Data\RequestSnapshot;
use DateTimeImmutable;
use DateTimeZone;
use Dotswan\EagleEye\Data\ActivityEntry;

/**
 * Turns entries into the identical three-tier representation every Loki
 * writer ships: bounded stream labels, high-cardinality structured metadata,
 * and the canonical JSON body.
 *
 * @internal
 */
final readonly class LokiLineFormatter
{
    public const string STDOUT_MARKER = 'eagle_eye';

    public const array METADATA_KEYS = [
        'id', 'trace_id', 'correlation_id', 'causation_id',
        'actor_type', 'actor_id', 'subject_type', 'subject_id',
        'action', 'route', 'method', 'status_code', 'duration_ms', 'tags',
    ];

    /**
     * @param array<string, string> $staticLabels boot-validated by LokiLabelGuard
     * @param list<string> $dynamicLabels subset of ['env', 'channel', 'severity']
     */
    public function __construct(
        private array $staticLabels,
        private array $dynamicLabels = ['env', 'channel', 'severity'],
    ) {
    }

    /** @return array<string, string> */
    public function labels(ActivityEntry $entry): array
    {
        $labels = $this->staticLabels;

        foreach ($this->dynamicLabels as $label) {
            $labels[$label] = match ($label) {
                'env'      => $entry->environment,
                'channel'  => $entry->channel->value,
                'severity' => $entry->severity->value,
                default    => throw new LokiMisconfigurationException("Unknown dynamic label [{$label}]."),
            };
        }

        return $labels;
    }

    /** @return array<string, string> */
    public function metadata(ActivityEntry $entry): array
    {
        $request = $entry->request;
        $actor = $entry->actor;
        $subject = $entry->subject;

        $metadata = array_filter([
            'id'             => $entry->id,
            'trace_id'       => '' !== $entry->trace->traceId ? $entry->trace->traceId : null,
            'correlation_id' => $entry->trace->correlationId ?: null,
            'causation_id'   => $entry->trace->causationId ?: null,
            'actor_type'     => $actor instanceof Actor ? $actor->type : null,
            'actor_id'       => $actor instanceof Actor && null !== $actor->id ? $actor->id : null,
            'subject_type'   => $subject instanceof Subject ? $subject->type : null,
            'subject_id'     => $subject instanceof Subject ? $subject->id : null,
            'action'         => $entry->action,
            'route'          => $request instanceof RequestSnapshot ? $request->route : null,
            'method'         => $request instanceof RequestSnapshot ? $request->method : null,
            'status_code'    => $request instanceof RequestSnapshot && null !== $request->statusCode ? (string) $request->statusCode : null,
            'duration_ms'    => $request instanceof RequestSnapshot && null !== $request->durationMs ? (string) $request->durationMs : null,
            'tags'           => [] !== $entry->tags ? implode(',', $entry->tags) : null,
        ], static fn (?string $value): bool => null !== $value);

        return array_intersect_key($metadata, array_flip(self::METADATA_KEYS));
    }

    public function body(ActivityEntry $entry): string
    {
        return json_encode($entry->toStorage(), JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    }

    /**
     * @param list<ActivityEntry> $entries
     * @return list<LokiStream>
     */
    public function streams(array $entries): array
    {
        $buckets = [];

        foreach ($entries as $entry) {
            $labels = $this->labels($entry);
            $key = json_encode($labels);
            $buckets[$key] ??= new LokiStream($labels);
            $buckets[$key] = $buckets[$key]->add($this->nanoseconds($entry->loggedAt), $this->body($entry), $this->metadata($entry));
        }

        return array_values($buckets);
    }

    public function nanoseconds(DateTimeImmutable $at): string
    {
        return (string) ($at->getTimestamp() * 1_000_000_000 + ((int) $at->format('u') * 1000));
    }

    public function fromNanoseconds(string $nanoseconds): DateTimeImmutable
    {
        if (1 !== preg_match('/^-?\d+$/', $nanoseconds)) {
            throw new LokiMisconfigurationException('Invalid Loki nanosecond timestamp.');
        }

        $value = (int) $nanoseconds;
        $seconds = intdiv($value, 1_000_000_000);
        $remainder = $value % 1_000_000_000;

        if ($remainder < 0) {
            $seconds--;
            $remainder += 1_000_000_000;
        }

        $at = DateTimeImmutable::createFromFormat('U.u', sprintf('%d.%06d', $seconds, intdiv($remainder, 1000)));

        if (false === $at) {
            throw new LokiMisconfigurationException('Invalid Loki nanosecond timestamp.');
        }

        return $at->setTimezone(new DateTimeZone('UTC'));
    }
}
