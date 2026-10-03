<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Console;

use Dotswan\EagleEye\Contracts\Driver\ActivityLogDriverFactoryInterface;
use Dotswan\EagleEye\Data\ActivityEntry;
use Dotswan\EagleEye\Data\EnvelopeBudget;
use Dotswan\EagleEye\Transport\SpoolFile;
use Dotswan\EagleEye\Transport\BatchWriteException;
use Dotswan\EagleEye\Transport\BatchWriter;
use Illuminate\Console\Command;
use Dotswan\EagleEye\Privacy\ErasureRegistry;
use Throwable;

final class ShipSpoolCommand extends Command
{
    protected $signature = 'activity-log:ship {--chunk=500}';

    protected $description = 'Ship spooled Eagle Eye activity entries to the configured driver.';

    public function handle(ActivityLogDriverFactoryInterface $drivers, ErasureRegistry $erasure): int
    {
        $path = $this->spoolPath();

        $chunkSize = max(1, min((int) $this->option('chunk'), 5_000));

        try {
            $spool = new SpoolFile($path, max(1, (int) config('eagle-eye.transport.spool.max_bytes', 10_485_760)));
            $shipped = $spool->ship(
                static function (array $rows) use ($drivers): array {
                    try {
                        BatchWriter::write($drivers->driver(), array_map(ActivityEntry::fromStorage(...), $rows));

                        return [];
                    } catch (BatchWriteException $exception) {
                        return array_map(static fn (ActivityEntry $entry): array => $entry->toStorage(), $exception->entries);
                    }
                },
                $chunkSize,
                static function (array $row) use ($erasure): bool {
                    $entry = ActivityEntry::fromStorage($row);
                    EnvelopeBudget::size($entry);

                    return $erasure->allows($entry);
                },
            );
            $this->info("Shipped {$shipped} Eagle Eye entries from the spool.");

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('Shipping failed; unacknowledged entries remain in the spool: '.$exception->getMessage());

            return self::FAILURE;
        }
    }

    private function spoolPath(): string
    {
        $path = config('eagle-eye.transport.spool.path');

        if (is_string($path) && '' !== $path) {
            return $path;
        }

        return storage_path('eagle-eye/spool.log');
    }
}
