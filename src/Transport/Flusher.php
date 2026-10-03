<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Transport;

use Dotswan\EagleEye\Capture\CaptureSuppression;
use Dotswan\EagleEye\Contracts\Privacy\ErasureBarrierInterface;
use Dotswan\EagleEye\Contracts\Transport\TransportInterface;

final readonly class Flusher
{
    public function __construct(
        private ActivityBuffer $buffer,
        private TransportInterface $transport,
        private ?ErasureBarrierInterface $erasure = null,
        private ?FlushDeferral $deferral = null,
    ) {
    }

    public function flush(): int
    {
        if (true === $this->erasure?->deliveryBlocked() && $this->deferral instanceof FlushDeferral) {
            $this->deferral->schedule(function (): void {
                $this->flushNow();
            });

            return 0;
        }

        return $this->flushNow();
    }

    private function flushNow(): int
    {
        $flushed = 0;

        while ([] !== ($entries = $this->buffer->drainBatch())) {
            app(CaptureSuppression::class)->run(function () use ($entries): void {
                $this->transport->send($entries);
            });

            $flushed += count($entries);
        }

        return $flushed;
    }
}
