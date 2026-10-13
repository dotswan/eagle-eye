<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Drivers\Concerns;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Dotswan\EagleEye\Data\ActivityEntry;
use Dotswan\EagleEye\Data\EnvelopeBudget;
use Dotswan\EagleEye\Data\Query\ActivityQuery;
use Dotswan\EagleEye\Data\Query\Criterion;
use Dotswan\EagleEye\Data\Query\Cursor;
use Dotswan\EagleEye\Data\Query\QueryResult;
use Dotswan\EagleEye\Data\Series\SeriesWindow;
use Dotswan\EagleEye\Enums\ActivityChannel;
use Dotswan\EagleEye\Enums\ActivitySeverity;
use Dotswan\EagleEye\Enums\SortOrder;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;
use LengthException;

/**
 * Shared SQL filtering for the activity tables (full `activity_logs` and the
 * header-only index projection). Both schemas expose the same filterable
 * columns; only the searchable-text column set differs per driver.
 */
trait BuildsSqlActivityQueries
{
    abstract protected function sqlConnection(): Connection;

    /** @return list<string> */
    abstract protected function searchColumns(): array;

    protected function applyActivityFilters(Builder $builder, ActivityQuery $query): Builder
    {
        if ([] !== $query->channels) {
            $builder->whereIn('channel', array_map(
                static fn (ActivityChannel $channel): string => $channel->value,
                $query->channels,
            ));
        }

        if ([] !== $query->severities) {
            $builder->whereIn('severity', array_map(
                static fn (ActivitySeverity $severity): string => $severity->value,
                $query->severities,
            ));
        }

        if ([] !== $query->actions) {
            $builder->whereIn('action', $query->actions);
        }

        if ($query->from instanceof DateTimeImmutable) {
            $builder->where('logged_at', '>=', $this->sqlTimestamp($query->from));
        }

        if ($query->to instanceof DateTimeImmutable) {
            $builder->where('logged_at', '<', $this->sqlTimestamp($query->to));
        }

        if (null !== $query->actorType) {
            $builder->where('actor_type', $query->actorType);
        }

        if (null !== $query->actorId) {
            $builder->where('actor_id', $query->actorId);
        }

        $this->applySubject($builder, $query);
        $this->applyTags($builder, $query->tags);
        $this->applySearch($builder, $query->search);

        if (null !== $query->traceId) {
            $builder->where('trace_id', $query->traceId);
        }

        if ($query->status instanceof Criterion) {
            $builder->where('status_code', $query->status->operator, (int) $query->status->value);
        }

        return $builder;
    }

    protected function applySubject(Builder $builder, ActivityQuery $query): void
    {
        if (null === $query->subjectType && null === $query->subjectClass && null === $query->subjectId) {
            return;
        }

        if (null !== $query->subjectId) {
            $builder->where('subject_id', $query->subjectId);
        }

        if (null !== $query->subjectType || null !== $query->subjectClass) {
            $builder->where(static function (Builder $nested) use ($query): void {
                if (null !== $query->subjectType) {
                    $nested->where('subject_type', $query->subjectType)
                        ->orWhere('subject_class', $query->subjectType);
                }

                if (null !== $query->subjectClass) {
                    $nested->orWhere('subject_class', $query->subjectClass);
                }
            });
        }
    }

    /** @param list<string> $tags */
    protected function applyTags(Builder $builder, array $tags): void
    {
        if ([] === $tags) {
            return;
        }

        $builder->where(function (Builder $nested) use ($tags): void {
            foreach ($tags as $index => $tag) {
                [$sql, $binding] = $this->tagContains($tag);
                $nested->whereRaw($sql, [$binding], 0 === $index ? 'and' : 'or');
            }
        });
    }

    /** @return array{0: literal-string, 1: string} */
    protected function tagContains(string $tag): array
    {
        $encodedTag = json_encode($tag, JSON_THROW_ON_ERROR);

        return match ($this->sqlConnection()->getDriverName()) {
            'mysql', 'mariadb' => ['JSON_CONTAINS(`tags`, ?)', $encodedTag],
            'pgsql'            => ["tags::jsonb @> ?::jsonb", '['.$encodedTag.']'],
            default            => ["EXISTS (SELECT 1 FROM json_each(tags) WHERE json_each.value = ?)", $tag],
        };
    }

    protected function applySearch(Builder $builder, ?string $term): void
    {
        if (null === $term || '' === $term) {
            return;
        }

        $like = '%'.addcslashes($term, '\\%_').'%';
        $columns = $this->searchColumns();

        $builder->where(static function (Builder $nested) use ($like, $columns): void {
            foreach ($columns as $column) {
                $nested->orWhere($column, 'LIKE', $like);
            }
        });
    }

