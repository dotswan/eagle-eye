<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Transport;

use Dotswan\EagleEye\Data\ActivityEntry;
use RuntimeException;
use Throwable;

final class BatchWriteException extends RuntimeException
{
    /** @param list<ActivityEntry> $entries */
    public function __construct(public readonly array $entries, string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
