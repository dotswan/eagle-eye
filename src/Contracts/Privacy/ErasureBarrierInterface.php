<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Contracts\Privacy;

use Closure;
use Dotswan\EagleEye\Data\ActivityEntry;
use Illuminate\Database\Connection;

interface ErasureBarrierInterface
{
    public function allows(ActivityEntry $entry): bool;

    /** @param list<ActivityEntry> $entries
     *  @return list<ActivityEntry>
     */
    public function allowed(array $entries): array;

    /** @param list<ActivityEntry> $entries
     *  @param Closure(list<ActivityEntry>): void $write
     *  @param bool|Closure(): bool $acknowledged
     */
    public function deliver(array $entries, Closure $write, ?Connection $connection = null, bool|Closure $acknowledged = true): void;

    public function deliveryBlocked(): bool;
}
