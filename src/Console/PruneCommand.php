<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Console;

use Dotswan\EagleEye\Retention\RetentionPruner;
use Illuminate\Console\Command;
use Throwable;

final class PruneCommand extends Command
{
    protected $signature = 'activity-log:prune';

    protected $description = 'Prune activity entries using configured retention rules.';

    public function handle(RetentionPruner $pruner): int
    {
        try {
            $count = $pruner->prune();
            $this->info("Pruned {$count} activity entry or entries.");

            return self::SUCCESS;
        } catch (Throwable $throwable) {
            $this->error($throwable->getMessage());

            return self::FAILURE;
        }
    }
}
