<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Console;

use Dotswan\EagleEye\Contracts\Repository\ActivityRepositoryInterface;
use Dotswan\EagleEye\Data\Query\ActivityQuery;
use Illuminate\Console\Command;

final class TailCommand extends Command
{
    protected $signature = 'activity-log:tail {--limit=20} {--follow}';

    protected $description = 'Print newest activity entries; continuous mode requires live-tail support.';

    public function handle(ActivityRepositoryInterface $repository): int
    {
        if ((bool) $this->option('follow')) {
            $this->error('Continuous live tail is not implemented by the current driver contract.');

            return self::FAILURE;
        }

        $entries = $repository->search(
            (new ActivityQuery())->limit(max(1, min((int) $this->option('limit'), 500))),
        )->entries;

        foreach ($entries as $entry) {
            $this->line(sprintf('%s %s %s', $entry->loggedAt->format(DATE_ATOM), $entry->channel->value, $entry->title));
        }

        return self::SUCCESS;
    }
}
