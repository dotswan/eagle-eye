<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Exceptions;

use RuntimeException;

final class SecretRevealDeniedException extends RuntimeException
{
    public function __construct(string $entryId, string $path)
    {
        parent::__construct("Revealing [{$path}] on activity entry [{$entryId}] is denied by the log access policy.");
    }
}
