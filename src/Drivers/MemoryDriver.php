<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Drivers;

use DateTimeImmutable;
use Dotswan\EagleEye\Contracts\Driver\FullActivityLogDriverInterface;
use Dotswan\EagleEye\Contracts\Driver\ActivityLogSeriesDriverInterface;
use Dotswan\EagleEye\Contracts\Driver\StoresFullActivityBodiesInterface;
use Dotswan\EagleEye\Data\ActivityEntry;
use Dotswan\EagleEye\Data\EnvelopeBudget;
use Dotswan\EagleEye\Data\Series\SeriesWindow;
use Dotswan\EagleEye\Data\ErasureResult;
use Dotswan\EagleEye\Data\Actor;
use Dotswan\EagleEye\Data\Query\ActivityQuery;
use Dotswan\EagleEye\Data\Query\Criterion;
use Dotswan\EagleEye\Data\Query\Cursor;
use Dotswan\EagleEye\Data\Query\QueryResult;
use Dotswan\EagleEye\Data\Subject;
use Dotswan\EagleEye\Enums\Capability;
use Dotswan\EagleEye\Enums\SortOrder;
use InvalidArgumentException;
use LengthException;

final class MemoryDriver implements FullActivityLogDriverInterface, ActivityLogSeriesDriverInterface, StoresFullActivityBodiesInterface
{
    /** @var array<string, array<string, mixed>> */
    private array $entries = [];

    public function __construct(private readonly int $maxEntries = 1000)
    {
        if ($this->maxEntries < 1 || $this->maxEntries > 100_000) {
            throw new InvalidArgumentException('Memory driver capacity must be between 1 and 100000 entries.');
        }
    }

    public function write(ActivityEntry $entry): void
    {
        EnvelopeBudget::size($entry);

        if (! isset($this->entries[$entry->id]) && count($this->entries) >= $this->maxEntries) {
            $oldest = array_key_first($this->entries);
            unset($this->entries[$oldest]);
        }

        $this->entries[$entry->id] = $entry->toStorage();
    }

    public function writeBatch(array $entries): array
    {
        EnvelopeBudget::batchSize($entries);

        foreach ($entries as $entry) {
            $this->write($entry);
        }

        return [];
    }

    public function name(): string
    {
        return 'memory';
    }

