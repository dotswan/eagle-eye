<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Drivers\Loki;

use Dotswan\EagleEye\Drivers\Loki\Writers\StdoutWriter;
use Dotswan\EagleEye\Drivers\Loki\Writers\SpoolWriter;
use Dotswan\EagleEye\Drivers\Loki\Writers\PushWriter;
use InvalidArgumentException;
use Generator;
use DateTimeImmutable;
use Dotswan\EagleEye\Contracts\Driver\FullActivityLogDriverInterface;
use Dotswan\EagleEye\Contracts\Driver\ActivityLogSeriesDriverInterface;
use Dotswan\EagleEye\Contracts\Driver\StoresFullActivityBodiesInterface;
use Dotswan\EagleEye\Data\ActivityEntry;
use Dotswan\EagleEye\Data\EnvelopeBudget;
use Dotswan\EagleEye\Data\ErasureResult;
use Dotswan\EagleEye\Data\Query\ActivityQuery;
use Dotswan\EagleEye\Data\Query\Cursor;
use Dotswan\EagleEye\Data\Query\QueryResult;
use Dotswan\EagleEye\Data\Series\SeriesWindow;
use Dotswan\EagleEye\Data\WriteFailure;
use Dotswan\EagleEye\Drivers\Loki\Reader\LokiClient;
use Dotswan\EagleEye\Drivers\Loki\Writers\LokiWriterInterface;
use Dotswan\EagleEye\Enums\Capability;
use Dotswan\EagleEye\Enums\SortOrder;
use RuntimeException;
use LengthException;
use Throwable;
use Dotswan\EagleEye\Contracts\Privacy\ErasureBarrierInterface;
use Dotswan\EagleEye\Contracts\Integrity\IntegrityChainInterface;

/**
 * Loki 3.x driver (schema v13, TSDB). Writes through one of the three
 * writer paths; reads go through the Loki HTTP query API with structured
 * metadata (or the `| json` fallback on older clusters).
 *
 * Loki is an at-least-once append log: the read path deduplicates by
 * entry id and pagination is client-side keyset on (Loki timestamp, id),
 * which is what makes the driver contract hold on top of it.
 */
