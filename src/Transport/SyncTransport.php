<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Transport;

use Closure;
use Dotswan\EagleEye\Contracts\Driver\ActivityLogDriverInterface;
use Dotswan\EagleEye\Contracts\Transport\TransportInterface;

final readonly class SyncTransport implements TransportInterface
{
    /** @param Closure(): ActivityLogDriverInterface $driver */
    public function __construct(private Closure $driver)
    {
    }

    public function send(array $entries): void
    {
        BatchWriter::write(($this->driver)(), $entries);
    }
}
