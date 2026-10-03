<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Contracts\Repository;

use DateTimeImmutable;
use Dotswan\EagleEye\Data\ActivityEntry;
use Dotswan\EagleEye\Data\Query\ActivityQuery;
use Dotswan\EagleEye\Data\Query\QueryResult;

interface ActivityRepositoryInterface
{
    public function find(string $id): ?ActivityEntry;

    public function search(ActivityQuery $query): QueryResult;

    public function count(ActivityQuery $query): int;

    /** @return array<string, int> */
    public function aggregate(ActivityQuery $query, string $groupBy, string $interval = 'day'): array;

    /** @return array<string, int> */
    public function aggregateTop(ActivityQuery $query, string $groupBy, int $limit): array;

    /**
     * Counts per aligned UTC bucket (and optional group), zero-filled.
     *
     * @return array{buckets: list<DateTimeImmutable>, series: array<string, list<int>>}
     */
    public function aggregateSeries(ActivityQuery $query, ?string $groupBy, int $stepSeconds): array;

    /** @return list<string> */
    public function tags(?string $prefix = null, int $limit = 50): array;

    public function reveal(ActivityEntry $entry, string $path, ?object $viewer = null): string;
}
