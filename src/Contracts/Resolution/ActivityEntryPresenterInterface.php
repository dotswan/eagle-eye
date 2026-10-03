<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Contracts\Resolution;

use Dotswan\EagleEye\Data\ActivityEntry;

interface ActivityEntryPresenterInterface
{
    /**
     * @param list<ActivityEntry> $entries
     * @return list<ActivityEntry>
     */
    public function present(array $entries): array;

    public function presentOne(ActivityEntry $entry): ActivityEntry;
}
