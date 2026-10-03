<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Console;

use Dotswan\EagleEye\Contracts\Driver\ActivityLogDriverFactoryInterface;
use Dotswan\EagleEye\Contracts\Driver\ActivityLogMaintenanceDriverInterface;
use Dotswan\EagleEye\Contracts\Repository\ActivityRepositoryInterface;
use Dotswan\EagleEye\Data\Query\ActivityQuery;

final class StatsCommand extends EagleEyeCommand
{
    protected $signature = 'activity-log:stats {--group-by=channel} {--interval=day}';

    protected $description = 'Show activity storage and aggregation statistics.';

    public function handle(ActivityLogDriverFactoryInterface $drivers, ActivityRepositoryInterface $repository): int
    {
        $driver = $drivers->driver();
        $stats = $driver instanceof ActivityLogMaintenanceDriverInterface
            ? $driver->stats()
            : ['entries' => 0, 'bytes' => 0, 'oldest' => null];

        $this->table(['Driver', 'Entries', 'Payload bytes', 'Oldest'], [[
            $driver->name(),
            $stats['entries'],
            $stats['bytes'],
            $stats['oldest']?->format(DATE_ATOM) ?? '-',
        ]]);

        $groups = $repository->aggregate(
            new ActivityQuery(),
            $this->stringOption('group-by', 'channel'),
            $this->stringOption('interval', 'day'),
        );
        $this->table(['Group', 'Entries'], array_map(
            static fn (string $key, int $count): array => [$key, $count],
            array_keys($groups),
            array_values($groups),
        ));

        return self::SUCCESS;
    }
}
