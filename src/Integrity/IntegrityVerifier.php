<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Integrity;

use DateTimeImmutable;
use Dotswan\EagleEye\Contracts\Integrity\IntegrityHasherInterface;
use Dotswan\EagleEye\Contracts\Repository\ActivityRepositoryInterface;
use Dotswan\EagleEye\Data\IntegrityVerificationResult;
use Dotswan\EagleEye\Data\ActivityEntry;
use Dotswan\EagleEye\Data\Query\ActivityQuery;
use Dotswan\EagleEye\Data\Query\Cursor;
use Dotswan\EagleEye\Enums\SortOrder;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use stdClass;

final readonly class IntegrityVerifier
{
    private const int MAX_RETAINED_FAILURES = 1_000;

    public function __construct(
        private ActivityRepositoryInterface $repository,
        private IntegrityHasherInterface $hasher,
        private IntegrityLedger $ledger,
        private ConfigRepository $config,
    ) {
    }

    public function verify(?ActivityQuery $scope = null): IntegrityVerificationResult
    {
        $invalid = [];
        $invalidCount = 0;
        $checked = 0;
        $pending = 0;
        $afterSegment = null;
        $previousSegment = null;
        $lastSegment = null;

        do {
            $segments = $this->ledger->segmentsAfter($afterSegment, 100);

            foreach ($segments as $segment) {
                $segmentId = (string) $segment->id;
                $lastSegment = $segmentId;

                if (
                    ! $this->ledger->validAnchor($segment)
                    || $this->nullableString($segment->predecessor_segment_id ?? null) !== $previousSegment
                ) {
                    $this->recordFailure('segment:'.$segmentId, $invalid, $invalidCount);
                }

                $this->verifySegment($segment, $scope, $invalid, $invalidCount, $checked, $pending);
                $previousSegment = $segmentId;
                $afterSegment = $segmentId;
            }
        } while (100 === count($segments));

        $expectedLast = $this->ledger->lastSegmentId();

        if ($this->nullableString($expectedLast) !== $lastSegment) {
            $this->recordFailure('chain:tail', $invalid, $invalidCount);
        }

        $this->verifyExpectedEntries($scope, $invalid, $invalidCount);

        return new IntegrityVerificationResult($checked, $invalid, $invalidCount, $pending);
    }

    /** @param list<string> $invalid */
    private function verifyExpectedEntries(?ActivityQuery $scope, array &$invalid, int &$invalidCount): void
    {
        $query = clone ($scope ?? new ActivityQuery());
        $query->orderByTime(SortOrder::Ascending)->limit(500);
        $channels = $this->config->get('eagle-eye.integrity.channels', ['auth']);
        $channels = is_array($channels) ? array_fill_keys(array_map(strval(...), $channels), true) : [];

        do {
            $result = $this->repository->search($query);
            $expected = array_values(array_filter(
                $result->entries,
                static fn (ActivityEntry $entry): bool => null !== $entry->integrityHash || isset($channels[$entry->channel->value]),
            ));
            $known = $this->ledger->knownEntryIds(array_map(static fn (ActivityEntry $entry): string => $entry->id, $expected));

            foreach ($expected as $entry) {
                if (null === $entry->integrityHash || ! isset($known[$entry->id])) {
                    $this->recordFailure($entry->id, $invalid, $invalidCount);
                }
            }

            if ($result->hasMore && ! $result->nextCursor instanceof Cursor) {
                $this->recordFailure('scan:missing-cursor', $invalid, $invalidCount);

                break;
            }

            if ($result->nextCursor instanceof Cursor && $query->cursor instanceof Cursor && $result->nextCursor->encode() === $query->cursor->encode()) {
                $this->recordFailure('scan:repeated-cursor', $invalid, $invalidCount);

                break;
            }

            $query->cursor($result->nextCursor);
        } while ($result->hasMore);
    }

    /** @param list<string> $invalid */
    private function verifySegment(
        stdClass $segment,
        ?ActivityQuery $scope,
        array &$invalid,
        int &$invalidCount,
        int &$checked,
        int &$pending,
    ): void {
        $sequence = 0;
        $previousHash = null;
        $firstEntry = null;
        $lastEntry = null;

        do {
            $rows = $this->ledger->entriesAfter((string) $segment->id, $sequence, 500);

            foreach ($rows as $row) {
                $entryId = (string) $row->entry_id;
                $currentSequence = (int) $row->sequence;
                $entry = $this->repository->find($entryId);
                $firstEntry ??= $entryId;
                $lastEntry = $entryId;
                $checked++;
                $pendingEntry = 'pending' === ($row->lifecycle ?? null) && !$entry instanceof ActivityEntry;
                $valid = $currentSequence === $sequence + 1
                    && $this->nullableString($row->previous_hash ?? null) === $previousHash
                    && $this->ledger->validLifecycle($row)
                    && (in_array($row->lifecycle, ['discarded', 'erased', 'pruned'], true)
                        ? (!$entry instanceof ActivityEntry || $this->validEntry($entry, $row, $previousHash))
                        : ($pendingEntry || ($entry instanceof ActivityEntry && $this->validEntry($entry, $row, $previousHash))));

                if ($valid && $pendingEntry) {
                    $pending++;
                }

                if ((!$scope instanceof ActivityQuery || $this->matchesScope($entry, $scope)) && ! $valid) {
                    $this->recordFailure($entryId, $invalid, $invalidCount);
                }

                $sequence = $currentSequence;
                $previousHash = (string) $row->entry_hash;
            }
        } while (500 === count($rows));

        if (
            $sequence !== (int) $segment->entry_count
            || $firstEntry !== (string) $segment->first_entry_id
            || $lastEntry !== (string) $segment->last_entry_id
            || $previousHash !== (string) $segment->head_hash
        ) {
            $this->recordFailure('segment:'.$segment->id, $invalid, $invalidCount);
        }
    }

    private function matchesScope(?ActivityEntry $entry, ActivityQuery $scope): bool
    {
        if (!$entry instanceof ActivityEntry) {
            return true;
        }

        return ([] === $scope->channels || in_array($entry->channel, $scope->channels, true))
            && (!$scope->from instanceof DateTimeImmutable || $entry->loggedAt >= $scope->from)
            && (!$scope->to instanceof DateTimeImmutable || $entry->loggedAt < $scope->to);
    }

    private function validEntry(ActivityEntry $entry, stdClass $row, ?string $previousHash): bool
    {
        return is_string($entry->integrityHash)
            && hash_equals((string) $row->entry_hash, $entry->integrityHash)
            && hash_equals((string) $row->entry_hash, $this->hasher->hash($entry, $previousHash));
    }

    /** @param list<string> $invalid */
    private function recordFailure(string $id, array &$invalid, int &$invalidCount): void
    {
        $invalidCount++;

        if (count($invalid) < self::MAX_RETAINED_FAILURES) {
            $invalid[] = $id;
        }
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && '' !== $value ? $value : null;
    }
}
