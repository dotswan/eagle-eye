<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Transport\Jobs;

use Dotswan\EagleEye\Capture\CaptureSuppression;
use Dotswan\EagleEye\Contracts\Driver\ActivityLogDriverFactoryInterface;
use Dotswan\EagleEye\Contracts\Privacy\ErasureBarrierInterface;
use Dotswan\EagleEye\Data\ActivityEntry;
use Dotswan\EagleEye\Transport\BatchWriteException;
use Dotswan\EagleEye\Transport\BatchWriter;
use Dotswan\EagleEye\Transport\SpoolTransport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Throwable;

final class PersistActivityBatchJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public bool $failOnTimeout = true;

    /** @var list<int> */
    public array $backoff = [1, 5, 15];

    /** @param list<array<string, mixed>> $rows */
    public function __construct(private readonly array $rows)
    {
    }

    public function handle(ActivityLogDriverFactoryInterface $drivers, ErasureBarrierInterface $erasure): void
    {
        $entries = array_map(ActivityEntry::fromStorage(...), $this->rows);
        app(CaptureSuppression::class)->run(static function () use ($drivers, $entries, $erasure): void {
            $entries = $erasure->allowed($entries);

            if ([] === $entries) {
                return;
            }

            BatchWriter::write($drivers->driver(), $entries);
        });
    }

    public function failed(?Throwable $exception): void
    {
        if ('spool' !== (string) config('eagle-eye.transport.on_failure', 'spool')) {
            return;
        }

        $entries = $exception instanceof BatchWriteException
            ? $exception->entries
            : array_map(ActivityEntry::fromStorage(...), $this->rows);
        $path = (string) (config('eagle-eye.transport.spool.path') ?: storage_path('eagle-eye/spool.log'));
        $maxBytes = max(65_536, min(536_870_912, (int) config('eagle-eye.transport.spool.max_bytes', 10_485_760)));

        app(CaptureSuppression::class)->run(static function () use ($entries, $path, $maxBytes): void {
            (new SpoolTransport($path, $maxBytes, app(ErasureBarrierInterface::class)))->send($entries);
        });
    }
}
