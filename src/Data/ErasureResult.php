<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Data;

use InvalidArgumentException;

final readonly class ErasureResult
{
    /**
     * @param list<array{driver: string, state: string, removed: int, request_ids: list<string>}> $outcomes
     */
    public function __construct(
        public string $state,
        public int $removed,
        public array $outcomes,
    ) {
        if (! in_array($state, ['completed', 'accepted_pending', 'partial', 'failed'], true)) {
            throw new InvalidArgumentException('Invalid erasure result state.');
        }
    }

    public static function completed(string $driver, int $removed): self
    {
        return new self('completed', max(0, $removed), [[
            'driver'      => $driver,
            'state'       => 'completed',
            'removed'     => max(0, $removed),
            'request_ids' => [],
        ]]);
    }

    /** @param list<string> $requestIds */
    public static function pending(string $driver, array $requestIds): self
    {
        return new self('accepted_pending', 0, [[
            'driver'      => $driver,
            'state'       => 'accepted_pending',
            'removed'     => 0,
            'request_ids' => $requestIds,
        ]]);
    }

    /** @param list<string> $requestIds */
    public static function failed(string $driver, array $requestIds = []): self
    {
        return new self('failed', 0, [[
            'driver'      => $driver,
            'state'       => 'failed',
            'removed'     => 0,
            'request_ids' => $requestIds,
        ]]);
    }

    /** @param non-empty-list<self> $results */
    public static function combine(array $results): self
    {
        $accepted = array_filter($results, static fn (self $result): bool => $result->accepted());
        $failed = array_filter($results, static fn (self $result): bool => ! $result->accepted());
        $pending = array_filter($results, static fn (self $result): bool => 'accepted_pending' === $result->state);

        $state = [] !== $failed
            ? ([] !== $accepted ? 'partial' : 'failed')
            : ([] !== $pending ? 'accepted_pending' : 'completed');

        return new self(
            $state,
            max(array_map(static fn (self $result): int => $result->removed, $results)),
            array_merge(...array_map(static fn (self $result): array => $result->outcomes, $results)),
        );
    }

    public function accepted(): bool
    {
        return in_array($this->state, ['completed', 'accepted_pending'], true);
    }
}
