<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Drivers;

use DateTimeImmutable;
use Dotswan\EagleEye\Contracts\Driver\FullActivityLogDriverInterface;
use Dotswan\EagleEye\Contracts\Driver\ActivityLogSeriesDriverInterface;
use Dotswan\EagleEye\Data\ActivityEntry;
use Dotswan\EagleEye\Data\EnvelopeBudget;
use Dotswan\EagleEye\Data\ErasureResult;
use Dotswan\EagleEye\Data\Actor;
use Dotswan\EagleEye\Data\Query\ActivityQuery;
use Dotswan\EagleEye\Data\Query\QueryResult;
use Dotswan\EagleEye\Data\RequestSnapshot;
use Dotswan\EagleEye\Data\Subject;
use Dotswan\EagleEye\Data\TraceContext;
use Dotswan\EagleEye\Drivers\Concerns\BuildsSqlActivityQueries;
use Dotswan\EagleEye\Enums\ActivityChannel;
use Dotswan\EagleEye\Enums\ActivitySeverity;
use Dotswan\EagleEye\Enums\Capability;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Dotswan\EagleEye\Contracts\Privacy\ErasureBarrierInterface;
use Throwable;

/**
 * Header-only SQL projection (~250 B/row, no payload) written in the same
 * batch as the primary driver. Buys exactly what append backends (Loki)
 * cannot provide: unbounded-time actor/subject queries, exact counts,
 * stable rankings, and instant trace assembly. Returned entries carry
 * headers only (`payload`/`secrets` empty); bodies are fetched from the
 * primary driver on demand.
 */
