<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Http\Resources;

use Dotswan\EagleEye\Data\Subject;
use Dotswan\EagleEye\Contracts\Resolution\FieldLabelResolverInterface;
use Dotswan\EagleEye\Data\ActivityEntry;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The frozen, versioned API envelope for one entry. Any shape change here
 * requires bumping the envelope's `schema_version` (see the snapshot test).
 *
 * @property ActivityEntry $resource
 */
final class ActivityEntryResource extends JsonResource
{
    public const int ENVELOPE_SCHEMA_VERSION = 1;

    /**
     * Field map shared with the committed TypeScript contract
     * (resources/js/types/eagle-eye.d.ts) — the drift test reads both.
     *
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        $entry = $this->resource;

        return [
            'id'             => $entry->id,
            'schema_version' => $entry->schemaVersion,
            'logged_at'      => $entry->loggedAt->format('Y-m-d\TH:i:s.u\Z'),
            'channel'        => $entry->channel->value,
            'action'         => $entry->action,
            'severity'       => $entry->severity->value,
            'title'          => $entry->title,
            'summary'        => $entry->summary,
            'actor'          => $entry->actor?->toArray(),
            'subject'        => $entry->subject?->toArray(),
            'trace'          => [
                'trace_id'       => $entry->trace->traceId,
                'correlation_id' => $entry->trace->correlationId,
                'causation_id'   => $entry->trace->causationId,
                'parent_span_id' => $entry->trace->parentSpanId,
            ],
            'tags'           => $entry->tags,
            'request'        => $entry->request?->toArray(),
            'changes'        => $this->changes($entry),
            'payload'        => $entry->payload,
            'sealed'         => array_keys($entry->secrets),
            'payload_bytes'  => $entry->payloadBytes,
            'environment'    => $entry->environment,
            'hostname'       => $entry->hostname,
            'memory_kb'      => $entry->memoryKb,
            'integrity_hash' => $entry->integrityHash,
            'created_at'     => $entry->createdAt?->format('Y-m-d\TH:i:s.u\Z'),
        ];
    }

    /**
     * Pre-formatted change rows with human field labels.
     *
     * @return list<array{path: string, label: string, before: mixed, after: mixed}>
     */
    private function changes(ActivityEntry $entry): array
    {
        $changes = $entry->payload['changes'] ?? [];

        if (! is_array($changes)) {
            return [];
        }

        $rows = [];
        $labels = app(FieldLabelResolverInterface::class);
        $subject = $entry->subject;
        $labelSubject = $subject instanceof Subject ? $subject->className ?? $subject->type : ('activity');

        foreach ($changes as $path => $change) {
            if (! is_string($path) || ! is_array($change)) {
                continue;
            }

            $rows[] = [
                'path'   => $path,
                'label'  => $labels->resolve($labelSubject, $path),
                'before' => $change['old'] ?? null,
                'after'  => $change['new'] ?? null,
            ];
        }

        return $rows;
    }
}