    protected function applyCursor(Builder $builder, Cursor $cursor): void
    {
        $operator = SortOrder::Ascending === $cursor->direction ? '>' : '<';
        $timestamp = $this->sqlTimestamp($cursor->timestamp);

        $builder->where(static function (Builder $nested) use ($operator, $timestamp, $cursor): void {
            $nested->where('logged_at', $operator, $timestamp)
                ->orWhere(static function (Builder $same) use ($operator, $timestamp, $cursor): void {
                    $same->where('logged_at', '=', $timestamp)->where('id', $operator, $cursor->lastId);
                });
        });
    }

    /**
     * Shared search execution for the SQL drivers. Keyset pagination uses
     * the stable (logged_at, id) order encoded by the cursor.
     *
     * @param  Closure(array<string, mixed>): ActivityEntry  $fromRow
     */
    protected function runSearch(ActivityQuery $query, Builder $builder, Closure $fromRow, ?int $count = null): QueryResult
    {
        $direction = SortOrder::Ascending === $query->sortOrder ? 'asc' : 'desc';
        $builder->orderBy('logged_at', $direction)->orderBy('id', $direction);

        if ($query->cursor instanceof Cursor) {
            $this->applyCursor($builder, $query->cursor);
        }

        $builder->limit($query->limit + 1);
        $rows = $this->prepareSearchRows(
            $query,
            array_values($this->prepareSearchBuilder($query, $builder)->get()->all()),
        );
        $entries = [];

        foreach ($rows as $row) {
            $entries[] = $fromRow((array) $row);
        }

        $hasMore = count($entries) > $query->limit;

        if ($hasMore) {
            array_pop($entries);
        }

        $last = [] === $entries ? null : $entries[array_key_last($entries)];

        return new QueryResult(
            entries: $entries,
            nextCursor: $hasMore && $last instanceof ActivityEntry
                ? new Cursor(
                    $last->loggedAt,
                    $last->id,
                    $query->sortOrder,
                )
                : null,
            hasMore: $hasMore,
            count: $count,
        );
    }

    protected function prepareSearchBuilder(ActivityQuery $query, Builder $builder): Builder
    {
        return $builder;
    }

    /**
     * @param  list<object>  $rows
     * @return list<object>
     */
    protected function prepareSearchRows(ActivityQuery $query, array $rows): array
    {
        return $rows;
    }

    /** @return literal-string */
    protected function sqlGroupExpression(string $groupBy, string $interval): string
    {
        return match ($groupBy) {
            'channel'      => 'channel',
            'action'       => 'action',
            'severity'     => 'severity',
            'actor_type'   => 'actor_type',
            'subject_type' => 'subject_type',
            'trace_id'     => 'trace_id',
            'actor'        => $this->actorGroupExpression(),
            'route'        => "COALESCE(route, 'unknown')",
            'status_code'  => $this->statusGroupExpression(),
            'logged_at'    => $this->dateGroupExpression($interval),
            default        => throw new InvalidArgumentException("Unknown activity group key [{$groupBy}]."),
        };
    }

    /** @return literal-string */
    protected function actorGroupExpression(): string
    {
        return match ($this->sqlConnection()->getDriverName()) {
            'mysql', 'mariadb' => "CAST(JSON_ARRAY(COALESCE(actor_type, 'system'), COALESCE(actor_id, '')) AS CHAR)",
            'pgsql'            => "json_build_array(COALESCE(actor_type, 'system'), COALESCE(actor_id, ''))::text",
            default            => "json_array(COALESCE(actor_type, 'system'), COALESCE(actor_id, ''))",
        };
    }

    protected function canonicalGroupKey(string $groupBy, mixed $value): string
    {
        if ('actor' !== $groupBy || ! is_string($value)) {
            return (string) $value;
        }

        $identity = json_decode($value, true);

        return is_array($identity)
            ? json_encode(array_values($identity), JSON_THROW_ON_ERROR)
            : $value;
    }

    /** @return literal-string */
    protected function statusGroupExpression(): string
    {
        $cast = 'pgsql' === $this->sqlConnection()->getDriverName() ? 'text' : 'char';

        return "COALESCE(CAST(status_code AS {$cast}), '0')";
    }

    /** @return literal-string */
    protected function dateGroupExpression(string $interval): string
    {
        return match ($this->sqlConnection()->getDriverName()) {
            'mysql', 'mariadb' => "DATE_FORMAT(logged_at, '".$this->intervalFormat($interval, true)."')",
            'pgsql'            => "TO_CHAR(logged_at, '".$this->intervalFormat($interval, false)."')",
            default            => "strftime('".$this->intervalFormat($interval, true)."', logged_at)",
        };
    }