    public function healthy(): bool
    {
        return true;
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
            Capability::LiveTail,
            Capability::JsonFieldQuery => false,
        };
    }

    public function find(string $id): ?ActivityEntry
    {
        $entry = $this->entries[$id] ?? null;

        return is_array($entry) ? ActivityEntry::fromStorage($entry) : null;
    }

    public function search(ActivityQuery $query): QueryResult
    {
        $matches = [];

        foreach ($this->readEntries() as $entry) {
            if ($this->matches($entry, $query)) {
                $matches[] = $entry;
            }
        }

        usort($matches, fn (ActivityEntry $left, ActivityEntry $right): int => $this->compare($left, $right, $query->sortOrder));

        $entries = [];
        $hasMore = false;

        foreach ($matches as $entry) {
            if ($query->cursor instanceof Cursor && ! $this->isAfterCursor($entry, $query->cursor)) {
                continue;
            }

            if (count($entries) >= $query->limit) {
                $hasMore = true;

                break;
            }

            $entries[] = $entry;
        }

        $last = [] === $entries ? null : $entries[count($entries) - 1];

        return new QueryResult(
            entries: $entries,
            nextCursor: $hasMore && $last instanceof ActivityEntry
                ? new Cursor($last->loggedAt, $last->id, $query->sortOrder)
                : null,
            hasMore: $hasMore,
            count: count($matches),
        );
    }

    public function count(ActivityQuery $query): int
    {
        $count = 0;

        foreach ($this->readEntries() as $entry) {
            if ($this->matches($entry, $query)) {
                $count++;
            }
        }

        return $count;
    }

    public function aggregate(ActivityQuery $query, string $groupBy, string $interval = 'day'): array
    {
        $groups = [];

        foreach ($this->readEntries() as $entry) {
            if (! $this->matches($entry, $query)) {
                continue;
            }

            $key = $this->groupKey($entry, $groupBy, $interval);

            if (! isset($groups[$key]) && count($groups) >= EnvelopeBudget::MAX_AGGREGATE_GROUPS) {
                throw new LengthException('Activity aggregation exceeds its group limit.');
            }

            $groups[$key] = ($groups[$key] ?? 0) + 1;
        }

        ksort($groups);

        return $groups;
    }

    public function aggregateTop(ActivityQuery $query, string $groupBy, int $limit): array
    {
        $groups = $this->aggregate($query, $groupBy);
        uksort($groups, static fn (string $left, string $right): int => $groups[$right] <=> $groups[$left] ?: $left <=> $right);

        return array_slice($groups, 0, max(1, min($limit, 100)), true);
    }

    /**
     * @return array{buckets: list<DateTimeImmutable>, series: array<string, list<int>>}
     */
    public function aggregateSeries(ActivityQuery $query, ?string $groupBy, int $stepSeconds): array
    {
        $window = SeriesWindow::fromQuery($query, $stepSeconds);

        if (null !== $groupBy && in_array($groupBy, ['logged_at', 'id', 'correlation_id', 'causation_id'], true)) {
            throw new InvalidArgumentException("Activity series group key [{$groupBy}] is not supported.");
        }

        $counts = [];

        foreach ($this->readEntries() as $entry) {
            if (! $this->matches($entry, $query)) {
                continue;
            }

            $group = null === $groupBy ? '_total' : $this->groupKey($entry, $groupBy, 'day');
            $index = $window->bucketIndex($entry->loggedAt);
            $counts[$group][$index] = ($counts[$group][$index] ?? 0) + 1;
        }

        return $window->assemble($counts, null === $groupBy ? ['_total'] : []);
    }

    public function tags(?string $prefix = null, int $limit = 50): array
    {
        $tags = [];

        foreach ($this->readEntries() as $entry) {
            foreach ($entry->tags as $tag) {
                if (null === $prefix || str_starts_with($tag, $prefix)) {
                    $tags[$tag] = true;
                }
            }
        }

        $values = array_keys($tags);
        sort($values);

        return array_slice($values, 0, max(0, min($limit, 500)));
    }

    public function prune(DateTimeImmutable $before, ?ActivityQuery $scope = null): int
    {
        $removed = 0;

        foreach ($this->readEntries() as $id => $entry) {
            if ($entry->loggedAt < $before && (!$scope instanceof ActivityQuery || $this->matches($entry, $scope))) {
                unset($this->entries[$id]);
                $removed++;
            }
        }

        return $removed;
    }

    public function forget(string $subjectType, string $subjectId): ErasureResult
    {
        $removed = 0;

        foreach ($this->readEntries() as $id => $entry) {
            $matchesSubject = $entry->subject instanceof Subject
                && $entry->subject->id === $subjectId
                && ($entry->subject->type === $subjectType || $entry->subject->className === $subjectType);
            $matchesActor = $entry->actor instanceof Actor
                && $entry->actor->type === $subjectType
                && $entry->actor->id === $subjectId;

            if ($matchesSubject || $matchesActor) {
                unset($this->entries[$id]);
                $removed++;
            }
        }

        return ErasureResult::completed($this->name(), $removed);
    }

    public function install(): void
    {
    }

    public function stats(): array
    {
        $bytes = 0;
        $oldest = null;

        foreach ($this->readEntries() as $entry) {
            $bytes += $entry->payloadBytes;

            if (! $oldest instanceof DateTimeImmutable || $entry->loggedAt < $oldest) {
                $oldest = $entry->loggedAt;
            }
        }

        return [
            'entries' => count($this->entries),
            'bytes'   => $bytes,
            'oldest'  => $oldest,
        ];
    }

    /** @return list<ActivityEntry> */
    public function entries(): array
    {
        return array_values(iterator_to_array($this->readEntries()));
    }

    /** @return iterable<string, ActivityEntry> */
    private function readEntries(): iterable
    {
        foreach ($this->entries as $id => $entry) {
            yield $id => ActivityEntry::fromStorage($entry);
        }
    }

    private function matches(ActivityEntry $entry, ActivityQuery $query): bool
    {
        if ([] !== $query->channels && ! in_array($entry->channel, $query->channels, true)) {
            return false;
        }

        if ([] !== $query->severities && ! in_array($entry->severity, $query->severities, true)) {
            return false;
        }

        if ([] !== $query->actions && ! in_array($entry->action, $query->actions, true)) {
            return false;
        }

        if ($query->from instanceof DateTimeImmutable && $entry->loggedAt < $query->from) {
            return false;
        }

        if ($query->to instanceof DateTimeImmutable && $entry->loggedAt >= $query->to) {
            return false;
        }

        if (null !== $query->actorType && (! $entry->actor instanceof Actor || $entry->actor->type !== $query->actorType || $entry->actor->id !== $query->actorId)) {
            return false;
        }

        if (! $this->matchesSubject($entry, $query)) {
            return false;
        }

        if (null !== $query->traceId && $entry->trace->traceId !== $query->traceId) {
            return false;
        }

        if ([] !== $query->tags && [] === array_intersect($query->tags, $entry->tags)) {
            return false;
        }

        if (null !== $query->search && '' !== $query->search && ! $this->matchesSearch($entry, $query->search)) {
            return false;
        }

        return ! $query->status instanceof Criterion
            || $this->matchesStatus($entry->request?->statusCode, $query->status->operator, (int) $query->status->value);
    }

    private function matchesSubject(ActivityEntry $entry, ActivityQuery $query): bool
    {
        if (null === $query->subjectType && null === $query->subjectClass) {
            return true;
        }

        if (! $entry->subject instanceof Subject || $entry->subject->id !== $query->subjectId) {
            return false;
        }

        return $entry->subject->type === $query->subjectType
            || $entry->subject->className === $query->subjectType
            || $entry->subject->className === $query->subjectClass;
    }

    private function matchesSearch(ActivityEntry $entry, string $term): bool
    {
        $encoded = json_encode($entry->toStorage(), JSON_INVALID_UTF8_SUBSTITUTE);

        return false !== $encoded && false !== mb_stripos($encoded, $term);
    }

    private function matchesStatus(?int $actual, string $operator, int $expected): bool
    {
        if (null === $actual) {
            return false;
        }

        return match ($operator) {
            '='     => $actual === $expected,
            '!='    => $actual !== $expected,
            '>'     => $actual > $expected,
            '>='    => $actual >= $expected,
            '<'     => $actual < $expected,
            '<='    => $actual <= $expected,
            default => false,
        };
    }

    private function compare(ActivityEntry $left, ActivityEntry $right, SortOrder $order): int
    {
        $comparison = $left->loggedAt <=> $right->loggedAt;
        $comparison = 0 === $comparison ? strcmp($left->id, $right->id) : $comparison;

        return SortOrder::Ascending === $order ? $comparison : -$comparison;
    }

    private function isAfterCursor(ActivityEntry $entry, Cursor $cursor): bool
    {
        $comparison = $entry->loggedAt <=> $cursor->timestamp;
        $comparison = 0 === $comparison ? strcmp($entry->id, $cursor->lastId) : $comparison;

        return SortOrder::Ascending === $cursor->direction ? $comparison > 0 : $comparison < 0;
    }

    private function groupKey(ActivityEntry $entry, string $groupBy, string $interval): string
    {
        return match ($groupBy) {
            'channel'      => $entry->channel->value,
            'action'       => $entry->action,
            'severity'     => $entry->severity->value,
            'actor_type'   => $entry->actor->type ?? 'unknown',
            'actor'        => json_encode([$entry->actor->type ?? 'system', $entry->actor->id ?? ''], JSON_THROW_ON_ERROR),
            'subject_type' => $entry->subject->type ?? 'unknown',
            'trace_id'     => $entry->trace->traceId,
            'route'        => $entry->request->route ?? 'unknown',
            'status_code'  => (string) ($entry->request->statusCode ?? 0),
            'logged_at'    => $entry->loggedAt->format(match ($interval) {
                'hour'  => 'Y-m-d H:00',
                'month' => 'Y-m',
                default => 'Y-m-d',
            }),
            default => throw new InvalidArgumentException("Unknown activity group key [{$groupBy}]."),
        };
    }
}
