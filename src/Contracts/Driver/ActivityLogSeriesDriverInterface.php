<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Contracts\Driver;

use DateTimeImmutable;
use Dotswan\EagleEye\Data\Query\ActivityQuery;
use InvalidArgumentException;
use LengthException;

interface ActivityLogSeriesDriverInterface
{
    /**
     * Counts per aligned UTC bucket (and optional group), zero-filled.
     *
     * The `series` array is keyed `_total` when `$groupBy` is null; bucket
     * order matches the returned `buckets` list.
     *
     * @return array{buckets: list<DateTimeImmutable>, series: array<string, list<int>>}
     *
     * @throws InvalidArgumentException unknown group, unsupported step, or missing bounds
     * @throws LengthException buckets or buckets * groups exceed the published caps
     */
    public function aggregateSeries(ActivityQuery $query, ?string $groupBy, int $stepSeconds): array;
}
