<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Transport;

use Dotswan\EagleEye\Data\ActivityEntry;
use Dotswan\EagleEye\Data\EnvelopeBudget;
use InvalidArgumentException;
use LengthException;

final class ActivityBuffer
{
    /** @var list<ActivityEntry> */
    private array $entries = [];

    /** @var list<int> */
    private array $sizes = [];

    private int $bytes = 0;

    public function __construct(
        private readonly int $capacity = 100,
        private readonly int $maxBytes = EnvelopeBudget::MAX_BUFFER_BYTES,
    ) {
        if ($this->capacity < 1 || $this->capacity > 100) {
            throw new InvalidArgumentException('Activity buffer capacity must be between 1 and 100 entries.');
        }

        if ($this->maxBytes < 1 || $this->maxBytes > EnvelopeBudget::MAX_BUFFER_BYTES) {
            throw new InvalidArgumentException('Activity buffer byte limit must be between 1 byte and 2 MiB.');
        }
    }

    public function push(ActivityEntry $entry): bool
    {
        $size = EnvelopeBudget::size($entry);

        if ($this->bytes + $size > $this->maxBytes) {
            throw new LengthException('Activity buffer byte limit exceeded.');
        }

        $this->entries[] = $entry;
        $this->sizes[] = $size;
        $this->bytes += $size;

        return count($this->entries) >= $this->capacity || $this->bytes >= $this->maxBytes;
    }

    public function wouldOverflow(ActivityEntry $entry): bool
    {
        return [] !== $this->entries && $this->bytes + EnvelopeBudget::size($entry) > $this->maxBytes;
    }

    /** @return list<ActivityEntry> */
    public function drain(): array
    {
        $entries = $this->entries;
        $this->entries = [];
        $this->sizes = [];
        $this->bytes = 0;

        return $entries;
    }

    /** @return list<ActivityEntry> */
    public function drainBatch(
        int $maxEntries = 100,
        int $maxBytes = EnvelopeBudget::MAX_BATCH_BYTES,
    ): array {
        if ($maxEntries < 1 || $maxEntries > 100 || $maxBytes < 1 || $maxBytes > EnvelopeBudget::MAX_BATCH_BYTES) {
            throw new InvalidArgumentException('Activity delivery batch limits are invalid.');
        }

        $count = 0;
        $bytes = 2;

        foreach ($this->sizes as $size) {
            $nextBytes = $bytes + $size + (0 === $count ? 0 : 1);

            if ($count >= $maxEntries || $nextBytes > $maxBytes) {
                break;
            }

            $count++;
            $bytes = $nextBytes;
        }

        if (0 === $count && [] !== $this->entries) {
            throw new LengthException('Buffered activity cannot fit within a delivery batch.');
        }

        $entries = array_splice($this->entries, 0, $count);
        $sizes = array_splice($this->sizes, 0, $count);
        $this->bytes -= array_sum($sizes);

        return $entries;
    }

    public function pending(): int
    {
        return count($this->entries);
    }

    public function pendingBytes(): int
    {
        return $this->bytes;
    }
}
