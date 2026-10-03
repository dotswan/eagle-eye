<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Tests\Support;

use Dotswan\EagleEye\Contracts\Driver\ActivityLogDriverInterface;
use Dotswan\EagleEye\Data\ActivityEntry;
use Dotswan\EagleEye\Enums\Capability;
use RuntimeException;

final class AlwaysFailingDriver implements ActivityLogDriverInterface
{
    public function write(ActivityEntry $entry): void
    {
        throw new RuntimeException('storage is down');
    }

    public function writeBatch(array $entries): array
    {
        throw new RuntimeException('storage is down');
    }

    public function name(): string
    {
        return 'failing';
    }

    public function healthy(): bool
    {
        return false;
    }

    public function supports(Capability $capability): bool
    {
        return false;
    }
}
