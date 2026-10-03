<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Repository;

use Dotswan\EagleEye\Capture\CaptureSuppression;
use Dotswan\EagleEye\Contracts\Driver\ActivityLogDriverFactoryInterface;
use Dotswan\EagleEye\Contracts\Driver\ActivityLogQueryDriverInterface;
use Dotswan\EagleEye\Contracts\Driver\ActivityLogSeriesDriverInterface;
use Dotswan\EagleEye\Contracts\Repository\ActivityRepositoryInterface;
use Dotswan\EagleEye\Contracts\Security\CipherInterface;
use Dotswan\EagleEye\Contracts\Security\DurableRevealAuditInterface;
use Dotswan\EagleEye\Contracts\Security\LogAccessPolicyInterface;
use Dotswan\EagleEye\Data\ActivityEntry;
use Dotswan\EagleEye\Data\Query\ActivityQuery;
use Dotswan\EagleEye\Data\Query\QueryResult;
use Dotswan\EagleEye\Data\SecretRef;
use Dotswan\EagleEye\Exceptions\SecretRevealDeniedException;
use InvalidArgumentException;
use LogicException;

final readonly class ActivityRepository implements ActivityRepositoryInterface
{
    public function __construct(
        private ActivityLogDriverFactoryInterface $drivers,
        private LogAccessPolicyInterface $policy,
        private CipherInterface $cipher,
        private DurableRevealAuditInterface $audits,
    ) {
    }

    public function find(string $id): ?ActivityEntry
    {
        $entry = app(CaptureSuppression::class)->run(fn (): ?ActivityEntry => $this->queryDriver()->find($id));

        return $entry instanceof ActivityEntry && hash_equals($id, $entry->id) ? $entry : null;
    }

    public function search(ActivityQuery $query): QueryResult
    {
        return app(CaptureSuppression::class)->run(fn (): QueryResult => $this->queryDriver()->search($query));
    }

    public function count(ActivityQuery $query): int
    {
        return app(CaptureSuppression::class)->run(fn (): int => $this->queryDriver()->count($query));
    }

    public function aggregate(ActivityQuery $query, string $groupBy, string $interval = 'day'): array
    {
        return app(CaptureSuppression::class)->run(fn (): array => $this->queryDriver()->aggregate($query, $groupBy, $interval));
    }

    public function aggregateTop(ActivityQuery $query, string $groupBy, int $limit): array
    {
        return app(CaptureSuppression::class)->run(fn (): array => $this->queryDriver()->aggregateTop($query, $groupBy, max(1, min($limit, 100))));
    }

    public function aggregateSeries(ActivityQuery $query, ?string $groupBy, int $stepSeconds): array
    {
        return app(CaptureSuppression::class)->run(function () use ($query, $groupBy, $stepSeconds): array {
            $driver = $this->queryDriver();

            if (! $driver instanceof ActivityLogSeriesDriverInterface) {
                throw new LogicException('The active driver does not support series aggregation.');
            }

            return $driver->aggregateSeries($query, $groupBy, $stepSeconds);
        });
    }

    public function tags(?string $prefix = null, int $limit = 50): array
    {
        return app(CaptureSuppression::class)->run(fn (): array => $this->queryDriver()->tags($prefix, $limit));
    }

    public function reveal(ActivityEntry $entry, string $path, ?object $viewer = null): string
    {
        if (! $this->policy->canReveal($entry, $path, $viewer)) {
            throw new SecretRevealDeniedException($entry->id, $path);
        }

        $secret = $entry->secrets[$path] ?? null;

        if (! $secret instanceof SecretRef) {
            throw new InvalidArgumentException("Activity entry [{$entry->id}] holds no secret at [{$path}].");
        }

        $this->audits->record($entry, $path, $viewer);

        return $this->cipher->decrypt($secret);
    }

    private function queryDriver(): ActivityLogQueryDriverInterface
    {
        $driver = $this->drivers->driver();

        if (! $driver instanceof ActivityLogQueryDriverInterface) {
            throw new LogicException("Driver [{$driver->name()}] does not support queries.");
        }

        return $driver;
    }
}
