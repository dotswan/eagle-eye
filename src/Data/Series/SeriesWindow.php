<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Data\Series;

use DateTimeImmutable;
use DateTimeZone;
use Dotswan\EagleEye\Data\EnvelopeBudget;
use Dotswan\EagleEye\Data\Query\ActivityQuery;
use InvalidArgumentException;
use LengthException;

/**
 * Aligned UTC time window for series aggregation. Buckets are aligned to
 * multiples of the step from the Unix epoch; the window is widened so the
 * first and last buckets fully contain the requested range.
 *
 * @internal
 */
final readonly class SeriesWindow
{
    /** @var list<int> */
    public const array STEPS = [300, 3600, 21600, 86400];

    /**
     * @param  list<DateTimeImmutable>  $buckets
     */
    private function __construct(
        public DateTimeImmutable $from,
        public DateTimeImmutable $to,
        public int $stepSeconds,
        public array $buckets,
    ) {
    }

    public static function fromQuery(ActivityQuery $query, int $stepSeconds): self
    {
        if (! in_array($stepSeconds, self::STEPS, true)) {
            throw new InvalidArgumentException(sprintf(
                'Activity series step [%d] is not supported. Allowed: %s.',
                $stepSeconds,
                implode(', ', self::STEPS),
            ));
        }

        if (! $query->from instanceof DateTimeImmutable || ! $query->to instanceof DateTimeImmutable) {
            throw new InvalidArgumentException('Activity series aggregation requires an explicit from and to range.');
        }

        $from = self::alignedDown($query->from, $stepSeconds);
        $to = self::alignedUp($query->to, $stepSeconds);
        $bucketCount = intdiv($to->getTimestamp() - $from->getTimestamp(), $stepSeconds);

        if ($bucketCount < 1 || $bucketCount > EnvelopeBudget::MAX_SERIES_BUCKETS) {
            throw new LengthException('Activity series exceeds its bucket limit.');
        }

        $buckets = [];

        for ($index = 0; $index < $bucketCount; $index++) {
            $buckets[] = self::at($from->getTimestamp() + $index * $stepSeconds);
        }

        return new self($from, $to, $stepSeconds, $buckets);
    }

    public function bucketCount(): int
    {
        return count($this->buckets);
    }

    public function bucketTimestamp(): int
    {
        return $this->from->getTimestamp();
    }

    public function bucketIndex(DateTimeImmutable $at): int
    {
        return intdiv($at->getTimestamp() - $this->bucketTimestamp(), $this->stepSeconds);
    }

    public function assertPointBudget(int $groupCount): void
    {
        if ($groupCount * $this->bucketCount() > EnvelopeBudget::MAX_SERIES_POINTS) {
            throw new LengthException('Activity series exceeds its point limit.');
        }
    }

    /**
     * @param  array<string, array<int, int>>  $counts  group key => bucket index => count
     * @param  list<string>  $groupOrder  groups that must be present even when empty
     * @return array{buckets: list<DateTimeImmutable>, series: array<string, list<int>>}
     */
    public function assemble(array $counts, array $groupOrder = []): array
    {
        $groups = array_values(array_unique([...$groupOrder, ...array_keys($counts)]));
        $this->assertPointBudget(count($groups));
        $series = [];

        foreach ($groups as $group) {
            $series[$group] = array_fill(0, $this->bucketCount(), 0);
        }

        foreach ($counts as $group => $buckets) {
            foreach ($buckets as $index => $count) {
                if ($index >= 0 && $index < $this->bucketCount()) {
                    $series[$group][$index] = $count;
                }
            }
        }

        foreach ($series as $group => $values) {
            $series[$group] = array_values($values);
        }

        return ['buckets' => $this->buckets, 'series' => $series];
    }

    private static function alignedDown(DateTimeImmutable $at, int $stepSeconds): DateTimeImmutable
    {
        $timestamp = $at->getTimestamp();

        return self::at($timestamp - ($timestamp % $stepSeconds));
    }

    private static function alignedUp(DateTimeImmutable $at, int $stepSeconds): DateTimeImmutable
    {
        $timestamp = $at->getTimestamp();
        $remainder = $timestamp % $stepSeconds;

        return self::at(0 === $remainder ? $timestamp : $timestamp + ($stepSeconds - $remainder));
    }

    private static function at(int $timestamp): DateTimeImmutable
    {
        return (new DateTimeImmutable('@'.$timestamp))->setTimezone(new DateTimeZone('UTC'));
    }
}
