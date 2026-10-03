<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Transport;

use Dotswan\EagleEye\Contracts\Driver\ActivityLogDriverInterface;
use Dotswan\EagleEye\Data\ActivityEntry;
use Dotswan\EagleEye\Data\EnvelopeBudget;
use Throwable;

final class BatchWriter
{
    /** @param list<ActivityEntry> $entries */
    public static function write(ActivityLogDriverInterface $driver, array $entries): void
    {
        $indexed = [];

        foreach ($entries as $entry) {
            $indexed[$entry->id] = $entry;
        }

        EnvelopeBudget::batchSize(array_values($indexed));

        try {
            $failures = $driver->writeBatch(array_values($indexed));
        } catch (BatchWriteException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new BatchWriteException(array_values($indexed), 'Eagle Eye driver failed to write the delivery batch.', $exception);
        }
        $failed = [];

        try {
            foreach ($failures as $failure) {
                if (! isset($indexed[$failure->entryId]) || isset($failed[$failure->entryId])) {
                    throw new BatchWriteException(array_values($indexed), 'Eagle Eye driver returned an invalid batch acknowledgement.');
                }

                $failed[$failure->entryId] = $indexed[$failure->entryId];
            }
        } catch (BatchWriteException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new BatchWriteException(array_values($indexed), 'Eagle Eye driver returned an invalid batch acknowledgement.', $exception);
        }

        if ([] !== $failed) {
            throw new BatchWriteException(array_values($failed), 'Eagle Eye driver rejected '.count($failed).' entries.');
        }
    }
}
