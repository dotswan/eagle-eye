<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Contracts\Driver;

use Dotswan\EagleEye\Data\ActivityEntry;
use Dotswan\EagleEye\Data\Query\ActivityQuery;
use Dotswan\EagleEye\Data\Query\QueryResult;

interface ActivityLogQueryDriverInterface
{
    public function find(string $id): ?ActivityEntry;

    public function search(ActivityQuery $query): QueryResult;

    public function count(ActivityQuery $query): int;

    /** @return array<string, int> */
    public function aggregate(ActivityQuery $query, string $groupBy, string $interval = 'day'): array;

    /** @return array<string, int> */
    public function aggregateTop(ActivityQuery $query, string $groupBy, int $limit): array;

    /** @return list<string> */
    public function tags(?string $prefix = null, int $limit = 50): array;
}
