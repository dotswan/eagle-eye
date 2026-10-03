<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Contracts\Integrity;

use DateTimeImmutable;
use Dotswan\EagleEye\Data\ActivityEntry;
use Dotswan\EagleEye\Data\Query\ActivityQuery;
use Illuminate\Database\Connection;

interface IntegrityChainInterface
{
    public function seal(ActivityEntry $entry): string;

    /** @param list<ActivityEntry> $entries */
    public function transitionEntries(array $entries, string $state): void;

    /** @param list<string> $ids */
    public function transitionIds(array $ids, string $state): void;

    public function assertRemovalConnection(Connection $connection): void;

    public function authorizeErasure(string $type, string $id): void;

    public function authorizePrune(DateTimeImmutable $before, ?ActivityQuery $scope = null): void;
}
