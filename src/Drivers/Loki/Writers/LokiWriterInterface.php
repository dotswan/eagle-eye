<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Drivers\Loki\Writers;

use Dotswan\EagleEye\Drivers\Loki\LokiStream;

/**
 * Ships already-grouped streams to their destination. Receives completely
 * formatted data so every path writes the identical representation.
 */
interface LokiWriterInterface
{
    /** @param list<LokiStream> $streams */
    public function write(array $streams): void;

    public function healthy(): bool;
}
