<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Testing;

use Dotswan\EagleEye\Data\ActivityEntry;
use Dotswan\EagleEye\Drivers\MemoryDriver;
use PHPUnit\Framework\Assert;

final readonly class EagleEyeFake
{
    public function __construct(private MemoryDriver $driver)
    {
    }

    /** @param callable(ActivityEntry): bool $callback */
    public function assertLogged(callable $callback): void
    {
        $matched = false;

        foreach ($this->driver->entries() as $entry) {
            if ($callback($entry)) {
                $matched = true;

                break;
            }
        }

        Assert::assertTrue($matched, 'Failed asserting that a matching Eagle Eye activity was logged.');
    }

    /** @param callable(ActivityEntry): bool $callback */
    public function assertNotLogged(callable $callback): void
    {
        $matched = false;

        foreach ($this->driver->entries() as $entry) {
            if ($callback($entry)) {
                $matched = true;

                break;
            }
        }

        Assert::assertFalse($matched, 'Failed asserting that no matching Eagle Eye activity was logged.');
    }

    public function assertCount(int $count): void
    {
        Assert::assertCount($count, $this->driver->entries());
    }

    public function assertTagged(string $tag): void
    {
        $this->assertLogged(static fn (ActivityEntry $entry): bool => in_array($tag, $entry->tags, true));
    }

    /** @return list<ActivityEntry> */
    public function recorded(): array
    {
        return $this->driver->entries();
    }
}