final readonly class LokiDriver implements FullActivityLogDriverInterface, ActivityLogSeriesDriverInterface, StoresFullActivityBodiesInterface
{
    private const int HARD_SCAN_CAP = 1000;

    private const int READ_PAGE_ENTRIES = 100;

    /**
     * @param array<string, string> $staticLabels
     */
    public function __construct(
        private LokiWriterInterface $writer,
        private LokiClient $reader,
        private LokiLineFormatter $formatter,
        private LokiQueryTranslator $translator,
        private array $staticLabels,
        private int $defaultRangeDays = 30,
        private ?ErasureBarrierInterface $erasure = null,
        private ?IntegrityChainInterface $integrity = null,
    ) {
    }

    public function write(ActivityEntry $entry): void
    {
        $this->deliver([$entry]);
    }

    /** @param list<ActivityEntry> $entries */
    public function writeBatch(array $entries): array
    {
        if ([] === $entries) {
            return [];
        }

        EnvelopeBudget::batchSize($entries);

        try {
            $this->deliver($entries);

            return [];
        } catch (Throwable $throwable) {
            return array_map(
                static fn (ActivityEntry $entry): WriteFailure => new WriteFailure(
                    entryId: $entry->id,
                    message: $throwable->getMessage(),
                ),
                $entries,
            );
        }
    }

    /** @param list<ActivityEntry> $entries */
    private function deliver(array $entries): void
    {
        $write = function (array $allowed): void {
            if ([] !== $allowed) {
                $allowed = array_values($allowed);
                EnvelopeBudget::batchSize($allowed);
                $this->writer->write($this->formatter->streams($allowed));
            }
        };

        if ($this->erasure instanceof ErasureBarrierInterface) {
            $this->erasure->deliver($entries, $write, acknowledged: fn (): bool => $this->writer instanceof PushWriter ? $this->writer->acknowledged() : ! $this->writer instanceof StdoutWriter && ! $this->writer instanceof SpoolWriter);
        } else {
            $write($entries);
        }
    }

    public function name(): string
    {
        return 'loki';
    }

    public function healthy(): bool
    {
        return $this->writer instanceof StdoutWriter || $this->writer instanceof SpoolWriter
            ? $this->writer->healthy()
            : $this->reader->ready() && $this->writer->healthy();
    }

    public function supports(Capability $capability): bool
    {
        return in_array($capability, [
            Capability::FullTextSearch,
            Capability::Aggregation,
            Capability::RankedAggregation,
            Capability::SeriesAggregation,
        ], true);
    }

    public function find(string $id): ?ActivityEntry
    {
        $query = new ActivityQuery();
        [$startNs, $endNs] = $this->bounds($query);
        $data = $this->reader->queryRange(
            $this->translator->exactIdLogql($id, $this->staticLabels),
            10,
            $startNs,
            $endNs,
            'backward',
        );

        foreach ($this->assemble($data, 'backward')['entries'] as [$entry]) {
            if (hash_equals($id, $entry->id)) {
                return $entry;
            }
        }

        return null;
    }

    public function search(ActivityQuery $query): QueryResult
    {
        $query = clone $query;
        $rows = [];
        $boundary = $query->cursor instanceof Cursor
            ? [$query->cursor->timestamp, $query->cursor->lastId]
            : null;

        if ($query->cursor instanceof Cursor) {
            // Loki's range end is EXCLUSIVE at nanosecond precision; add one
            // microsecond to re-read the boundary timestamp inclusively.
            // Same-timestamp lines from the previous page are then dropped
            // by the id comparator below.
            if (SortOrder::Ascending === $query->sortOrder) {
                $query->from = $query->cursor->timestamp;
            } else {
                $query->to = $query->cursor->timestamp->modify('+1 microsecond');
            }
        }

        foreach ($this->fetch($query, $query->limit + 1, $boundary) as $row) {
            $rows[] = $row;

            if (count($rows) > $query->limit) {
                break;
            }
        }

        $hasMore = count($rows) > $query->limit;

        if ($hasMore) {
            array_pop($rows);
        }

        $last = [] === $rows ? null : $rows[array_key_last($rows)];
        $nextCursor = is_array($last) && $hasMore
            ? new Cursor($last[1], $last[0]->id, $query->sortOrder)
            : null;
        $entries = array_map(static fn (array $row): ActivityEntry => $row[0], $rows);

        return new QueryResult($entries, $nextCursor, $hasMore, exactCount: false);
    }

    public function count(ActivityQuery $query): int
    {
        [$startNs, $endNs] = $this->bounds($query);
        $rangeSeconds = max(60, intdiv(max(0, (int) $endNs - (int) $startNs), 1_000_000_000));
        $expression = $this->translator->countExpression($query, $this->staticLabels, $rangeSeconds);
        $count = 0;

        foreach ($this->reader->instant($expression, $endNs) as $series) {
            $value = is_array($series['value'] ?? null) ? ($series['value'][1] ?? '0') : '0';
            $count += (int) $value;
        }

        return $count;
    }

    /** @return array<string, int> */
    public function aggregate(ActivityQuery $query, string $groupBy, string $interval = 'day'): array
    {
        $this->validateAggregateGroup($groupBy);

        [$startNs, $endNs] = $this->bounds($query);
        $rangeSeconds = max(60, intdiv(max(0, (int) $endNs - (int) $startNs), 1_000_000_000));

        $expression = $this->translator->aggregateExpression($query, $this->staticLabels, $groupBy, $rangeSeconds);

        $result = [];
        foreach ($this->reader->instant($expression, $endNs) as $series) {
            $metric = is_array($series['metric'] ?? null) ? $series['metric'] : [];
            $value = is_array($series['value'] ?? null) ? ($series['value'][1] ?? '0') : '0';
            $key = $this->aggregateKey($metric, $groupBy);

            if (! isset($result[$key]) && count($result) >= EnvelopeBudget::MAX_AGGREGATE_GROUPS) {
                throw new LengthException('Activity aggregation exceeds its group limit.');
            }

            $result[$key] = (int) $value;
        }

        return $result;
    }

    public function aggregateTop(ActivityQuery $query, string $groupBy, int $limit): array
    {
        $this->validateAggregateGroup($groupBy);
        [$startNs, $endNs] = $this->bounds($query);
        $rangeSeconds = max(60, intdiv(max(0, (int) $endNs - (int) $startNs), 1_000_000_000));
        $expression = $this->translator->topAggregateExpression($query, $this->staticLabels, $groupBy, $rangeSeconds, EnvelopeBudget::MAX_AGGREGATE_GROUPS + 1);
        $result = [];

        $seriesList = $this->reader->instant($expression, $endNs);

        if (count($seriesList) > EnvelopeBudget::MAX_AGGREGATE_GROUPS + 1) {
            throw new LengthException('Loki ranking response exceeds its bounded group limit.');
        }

        foreach ($seriesList as $series) {
            $metric = is_array($series['metric'] ?? null) ? $series['metric'] : [];
            $value = is_array($series['value'] ?? null) ? ($series['value'][1] ?? '0') : '0';
            $key = $this->aggregateKey($metric, $groupBy);
            $result[$key] = (int) $value;
        }

        uksort($result, static fn (string $left, string $right): int => $result[$right] <=> $result[$left] ?: $left <=> $right);

        $effectiveLimit = max(1, min($limit, 100));
        $counts = array_values($result);

        if (
            count($seriesList) === EnvelopeBudget::MAX_AGGREGATE_GROUPS + 1
            && count($counts) > $effectiveLimit
            && $counts[$effectiveLimit - 1] === $counts[$effectiveLimit]
        ) {
            throw new LengthException('Loki ranking cutoff is ambiguous at the bounded group limit.');
        }

        return array_slice($result, 0, $effectiveLimit, true);
    }

    /**
     * @return array{buckets: list<DateTimeImmutable>, series: array<string, list<int>>}
     */
    public function aggregateSeries(ActivityQuery $query, ?string $groupBy, int $stepSeconds): array
    {
        if (null !== $groupBy) {
            $this->validateSeriesGroup($groupBy);
        }

        if (! $query->from instanceof DateTimeImmutable || ! $query->to instanceof DateTimeImmutable) {
            throw new InvalidArgumentException('Activity series aggregation requires an explicit from and to range.');
        }

        $window = SeriesWindow::fromQuery($query, $stepSeconds);
        $expression = $this->translator->seriesExpression($query, $this->staticLabels, $groupBy, $stepSeconds);
        $startNs = $this->translator->toNanoseconds($window->from->modify(sprintf('+%d seconds', $stepSeconds)));
        $endNs = $this->translator->toNanoseconds($window->to);
        $counts = [];

        foreach ($this->reader->metricRange($expression, $startNs, $endNs, $stepSeconds) as $series) {
            $metric = is_array($series['metric'] ?? null) ? $series['metric'] : [];
            $key = null === $groupBy ? '_total' : $this->aggregateKey($metric, $groupBy);

            if (! isset($counts[$key]) && count($counts) >= EnvelopeBudget::MAX_AGGREGATE_GROUPS) {
                throw new LengthException('Activity aggregation exceeds its group limit.');
            }

            $counts[$key] ??= [];

            foreach (is_array($series['values'] ?? null) ? $series['values'] : [] as $point) {
                if (! is_array($point)) {
                    continue;
                }

                $index = intdiv((int) ($point[0] ?? 0) - $window->bucketTimestamp(), $stepSeconds) - 1;

                if ($index < 0 || $index >= $window->bucketCount()) {
                    continue;
                }

                $counts[$key][$index] = (int) ($point[1] ?? 0);
            }
        }

        return $window->assemble($counts, null === $groupBy ? ['_total'] : []);
    }

    private function validateAggregateGroup(string $groupBy): void
    {
        $groupable = $this->translator->collectorMode()
            ? array_unique(array_merge(['channel', 'severity'], LokiLineFormatter::METADATA_KEYS, ['actor']))
            : array_unique(array_merge(LokiLabelGuard::ALLOWED, LokiLineFormatter::METADATA_KEYS, ['actor']));

        if (! in_array($groupBy, $groupable, true)) {
            throw new InvalidArgumentException(sprintf(
                'Loki aggregation group key [%s] is not supported. Allowed: %s.',
                $groupBy,
                implode(', ', $groupable),
            ));
        }
    }

    private function validateSeriesGroup(string $groupBy): void
    {
        $excluded = ['id', 'correlation_id', 'causation_id'];

        if (in_array($groupBy, $excluded, true)) {
            throw new InvalidArgumentException("Loki series group key [{$groupBy}] is not supported.");
        }

        $this->validateAggregateGroup($groupBy);
    }

    /** @param array<string, mixed> $metric */
    private function aggregateKey(array $metric, string $groupBy): string
    {
        if ('actor' === $groupBy) {
            return json_encode([
                (string) ($metric['ee_actor_type'] ?? $metric['actor_type'] ?? 'system'),
                (string) ($metric['ee_actor_id'] ?? $metric['actor_id'] ?? ''),
            ], JSON_THROW_ON_ERROR);
        }

        $prefixed = 'ee_'.$groupBy;

        return isset($metric[$prefixed])
            ? (string) $metric[$prefixed]
            : (isset($metric[$groupBy]) ? (string) $metric[$groupBy] : '_none_');
    }

    /** @return list<string> */
    public function tags(?string $prefix = null, int $limit = 50): array
    {
        $found = [];
        $needle = null !== $prefix ? mb_strtolower($prefix) : null;

        foreach ($this->fetch(new ActivityQuery(), 500) as [$entry]) {
            foreach ($entry->tags as $tag) {
                if (null !== $needle && ! str_starts_with(mb_strtolower($tag), $needle)) {
                    continue;
                }

                $found[$tag] = true;

                if (count($found) >= $limit) {
                    return array_keys($found);
                }
            }
        }

        return array_keys($found);
    }

    /**
     * Retention lives in Loki's limits_config; run `activity-log:loki-config`
     * to emit the matching YAML fragment. A local prune cannot reach a
     * remote cluster, so this is an honest no-op.
     */
    public function prune(DateTimeImmutable $before, ?ActivityQuery $scope = null): int
    {
        return 0;
    }

    /**
     * Loki deletion is asynchronous and stream-selector based; this submits
     * the compactor delete request over all retained history. The
     * request IDs are retained in the erasure result.
     */
    public function forget(string $subjectType, string $subjectId): ErasureResult
    {
        if ($this->writer instanceof StdoutWriter) {
            return ErasureResult::failed($this->name());
        }
        $startNs = '1970-01-01T00:00:00Z';
        $endNs = (new DateTimeImmutable())->format(DATE_RFC3339);
        $queries = [
            (new ActivityQuery())->forSubject($subjectType, $subjectId),
            (new ActivityQuery())->forActor($subjectType, $subjectId),
        ];

        $results = [];
        foreach ($queries as $query) {
            $logql = $this->translator->logql($query, $this->staticLabels);
            $requestId = $this->reader->requestDelete($logql, $startNs, $endNs);
            $results[] = null !== $requestId
                ? ErasureResult::pending($this->name(), [$requestId])
                : ErasureResult::failed($this->name());
        }

        $result = ErasureResult::combine($results);
        if ($result->accepted()) {
            $this->integrity?->authorizeErasure($subjectType, $subjectId);
        }
        return $result;
    }

    public function install(): void
    {
        // Loki installs and migrates itself.
    }

    /** @return array{entries: int, bytes: int, oldest: ?DateTimeImmutable} */
    public function stats(): array
    {
        try {
            $entries = $this->count(new ActivityQuery());
        } catch (Throwable) {
            $entries = 0;
        }

        return ['entries' => $entries, 'bytes' => 0, 'oldest' => null];
    }

    /**
     * Yields [ActivityEntry, DateTimeImmutable boundaryTs] pages as a flat,
     * deterministically ordered, id-deduplicated list.
     *
     * @param ?array{0: DateTimeImmutable, 1: string} $boundary (timestamp, id) of the previous page cursor; entries at or before it are skipped here so $softLimit counts only post-boundary results
     * @return Generator<int, array{0: ActivityEntry, 1: DateTimeImmutable}>
     */
    private function fetch(ActivityQuery $query, int $softLimit, ?array $boundary = null): Generator
    {
        $logql = $this->translator->logql($query, $this->staticLabels);
        [$startNs, $endNs] = $this->bounds($query);
        $direction = SortOrder::Ascending === $query->sortOrder ? 'forward' : 'backward';
        $seen = [];
        $candidates = [];
        $boundaryIds = [];
        $previousTimestamp = null;
        $completeThrough = null;
        $pages = 0;
        $requestLimit = max(1, min($softLimit, self::READ_PAGE_ENTRIES));
        $deadline = $this->reader->deadline();

        while ($pages < self::HARD_SCAN_CAP) {
            $pages++;
            try {
                $data = $this->reader->queryRange($this->translator->excludingIds($logql, $boundaryIds), $requestLimit, $startNs, $endNs, $direction, $deadline);
            } catch (Throwable $exception) {
                if ($requestLimit < 2 || ! $this->exceededReadBudget($exception)) {
                    throw $exception;
                }
                $requestLimit = max(1, intdiv($requestLimit, 2));
                continue;
            }
            $assembled = $this->assemble($data, $direction);
            $batch = $this->sorted($assembled['entries'], $query->sortOrder);
            $lines = $assembled['lines'];
            $edge = $assembled['edge'];
            $edgeNanoseconds = $assembled['edge_nanoseconds'];

            if ([] === $batch) {
                if ($lines >= $requestLimit && null !== $edgeNanoseconds) {
                    $boundaryIds = [];

                    if ('backward' === $direction) {
                        $endNs = $edgeNanoseconds;
                    } else {
                        $startNs = (string) ((int) $edgeNanoseconds + 1);
                    }

                    continue;
                }

                yield from $this->sorted(array_values($candidates), $query->sortOrder);
                return;
            }

            $edge ??= $batch[array_key_last($batch)][1];

            foreach ($batch as [$entry, $lokiTimestamp]) {
                if (null !== $completeThrough && $lokiTimestamp->format('U.u') !== $completeThrough) {
                    yield from $this->sorted(array_values($candidates), $query->sortOrder);
                    return;
                }

                if (isset($seen[$entry->id])) {
                    continue;
                }

                if (null !== $boundary
                    && $lokiTimestamp->format('U.u') === $boundary[0]->format('U.u')
                    && $this->beforeOrAt($entry->id, $boundary[1], $query->sortOrder)
                ) {
                    continue;
                }

                $seen[$entry->id] = true;
                $candidates[$entry->id] = [$entry, $lokiTimestamp];
            }

            $sorted = array_slice($this->sorted(array_values($candidates), $query->sortOrder), 0, $softLimit);
            $candidates = [];
            foreach ($sorted as [$candidate, $lokiTimestamp]) {
                $candidates[$candidate->id] = [$candidate, $lokiTimestamp];
            }

            if ($lines < $requestLimit) {
                yield from $sorted;
                return;
            }

            $timestamp = $edge;
            if ($previousTimestamp !== $timestamp->format('U.u')) {
                $boundaryIds = [];
            }
            $previousBoundaryCount = count($boundaryIds);
            foreach ($batch as [$entry, $lokiTimestamp]) {
                if ($lokiTimestamp->format('U.u') === $timestamp->format('U.u')) {
                    $boundaryIds[] = $entry->id;
                }
            }
            $boundaryIds = array_values(array_unique($boundaryIds));

            if ($previousTimestamp === $timestamp->format('U.u') && count($boundaryIds) === $previousBoundaryCount) {
                throw new RuntimeException('Loki pagination cannot advance past a repeated timestamp page; results are incomplete.');
            }

            if (count($boundaryIds) > EnvelopeBudget::MAX_AGGREGATE_GROUPS) {
                throw new RuntimeException('Loki pagination exceeds its bounded timestamp cardinality; results are incomplete.');
            }
            $previousTimestamp = $timestamp->format('U.u');
            if (count($candidates) >= $softLimit) {
                $completeThrough = $timestamp->format('U.u');
                $startNs = $this->formatter->nanoseconds($timestamp);
                $endNs = (string) ((int) $startNs + 1000);
                continue;
            }

            // Re-issue with the boundary INCLUSIVE on both ends (Loki's
            // range end is exclusive, its start inclusive); the id dedupe
            // set keeps re-read boundary lines from producing duplicates.
            if ('backward' === $direction) {
                $endNs = (string) ((int) $this->formatter->nanoseconds($timestamp) + 1000);
            } else {
                $startNs = $this->formatter->nanoseconds($timestamp);
            }
        }

        throw new RuntimeException('Loki pagination exceeded its bounded backend scan limit; results are incomplete.');
    }

    private function exceededReadBudget(Throwable $exception): bool
    {
        do {
            if ($exception instanceof LokiWriteException && str_contains($exception->getMessage(), 'decode limit')) {
                return true;
            }
            $exception = $exception->getPrevious();
        } while ($exception instanceof Throwable);

        return false;
    }

    /**
     * @param array<string, mixed> $data
     * @return array{entries: list<array{0: ActivityEntry, 1: DateTimeImmutable}>, lines: int, edge: ?DateTimeImmutable, edge_nanoseconds: ?string}
     */
    private function assemble(array $data, string $direction): array
    {
        $entries = [];
        $lines = 0;
        $edge = null;
        $edgeNanoseconds = null;
        $result = $data['result'] ?? [];

        if (! is_array($result)) {
            return ['entries' => [], 'lines' => 0, 'edge' => null, 'edge_nanoseconds' => null];
        }

        foreach ($result as $stream) {
            if (! is_array($stream)) {
                continue;
            }

            $values = $stream['values'] ?? [];

            if (! is_array($values)) {
                continue;
            }

            foreach ($values as $value) {
                $timestamp = is_array($value) && isset($value[0]) ? (string) $value[0] : null;
                $line = is_array($value) && isset($value[1]) ? (string) $value[1] : null;

                if (null === $line) {
                    continue;
                }

                $lines++;

                try {
                    $lokiTimestamp = $this->formatter->fromNanoseconds((string) $timestamp);
                } catch (Throwable) {
                    continue;
                }

                if (! $edge instanceof DateTimeImmutable
                    || ('backward' === $direction && (int) $timestamp < (int) $edgeNanoseconds)
                    || ('forward' === $direction && (int) $timestamp > (int) $edgeNanoseconds)
                ) {
                    $edge = $lokiTimestamp;
                    $edgeNanoseconds = $timestamp;
                }

                try {
                    $decoded = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                } catch (Throwable) {
                    continue;
                }

                if (! is_array($decoded)) {
                    continue;
                }

                try {
                    $entries[] = [ActivityEntry::fromStorage($decoded), $lokiTimestamp];
                } catch (Throwable) {
                    continue;
                }
            }
        }

        return ['entries' => $entries, 'lines' => $lines, 'edge' => $edge, 'edge_nanoseconds' => $edgeNanoseconds];
    }

    /**
     * @param list<array{0: ActivityEntry, 1: DateTimeImmutable}> $batch
     * @return list<array{0: ActivityEntry, 1: DateTimeImmutable}>
     */
    private function sorted(array $batch, SortOrder $order): array
    {
        usort($batch, static function (array $a, array $b) use ($order): int {
            $ts = $a[1] <=> $b[1];
            $cmp = 0 !== $ts ? $ts : strcmp($a[0]->id, $b[0]->id);

            return SortOrder::Ascending === $order ? $cmp : -$cmp;
        });

        return $batch;
    }

    private function beforeOrAt(string $id, string $boundaryId, SortOrder $order): bool
    {
        $cmp = strcmp($id, $boundaryId);

        return SortOrder::Ascending === $order ? $cmp <= 0 : $cmp >= 0;
    }

    /** @return array{0: string, 1: string} */
    private function bounds(ActivityQuery $query): array
    {
        $to = $query->to ?? new DateTimeImmutable();
        $from = $query->from ?? $to->modify(sprintf('-%d days', $this->defaultRangeDays));

        return [$this->translator->toNanoseconds($from), $this->translator->toNanoseconds($to)];
    }
}
