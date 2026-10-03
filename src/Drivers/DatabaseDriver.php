<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Drivers;

use DateTimeImmutable;
use Dotswan\EagleEye\Contracts\Driver\FullActivityLogDriverInterface;
use Dotswan\EagleEye\Contracts\Driver\ActivityLogSeriesDriverInterface;
use Dotswan\EagleEye\Contracts\Driver\StoresFullActivityBodiesInterface;
use Dotswan\EagleEye\Contracts\Security\DurableSecurityAuditDriverInterface;
use Dotswan\EagleEye\Drivers\Concerns\BuildsSqlActivityQueries;
use Dotswan\EagleEye\Data\ActivityEntry;
use Dotswan\EagleEye\Data\EnvelopeBudget;
use Dotswan\EagleEye\Data\ErasureResult;
use Dotswan\EagleEye\Data\Query\ActivityQuery;
use Dotswan\EagleEye\Data\Query\QueryResult;
use Dotswan\EagleEye\Enums\Capability;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Dotswan\EagleEye\Contracts\Privacy\ErasureBarrierInterface;
use Dotswan\EagleEye\Contracts\Integrity\IntegrityChainInterface;
use Throwable;

final readonly class DatabaseDriver implements DurableSecurityAuditDriverInterface, FullActivityLogDriverInterface, ActivityLogSeriesDriverInterface, StoresFullActivityBodiesInterface
{
    use BuildsSqlActivityQueries;

    private const int INSERT_CHUNK = 100;

    private const int TAGS_SCAN_CHUNK = 50;

    private const int DELETE_CHUNK = 1000;

    public function hasUncommittedTransaction(): bool
    {
        return $this->connection->transactionLevel() > 0 || $this->connection->getPdo()->inTransaction();
    }

    public function __construct(
        private Connection $connection,
        private string $table = 'activity_logs',
        private ?ErasureBarrierInterface $erasure = null,
        private ?IntegrityChainInterface $integrity = null,
    ) {
    }

    public function write(ActivityEntry $entry): void
    {
        $this->writeBatch([$entry]);
    }

    public function writeBatch(array $entries): array
    {
        EnvelopeBudget::batchSize($entries);
        $write = function (array $allowed): void {
            foreach (array_chunk(array_map($this->toRow(...), $allowed), self::INSERT_CHUNK) as $chunk) {
                $this->query()->insertOrIgnore($chunk);
            }
        };

        if ($this->erasure instanceof ErasureBarrierInterface) {
            $this->erasure->deliver($entries, $write, $this->connection);
        } else {
            $write($entries);
            $this->integrity?->transitionEntries($entries, 'delivered');
        }

        return [];
    }

    public function name(): string
    {
        return 'database';
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
            Capability::FullTextSearch,
            Capability::ExactCount,
            Capability::UnboundedTimeRange,
            Capability::Aggregation,
            Capability::RankedAggregation,
            Capability::SeriesAggregation,
            Capability::SynchronousDeletion => true,
            Capability::JsonFieldQuery,
            Capability::LiveTail => false,
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
            $this->filtered($query),
            fn (array $row): ActivityEntry => $this->fromRow($row),
            $this->filtered($query)->count(),
        );
    }

    public function count(ActivityQuery $query): int
    {
        return $this->filtered($query)->count();
    }

    public function aggregate(ActivityQuery $query, string $groupBy, string $interval = 'day'): array
    {
        $expression = $this->sqlGroupExpression($groupBy, $interval);

        $rows = $this->filtered($query)
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
        $rows = $this->filtered($query)
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

    public function tags(?string $prefix = null, int $limit = 50): array
    {
        return $this->collectTags($this->query(), $prefix, $limit, self::TAGS_SCAN_CHUNK);
    }

    /**
     * @return array{buckets: list<DateTimeImmutable>, series: array<string, list<int>>}
     */
    public function aggregateSeries(ActivityQuery $query, ?string $groupBy, int $stepSeconds): array
    {
        return $this->runAggregateSeries($this->filtered($query), $query, $groupBy, $stepSeconds);
    }

    public function prune(DateTimeImmutable $before, ?ActivityQuery $scope = null): int
    {
        $builder = $scope instanceof ActivityQuery ? $this->filtered($scope) : $this->query();

        return $this->deleteInBatches($builder->where('logged_at', '<', $this->sqlTimestamp($before)), 'pruned');
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

        return ErasureResult::completed($this->name(), $this->deleteInBatches($builder, 'erased'));
    }

    public function install(): void
    {
        ActivityLogSchema::create($this->connection->getSchemaBuilder(), $this->table);
    }

    public function stats(): array
    {
        $row = $this->query()
            ->selectRaw('COUNT(*) AS entries, COALESCE(SUM(payload_bytes), 0) AS bytes, MIN(logged_at) AS oldest')
            ->first();

        $oldest = is_object($row) && null !== $row->oldest ? new DateTimeImmutable((string) $row->oldest) : null;

        return [
            'entries' => is_object($row) ? (int) $row->entries : 0,
            'bytes'   => is_object($row) ? (int) $row->bytes : 0,
            'oldest'  => $oldest,
        ];
    }

    private function query(): Builder
    {
        return $this->connection->table($this->table);
    }

    private function deleteInBatches(Builder $builder, string $lifecycle): int
    {
        $this->integrity?->assertRemovalConnection($this->connection);
        $deleted = 0;

        while (true) {
            $ids = array_values(array_filter((clone $builder)->orderBy('id')->limit(self::DELETE_CHUNK)->pluck('id')->all(), is_string(...)));

            if ([] === $ids) {
                return $deleted;
            }

            $deleted += $this->connection->transaction(function () use ($ids, $lifecycle): int {
                $this->integrity?->transitionIds($ids, $lifecycle);

                return $this->query()->whereIn('id', $ids)->delete();
            });

            if (count($ids) < self::DELETE_CHUNK) {
                return $deleted;
            }
        }
    }

    private function filtered(ActivityQuery $query): Builder
    {
        return $this->applyActivityFilters($this->query(), $query);
    }

    protected function sqlConnection(): Connection
    {
        return $this->connection;
    }

    /** @return list<string> */
    protected function searchColumns(): array
    {
        return ['title', 'action', 'subject_label', 'actor_label', 'url'];
    }

    /** @return array<string, mixed> */
    private function toRow(ActivityEntry $entry): array
    {
        $storage = $entry->toStorage();
        $storage['tags'] = json_encode($storage['tags'], JSON_UNESCAPED_UNICODE);
        $storage['payload'] = json_encode($storage['payload'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $storage['secrets'] = json_encode($storage['secrets'], JSON_UNESCAPED_UNICODE);
        $storage['logged_at'] = $this->sqlTimestamp($entry->loggedAt);
        $storage['created_at'] = $this->sqlTimestamp($entry->createdAt ?? $entry->loggedAt);

        return $storage;
    }

    /** @param array<string, mixed> $row */
    private function fromRow(array $row): ActivityEntry
    {
        foreach (['tags', 'payload', 'secrets'] as $column) {
            $decoded = is_string($row[$column] ?? null) ? json_decode($row[$column], true) : null;
            $row[$column] = is_array($decoded) ? $decoded : [];
        }

        foreach (['logged_at', 'created_at'] as $column) {
            if (isset($row[$column]) && is_string($row[$column])) {
                $row[$column] = new DateTimeImmutable($row[$column]);
            }
        }

        return ActivityEntry::fromStorage($row);
    }
}
