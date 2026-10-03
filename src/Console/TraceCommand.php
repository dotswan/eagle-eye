<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Console;

use Dotswan\EagleEye\Data\ActivityEntry;
use Dotswan\EagleEye\Contracts\Repository\ActivityRepositoryInterface;
use Dotswan\EagleEye\Data\Query\Cursor;
use Dotswan\EagleEye\Data\Query\ActivityQuery;
use Dotswan\EagleEye\Enums\SortOrder;

final class TraceCommand extends EagleEyeCommand
{
    private const int PAGE_ENTRIES = 15;

    protected $signature = 'activity-log:trace {id}';

    protected $description = 'Print one correlated activity trace in causal order.';

    public function handle(ActivityRepositoryInterface $repository): int
    {
        $query = (new ActivityQuery())
            ->forTrace($this->stringArgument('id'))
            ->orderByTime(SortOrder::Ascending)
            ->limit(self::PAGE_ENTRIES);
        $count = 0;

        do {
            $result = $repository->search($query);
            $this->table(['ID', 'Cause', 'Time', 'Action', 'Title'], array_map(
                static fn (ActivityEntry $entry): array => [
                    $entry->id,
                    $entry->trace->causationId ?? '-',
                    $entry->loggedAt->format(DATE_ATOM),
                    $entry->action,
                    $entry->title,
                ],
                $result->entries,
            ));
            $count += count($result->entries);

            if ($result->hasMore && ! $result->nextCursor instanceof Cursor) {
                $this->error("Trace incomplete after {$count} entries: the backend supplied no continuation cursor.");

                return self::FAILURE;
            }

            $query->cursor($result->nextCursor);
        } while ($result->hasMore);

        $this->info("Trace complete: {$count} entries.");

        return 0 === $count ? self::FAILURE : self::SUCCESS;
    }
}