    /** @return literal-string */
    protected function intervalFormat(string $interval, bool $percent): string
    {
        return match ($interval) {
            'hour'  => $percent ? '%Y-%m-%d %H:00' : 'YYYY-MM-DD HH24:00',
            'month' => $percent ? '%Y-%m' : 'YYYY-MM',
            default => $percent ? '%Y-%m-%d' : 'YYYY-MM-DD',
        };
    }

    /** @return list<string> */
    protected function collectTags(Builder $builder, ?string $prefix, int $limit, int $chunkSize = 200): array
    {
        $limit = max(0, min($limit, 500));

        if (0 === $limit) {
            return [];
        }

        $tags = [];
        $bytes = 0;

        $builder
            ->select(['id', 'tags'])
            ->whereNotNull('tags')
            ->chunkById(min($chunkSize, 50), function ($rows) use ($prefix, $limit, &$tags, &$bytes): bool {
                foreach ($rows as $row) {
                    $decoded = json_decode((string) $row->tags, true);

                    if (! is_array($decoded)) {
                        continue;
                    }

                    foreach ($decoded as $tag) {
                        if (is_string($tag) && (null === $prefix || str_starts_with($tag, $prefix))) {
                            if (isset($tags[$tag])) {
                                continue;
                            }

                            $nextBytes = $bytes + mb_strlen($tag, '8bit');

                            if ($nextBytes > EnvelopeBudget::MAX_TAG_RESULT_BYTES) {
                                return false;
                            }

                            $tags[$tag] = true;
                            $bytes = $nextBytes;

                            if (count($tags) >= $limit) {
                                return false;
                            }
                        }
                    }
                }

                return true;
            });

        $values = array_keys($tags);
        sort($values);

        return array_slice($values, 0, $limit);
    }

    /** @param array<int, object> $rows */
    protected function assertAggregateBound(array $rows): void
    {
        if (count($rows) > EnvelopeBudget::MAX_AGGREGATE_GROUPS) {
            throw new LengthException('Activity aggregation exceeds its group limit.');
        }
    }

    /**
     * @return array{buckets: list<DateTimeImmutable>, series: array<string, list<int>>}
     */
    protected function runAggregateSeries(Builder $builder, ActivityQuery $query, ?string $groupBy, int $stepSeconds): array
    {
        $window = SeriesWindow::fromQuery($query, $stepSeconds);
        $this->assertSeriesGroupKey($groupBy);
        $select = $builder->selectRaw($this->sqlSeriesBucketExpression().' AS bucket_key', [$stepSeconds, $stepSeconds]);

        if (null !== $groupBy) {
            $select->selectRaw($this->sqlGroupExpression($groupBy, 'day').' AS group_key')->groupBy('group_key');
        }

        $rows = $select
            ->selectRaw('COUNT(*) AS aggregate')
            ->groupBy('bucket_key')
            ->orderBy('bucket_key')
            ->limit(EnvelopeBudget::MAX_SERIES_POINTS + 1)
            ->get();

        if (count($rows) > EnvelopeBudget::MAX_SERIES_POINTS) {
            throw new LengthException('Activity series exceeds its point limit.');
        }

        $counts = [];

        foreach ($rows as $row) {
            $group = null === $groupBy ? '_total' : $this->canonicalGroupKey($groupBy, $row->group_key);
            $index = intdiv(((int) $row->bucket_key) - $window->bucketTimestamp(), $stepSeconds);
            $counts[$group][$index] = (int) $row->aggregate;
        }

        return $window->assemble($counts, null === $groupBy ? ['_total'] : []);
    }

    protected function assertSeriesGroupKey(?string $groupBy): void
    {
        if (null !== $groupBy && in_array($groupBy, ['logged_at', 'id', 'correlation_id', 'causation_id'], true)) {
            throw new InvalidArgumentException("Activity series group key [{$groupBy}] is not supported.");
        }
    }

    /** @return literal-string */
    protected function sqlSeriesBucketExpression(): string
    {
        return match ($this->sqlConnection()->getDriverName()) {
            'mysql', 'mariadb' => 'FLOOR(UNIX_TIMESTAMP(logged_at)/?)*?',
            'pgsql'            => 'FLOOR(EXTRACT(EPOCH FROM logged_at)/?)*?',
            default            => "(CAST(strftime('%s', logged_at) AS INTEGER)/?)*?",
        };
    }

    protected function sqlTimestamp(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