final readonly class IndexDriver implements FullActivityLogDriverInterface, ActivityLogSeriesDriverInterface
{
    use BuildsSqlActivityQueries;

    private const int INSERT_CHUNK = 100;

    private const int DELETE_CHUNK = 1000;

    public function __construct(
        private Connection $connection,
        private string $table = 'activity_log_index',
        private ?ErasureBarrierInterface $erasure = null,
    ) {
    }

    public function write(ActivityEntry $entry): void
    {
        $this->writeBatch([$entry]);
    }

    /** @param list<ActivityEntry> $entries */
    public function writeBatch(array $entries): array
    {
        EnvelopeBudget::batchSize($entries);
        $write = function (array $allowed): void {
            foreach (array_chunk(array_map($this->toRow(...), $allowed), self::INSERT_CHUNK) as $chunk) {
                $this->query()->insertOrIgnore($chunk);
            }
        };

        if ($this->erasure instanceof ErasureBarrierInterface) {
            $this->erasure->deliver($entries, $write, $this->connection, acknowledged: false);
        } else {
            $write($entries);
        }

        return [];
    }

    public function name(): string
    {
        return 'index';
    }

    public function healthy(): bool
    {
        try {
            $this->connection->select('SELECT 1');

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function supports(Capability $capability): bool
    {
        return match ($capability) {
            Capability::ExactCount,
            Capability::UnboundedTimeRange,
            Capability::Aggregation,
            Capability::RankedAggregation,
            Capability::SeriesAggregation,
            Capability::SynchronousDeletion => true,
            Capability::FullTextSearch,
            Capability::LiveTail,
            Capability::JsonFieldQuery => false,
        };
    }

    public function find(string $id): ?ActivityEntry
    {
        $row = $this->query()->where('id', $id)->first();

        return null === $row ? null : $this->fromRow((array) $row);
    }

    public function search(ActivityQuery $query): QueryResult
    {
        return $this->runSearch(
            $query,
            $this->applyActivityFilters($this->query(), $query),
            fn (array $row): ActivityEntry => $this->fromRow($row),
            $this->applyActivityFilters($this->query(), $query)->count(),
        );
    }

    public function count(ActivityQuery $query): int
    {
        return $this->applyActivityFilters($this->query(), $query)->count();
    }

    /** @return array<string, int> */
    public function aggregate(ActivityQuery $query, string $groupBy, string $interval = 'day'): array
    {
        $expression = $this->sqlGroupExpression($groupBy, $interval);

        $rows = $this->applyActivityFilters($this->query(), $query)
            ->selectRaw("{$expression} AS group_key, COUNT(*) AS aggregate")
            ->groupBy('group_key')
            ->orderBy('group_key')
            ->limit(EnvelopeBudget::MAX_AGGREGATE_GROUPS + 1)
            ->get();

        $this->assertAggregateBound($rows->all());

        $groups = [];

        foreach ($rows as $row) {
            $groups[$this->canonicalGroupKey($groupBy, $row->group_key)] = (int) $row->aggregate;
        }

        return $groups;
    }

    public function aggregateTop(ActivityQuery $query, string $groupBy, int $limit): array
    {
        $expression = $this->sqlGroupExpression($groupBy, 'day');
        $rows = $this->applyActivityFilters($this->query(), $query)
            ->selectRaw("{$expression} AS group_key, COUNT(*) AS aggregate")
            ->groupBy('group_key')
            ->orderByDesc('aggregate')
            ->orderBy('group_key')
            ->limit(max(1, min($limit, 100)))
            ->get();
        $groups = [];

        foreach ($rows as $row) {
            $groups[$this->canonicalGroupKey($groupBy, $row->group_key)] = (int) $row->aggregate;
        }

        return $groups;
    }

    /** @return list<string> */
    public function tags(?string $prefix = null, int $limit = 50): array
    {
        return $this->collectTags($this->query(), $prefix, $limit);
    }

    /**
     * @return array{buckets: list<DateTimeImmutable>, series: array<string, list<int>>}
     */
    public function aggregateSeries(ActivityQuery $query, ?string $groupBy, int $stepSeconds): array
    {
        return $this->runAggregateSeries($this->applyActivityFilters($this->query(), $query), $query, $groupBy, $stepSeconds);
    }

    public function prune(DateTimeImmutable $before, ?ActivityQuery $scope = null): int
    {
        $builder = $scope instanceof ActivityQuery
            ? $this->applyActivityFilters($this->query(), $scope)
            : $this->query();

        return $this->deleteInBatches($builder->where('logged_at', '<', $this->sqlTimestamp($before)));
    }

    public function forget(string $subjectType, string $subjectId): ErasureResult
    {
        $builder = $this->query()
            ->where(static function (Builder $builder) use ($subjectType, $subjectId): void {
                $builder->where(static function (Builder $subject) use ($subjectType, $subjectId): void {
                    $subject->where('subject_id', $subjectId)
                        ->where(static function (Builder $type) use ($subjectType): void {
                            $type->where('subject_type', $subjectType)->orWhere('subject_class', $subjectType);
                        });
                })->orWhere(static function (Builder $actor) use ($subjectType, $subjectId): void {
                    $actor->where('actor_type', $subjectType)->where('actor_id', $subjectId);
                });
            });

        return ErasureResult::completed($this->name(), $this->deleteInBatches($builder));
    }

    public function install(): void
    {
        ActivityLogSchema::createIndex($this->connection->getSchemaBuilder(), $this->table);
    }

    /** @return array{entries: int, bytes: int, oldest: ?DateTimeImmutable} */
    public function stats(): array
    {
        $row = $this->query()
            ->selectRaw('COUNT(*) AS entries, MIN(logged_at) AS oldest')
            ->first();

        $oldest = is_object($row) && null !== $row->oldest ? new DateTimeImmutable((string) $row->oldest) : null;

        return [
            'entries' => is_object($row) ? (int) $row->entries : 0,
            'bytes'   => 0,
            'oldest'  => $oldest,
        ];
    }

    protected function sqlConnection(): Connection
    {
        return $this->connection;
    }

    /** @return list<string> */
    protected function searchColumns(): array
    {
        return ['title', 'action', 'subject_label', 'actor_label', 'route'];
    }

    private function query(): Builder
    {
        return $this->connection->table($this->table);
    }

    private function deleteInBatches(Builder $builder): int
    {
        $deleted = 0;

        while (true) {
            $ids = (clone $builder)->orderBy('id')->limit(self::DELETE_CHUNK)->pluck('id')->all();

            if ([] === $ids) {
                return $deleted;
            }

            $deleted += $this->query()->whereIn('id', $ids)->delete();

            if (count($ids) < self::DELETE_CHUNK) {
                return $deleted;
            }
        }
    }

    /** @return array<string, mixed> */
    private function toRow(ActivityEntry $entry): array
    {
        $actor = $entry->actor;
        $subject = $entry->subject;
        $request = $entry->request;

        return [
            'id'             => $entry->id,
            'logged_at'      => $this->sqlTimestamp($entry->loggedAt),
            'channel'        => $entry->channel->value,
            'severity'       => $entry->severity->value,
            'action'         => $entry->action,
            'actor_type'     => $actor?->type,
            'actor_id'       => $actor?->id,
            'actor_label'    => $actor?->label,
            'subject_type'   => $subject?->type,
            'subject_id'     => $subject?->id,
            'subject_label'  => $subject?->label,
            'subject_class'  => $subject?->className,
            'trace_id'       => $entry->trace->traceId,
            'correlation_id' => $entry->trace->correlationId,
            'causation_id'   => $entry->trace->causationId,
            'route'          => $request?->route,
            'method'         => $request?->method,
            'status_code'    => $request?->statusCode,
            'duration_ms'    => $request?->durationMs,
            'tags'           => json_encode($entry->tags, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'title'          => $entry->title,
            'created_at'     => $this->sqlTimestamp($entry->createdAt ?? $entry->loggedAt),
        ];
    }

    /**
     * Header rows contain no payload; the returned entry is a projection,
     * not the full record.
     *
     * @param array<string, mixed> $row
     */
    private function fromRow(array $row): ActivityEntry
    {
        $tags = is_string($row['tags'] ?? null) ? json_decode((string) $row['tags'], true) : null;
        $actorType = $row['actor_type'] ?? null;
        $subjectType = $row['subject_type'] ?? null;

        return new ActivityEntry(
            id: (string) $row['id'],
            schemaVersion: 1,
            loggedAt: new DateTimeImmutable((string) $row['logged_at']),
            channel: ActivityChannel::from((string) $row['channel']),
            action: (string) $row['action'],
            severity: ActivitySeverity::from((string) $row['severity']),
            title: (string) ($row['title'] ?? ''),
            summary: null,
            actor: is_string($actorType) ? new Actor(
                $actorType,
                null === $row['actor_id'] ? null : (string) $row['actor_id'],
                null === $row['actor_label'] ? null : (string) $row['actor_label'],
            ) : null,
            subject: is_string($subjectType) ? new Subject(
                $subjectType,
                (string) ($row['subject_id'] ?? ''),
                null === $row['subject_label'] ? null : (string) $row['subject_label'],
                null === $row['subject_class'] ? null : (string) $row['subject_class'],
            ) : null,
            trace: new TraceContext(
                (string) $row['trace_id'],
                (string) ($row['correlation_id'] ?? ''),
                null === ($row['causation_id'] ?? null) ? null : (string) $row['causation_id'],
            ),
            tags: is_array($tags) ? array_values(array_map(strval(...), $tags)) : [],
            request: null === ($row['route'] ?? null) && null === ($row['status_code'] ?? null) ? null : new RequestSnapshot(
                method: null === ($row['method'] ?? null) ? null : (string) $row['method'],
                route: null === ($row['route'] ?? null) ? null : (string) $row['route'],
                statusCode: null === ($row['status_code'] ?? null) ? null : (int) $row['status_code'],
                durationMs: null === ($row['duration_ms'] ?? null) ? null : (int) $row['duration_ms'],
            ),
            payload: [],
            secrets: [],
            payloadBytes: 0,
            environment: '',
            hostname: '',
            createdAt: new DateTimeImmutable((string) $row['created_at']),
        );
    }
}
