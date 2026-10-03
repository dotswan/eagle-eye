<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Transport;

use Dotswan\EagleEye\Capture\CaptureSuppression;
use Dotswan\EagleEye\Contracts\Privacy\ErasureBarrierInterface;
use Dotswan\EagleEye\Contracts\Transport\TransportInterface;
use Dotswan\EagleEye\Data\ActivityEntry;
use RuntimeException;
use Throwable;

final class GuardedTransport implements TransportInterface
{
    private int $failures = 0;

    private bool $reported = false;

    public function __construct(
        private readonly TransportInterface $transport,
        private readonly ?SpoolTransport $spool,
        private readonly string $onFailure = 'spool',
        private readonly ?ErasureBarrierInterface $erasure = null,
    ) {
    }

    public function send(array $entries): void
    {
        if ($this->erasure instanceof ErasureBarrierInterface) {
            $entries = $this->erasure->allowed($entries);
        }

        if ([] === $entries) {
            return;
        }

        try {
            $this->transport->send($entries);

            return;
        } catch (Throwable $exception) {
            $this->failures++;
        }

        if ($exception instanceof BatchWriteException) {
            $entries = $exception->entries;
        }

        match ($this->onFailure) {
            'throw' => throw new RuntimeException('Eagle Eye transport failed.', 0, $exception),
            'spool' => $this->spool($entries, $exception),
            default => null,
        };
    }

    public function failures(): int
    {
        return $this->failures;
    }

    /** @param list<ActivityEntry> $entries */
    private function spool(array $entries, Throwable $exception): void
    {
        if (! $this->spool instanceof SpoolTransport) {
            return;
        }

        $this->spool->send($entries);
        $this->reportFallback($exception);
    }

    private function reportFallback(Throwable $exception): void
    {
        if ($this->reported) {
            return;
        }

        $this->reported = true;

        app(CaptureSuppression::class)->run(static function () use ($exception): void {
            report(new SpoolFallbackException(
                'Eagle Eye transport failed; entries were spooled for replay: '.$exception->getMessage(),
                0,
                $exception,
            ));
        });
    }
}
