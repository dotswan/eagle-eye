<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Drivers;

use DateTimeImmutable;
use Dotswan\EagleEye\Contracts\Driver\FullActivityLogDriverInterface;
use Dotswan\EagleEye\Contracts\Driver\ActivityLogSeriesDriverInterface;
use Dotswan\EagleEye\Data\ActivityEntry;
use Dotswan\EagleEye\Data\EnvelopeBudget;
use Dotswan\EagleEye\Data\ErasureResult;
use Dotswan\EagleEye\Data\Query\ActivityQuery;
use Dotswan\EagleEye\Data\Query\QueryResult;
use Dotswan\EagleEye\Data\Series\SeriesWindow;
use Dotswan\EagleEye\Enums\Capability;

final class NullDriver implements FullActivityLogDriverInterface, ActivityLogSeriesDriverInterface
{
    public function write(ActivityEntry $entry): void
    {
        EnvelopeBudget::size($entry);
    }

    public function writeBatch(array $entries): array
    {
        EnvelopeBudget::batchSize($entries);

        return [];
    }

    public function name(): string
    {
        return 'null';
    }

    public function healthy(): bool
    {
        return true;
    }

    public function supports(Capability $capability): bool
    {
        return match ($capability) {
            Capability::FullTextSearch,
            Capability::LiveTail,
            Capability::JsonFieldQuery,
            Capability::RankedAggregation => false,
            Capability::ExactCount,
            Capability::UnboundedTimeRange,
            Capability::SynchronousDeletion,
            Capability::Aggregation,
            Capability::SeriesAggregation => true,
        };
    }

    public function find(string $id): ?ActivityEntry
    {
        return null;
    }

    public function search(ActivityQuery $query): QueryResult
    {
        return QueryResult::empty();
    }

    public function count(ActivityQuery $query): int
    {
        return 0;
    }

    public function aggregate(ActivityQuery $query, string $groupBy, string $interval = 'day'): array
    {
        return [];
    }

    public function aggregateTop(ActivityQuery $query, string $groupBy, int $limit): array
    {
        return [];
    }

    /**
     * @return array{buckets: list<DateTimeImmutable>, series: array<string, list<int>>}
     */
    public function aggregateSeries(ActivityQuery $query, ?string $groupBy, int $stepSeconds): array
    {
        $window = SeriesWindow::fromQuery($query, $stepSeconds);

        return $window->assemble([], null === $groupBy ? ['_total'] : []);
    }

    public function tags(?string $prefix = null, int $limit = 50): array
    {
        return [];
    }

    public function prune(DateTimeImmutable $before, ?ActivityQuery $scope = null): int
    {
        return 0;
    }

    public function forget(string $subjectType, string $subjectId): ErasureResult
    {
        return ErasureResult::failed($this->name());
    }

    public function install(): void
    {
    }

    public function stats(): array
    {
        return ['entries' => 0, 'bytes' => 0, 'oldest' => null];
    }
}
