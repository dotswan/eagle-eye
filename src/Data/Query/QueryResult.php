<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Data\Query;

use Dotswan\EagleEye\Data\ActivityEntry;

final readonly class QueryResult
{
    /** @param list<ActivityEntry> $entries */
    public function __construct(
        public array $entries,
        public ?Cursor $nextCursor = null,
        public bool $hasMore = false,
        public ?int $count = null,
        public bool $exactCount = true,
    ) {
    }

    public static function empty(): self
    {
        return new self(entries: [], count: 0);
    }
}
