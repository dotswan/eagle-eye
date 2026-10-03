<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Drivers;

use DateTimeImmutable;
use Dotswan\EagleEye\Contracts\Driver\FullActivityLogDriverInterface;
use Dotswan\EagleEye\Contracts\Driver\ActivityLogSeriesDriverInterface;
use Dotswan\EagleEye\Contracts\Driver\StoresFullActivityBodiesInterface;
use Dotswan\EagleEye\Data\ActivityEntry;
use Dotswan\EagleEye\Data\EnvelopeBudget;
use Dotswan\EagleEye\Data\Query\ActivityQuery;
use Dotswan\EagleEye\Data\Query\QueryResult;
use Dotswan\EagleEye\Data\WriteFailure;
use Dotswan\EagleEye\Data\ErasureResult;
use Dotswan\EagleEye\Enums\Capability;
use RuntimeException;
use Throwable;

/**
 * Composes a primary (full-body) driver with an optional header-only
 * accelerator. Writes fan out with per-child failure policies; reads prefer
 * the accelerator for listings/counts/sorting and fall back to the primary
 * for bodies and full text. With the primary hard-down the accelerator
 * still answers listing queries (degraded, not broken).
 */
final readonly class MultiDriver implements FullActivityLogDriverInterface
{
    private const string IGNORE = 'ignore';

    private const string FALLBACK = 'fallback';

    private const string THROW = 'throw';

    private const string BODY_RECOVERY_TOLERANCE = '+1 hour';

    public function __construct(
        private FullActivityLogDriverInterface $primary,
        private ?FullActivityLogDriverInterface $accelerator = null,
        private string $primaryOnFailure = self::THROW,
        private string $acceleratorOnFailure = self::IGNORE,
    ) {
    }

    public function write(ActivityEntry $entry): void
    {
        $this->writeBatch([$entry]);
    }

    /**
     * @param list<ActivityEntry> $entries
     * @return list<WriteFailure>
     */
    public function writeBatch(array $entries): array
    {
        EnvelopeBudget::batchSize($entries);
        $failures = [];

        try {
            $failures = $this->primary->writeBatch($entries);

            if ([] !== $failures && self::THROW === $this->primaryOnFailure) {
                throw new RuntimeException('Eagle Eye primary driver rejected a batch: '.$failures[0]->message);
            }

        } catch (Throwable $throwable) {
            if (self::THROW === $this->primaryOnFailure) {
                throw $throwable;
            }

            if (self::FALLBACK === $this->primaryOnFailure || self::IGNORE === $this->primaryOnFailure) {
                $failures = $this->toFailures($entries, $throwable);
            }
        }

        if ($this->accelerator instanceof FullActivityLogDriverInterface) {
            try {
                $childFailures = $this->accelerator->writeBatch($entries);

                if ([] !== $childFailures && self::THROW === $this->acceleratorOnFailure) {
                    throw new RuntimeException('Eagle Eye accelerator driver rejected a batch: '.$childFailures[0]->message);
                }

                if (self::FALLBACK === $this->primaryOnFailure && $this->accelerator instanceof StoresFullActivityBodiesInterface) {
                    $rejected = array_fill_keys(array_map(static fn (WriteFailure $failure): string => $failure->entryId, $childFailures), true);
                    $failures = array_values(array_filter(
                        $failures,
                        static fn (WriteFailure $failure): bool => isset($rejected[$failure->entryId]),
                    ));
                }
            } catch (Throwable $throwable) {
                if (self::THROW === $this->acceleratorOnFailure) {
                    throw $throwable;
                }
            }
        }

        return $failures;
    }

    public function name(): string
    {
        return 'multi';
    }

    public function healthy(): bool
    {
        if ($this->primary->healthy()) {
            return true;
        }

        return $this->accelerator instanceof FullActivityLogDriverInterface && $this->accelerator->healthy();
    }

    public function supports(Capability $capability): bool
    {
        return match ($capability) {
            Capability::FullTextSearch,
            Capability::JsonFieldQuery,
            Capability::LiveTail            => $this->primary->supports($capability),
            Capability::SynchronousDeletion => $this->primary->supports($capability)
                && (!$this->accelerator instanceof FullActivityLogDriverInterface || $this->accelerator->supports($capability)),
            default => ($this->accelerator ?? $this->primary)->supports($capability),
        };
    }

    public function find(string $id): ?ActivityEntry
    {
        try {
            $entry = $this->primary->find($id);
        } catch (Throwable) {
            $entry = null;
        }

        if ($entry instanceof ActivityEntry || !$this->accelerator instanceof FullActivityLogDriverInterface) {
            return $entry;
        }

        $header = $this->accelerator->find($id);

        if (!$header instanceof ActivityEntry) {
            return null;
        }

        $recoveryStart = $header->loggedAt->modify('-1 second');
        $recoveryLimit = $header->loggedAt->modify(self::BODY_RECOVERY_TOLERANCE);
        $now = new DateTimeImmutable();
        $recoveryEnd = $now < $recoveryLimit ? $now : $recoveryLimit;

        if ($recoveryEnd <= $recoveryStart) {
            return $this->degraded($header);
        }

        $bounded = (new ActivityQuery())
            ->search($id)
            ->between($recoveryStart, $recoveryEnd)
            ->limit(15);

        try {
            foreach ($this->primary->search($bounded)->entries as $candidate) {
                if ($candidate->id === $id) {
                    return $candidate;
                }
            }

            return $this->degraded($header);
        } catch (Throwable) {
            return $this->degraded($header);
        }
    }

    public function search(ActivityQuery $query): QueryResult
    {
        return $this->readDriver($query)->search($query);
    }

    public function count(ActivityQuery $query): int
    {
        return $this->readDriver($query)->count($query);
    }

    /** @return array<string, int> */
    public function aggregate(ActivityQuery $query, string $groupBy, string $interval = 'day'): array
    {
        return $this->readDriver($query)->aggregate($query, $groupBy, $interval);
    }

    public function aggregateTop(ActivityQuery $query, string $groupBy, int $limit): array
    {
        $driver = $this->readDriver($query);

        return $driver->aggregateTop($query, $groupBy, $limit);
    }

    /**
     * @return array{buckets: list<DateTimeImmutable>, series: array<string, list<int>>}
     */
    public function aggregateSeries(ActivityQuery $query, ?string $groupBy, int $stepSeconds): array
    {
        $driver = $this->readDriver($query);

        if (! $driver instanceof ActivityLogSeriesDriverInterface) {
            throw new RuntimeException("Driver [{$driver->name()}] does not support series aggregation.");
        }

        return $driver->aggregateSeries($query, $groupBy, $stepSeconds);
    }

    /** @return list<string> */
    public function tags(?string $prefix = null, int $limit = 50): array
    {
        return $this->accelerator instanceof FullActivityLogDriverInterface
            ? $this->accelerator->tags($prefix, $limit)
            : $this->primary->tags($prefix, $limit);
    }

    public function prune(DateTimeImmutable $before, ?ActivityQuery $scope = null): int
    {
        $primary = $this->primary->prune($before, $scope);
        $accelerator = $this->accelerator?->prune($before, $scope) ?? 0;

        return max($primary, $accelerator);
    }

    public function forget(string $subjectType, string $subjectId): ErasureResult
    {
        $primary = $this->primary->forget($subjectType, $subjectId);
        $accelerator = $this->accelerator?->forget($subjectType, $subjectId);

        return $accelerator instanceof ErasureResult
            ? ErasureResult::combine([$primary, $accelerator])
            : $primary;
    }

    public function install(): void
    {
        $this->primary->install();
        $this->accelerator?->install();
    }

    /** @return array{entries: int, bytes: int, oldest: ?DateTimeImmutable} */
    public function stats(): array
    {
        return $this->accelerator instanceof FullActivityLogDriverInterface
            ? $this->accelerator->stats()
            : $this->primary->stats();
    }

    /**
     * @param list<ActivityEntry> $entries
     * @return list<WriteFailure>
     */
    private function toFailures(array $entries, Throwable $throwable): array
    {
        return array_map(
            static fn (ActivityEntry $entry): WriteFailure => new WriteFailure($entry->id, $throwable->getMessage()),
            $entries,
        );
    }

    private function readDriver(ActivityQuery $query): FullActivityLogDriverInterface
    {
        if (!$this->accelerator instanceof FullActivityLogDriverInterface) {
            return $this->primary;
        }

        if (null !== $query->search && $this->primary->healthy()) {
            return $this->primary;
        }

        return $this->accelerator;
    }

    private function degraded(ActivityEntry $header): ActivityEntry
    {
        $storage = $header->toStorage();
        $storage['payload'] = ['__eagle_eye' => ['body_available' => false]];

        return ActivityEntry::fromStorage($storage);
    }
}
