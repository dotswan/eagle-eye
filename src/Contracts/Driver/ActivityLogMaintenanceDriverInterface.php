<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Contracts\Driver;

use DateTimeImmutable;
use Dotswan\EagleEye\Data\ErasureResult;
use Dotswan\EagleEye\Data\Query\ActivityQuery;

interface ActivityLogMaintenanceDriverInterface
{
    public function prune(DateTimeImmutable $before, ?ActivityQuery $scope = null): int;

    public function forget(string $subjectType, string $subjectId): ErasureResult;

    public function install(): void;

    /** @return array{entries: int, bytes: int, oldest: ?DateTimeImmutable} */
    public function stats(): array;
}
