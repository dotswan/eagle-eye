<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Privacy;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Dotswan\EagleEye\Contracts\Privacy\ErasureBarrierInterface;
use Dotswan\EagleEye\Data\ActivityEntry;
use Dotswan\EagleEye\Data\ErasureResult;
use Dotswan\EagleEye\Data\EnvelopeBudget;
use Dotswan\EagleEye\Contracts\Integrity\IntegrityChainInterface;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

final readonly class ErasureRegistry implements ErasureBarrierInterface
{
    private const int TRANSACTION_ATTEMPTS = 10;

    public function __construct(
        private Connection $connection,
        private string $key,
        private int $barrierDays = 31,
        private int $maxReplayAgeDays = 30,
        private int $maxBarriers = 100_000,
        private int $maxReceipts = 100_000,
        private ?IntegrityChainInterface $integrity = null,
        private int $maxAttempts = 100_000,
    ) {
        if ($barrierDays <= $maxReplayAgeDays) {
            throw new InvalidArgumentException('Erasure barrier lifetime must exceed maximum replay age.');
        }
    }

    public function begin(string $subjectType, string $subjectId): string
    {
        $this->validateIdentity($subjectType, $subjectId);
        $this->assertCommitBoundary();
        $digest = $this->digest($subjectType, $subjectId);
        $requestId = (string) Str::ulid();
        $now = $this->now();
        $expiresAt = $now->modify("+{$this->barrierDays} days");

        $this->connection->transaction(function () use ($digest, $requestId, $now, $expiresAt): void {
            $this->lockState();
            $barriers = $this->connection->table('activity_erasure_barriers');
            $receipts = $this->connection->table('activity_erasure_receipts');

            if (
                ! (clone $barriers)->where('identity_hmac', $digest)->exists()
                && $this->connection->table('activity_erasure_barriers')->count() >= $this->maxBarriers
            ) {
                throw new RuntimeException('Eagle Eye erasure barrier quota is full.');
            }

            if ($receipts->count() >= $this->maxReceipts) {
                throw new RuntimeException('Eagle Eye erasure receipt quota is full.');
            }

            $barriers->updateOrInsert(['identity_hmac' => $digest], [
                'request_id' => $requestId,
                'created_at' => $this->timestamp($now),
                'expires_at' => $this->timestamp($expiresAt),
            ]);
            $receipts->insert([
                'id'            => $requestId,
                'identity_hmac' => $digest,
                'state'         => 'requested',
                'outcomes'      => '[]',
                'requested_at'  => $this->timestamp($now),
                'completed_at'  => null,
                'receipt_hmac'  => null,
            ]);
        }, self::TRANSACTION_ATTEMPTS);

        return $requestId;
    }

    public function finish(string $requestId, string $subjectType, string $subjectId, ErasureResult $result): void
    {
        $this->assertCommitBoundary();
        $digest = $this->digest($subjectType, $subjectId);
        $completedAt = $this->now();
        $outcomes = json_encode($result->outcomes, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        if (mb_strlen($outcomes, '8bit') > 65_536) {
            throw new RuntimeException('Eagle Eye erasure outcomes exceed the receipt limit.');
        }

        $receiptHmac = hash_hmac('sha256', implode('|', [
            $requestId,
            $digest,
            $result->state,
            (string) $result->removed,
            $outcomes,
            $this->timestamp($completedAt),
        ]), $this->receiptKey());

        $updated = $this->connection->transaction(fn (): int => $this->connection
            ->table('activity_erasure_receipts')
            ->where('id', $requestId)
            ->where('identity_hmac', $digest)
            ->where('state', 'requested')
            ->update([
                'state'        => $result->state,
                'outcomes'     => $outcomes,
                'completed_at' => $this->timestamp($completedAt),
                'receipt_hmac' => $receiptHmac,
            ]), self::TRANSACTION_ATTEMPTS);

        if (1 !== $updated) {
            throw new RuntimeException('Eagle Eye erasure receipt could not be committed.');
        }
    }

    public function blocks(string $subjectType, string $subjectId): bool
    {
        $this->assertKey();
        return $this->connection
            ->table('activity_erasure_barriers')
            ->where('identity_hmac', $this->digest($subjectType, $subjectId))
            ->where('expires_at', '>', $this->timestamp($this->now()))
            ->exists();
    }

    public function allows(ActivityEntry $entry): bool
    {
        return [] !== $this->allowed([$entry]);
    }

    public function deliveryBlocked(): bool
    {
        return $this->connection->transactionLevel() > 0;
    }

    /** @param list<ActivityEntry> $entries
     *  @return list<ActivityEntry>
     */
    public function allowed(array $entries): array
    {
        $this->assertKey();
        $cutoff = $this->now()->modify("-{$this->maxReplayAgeDays} days");
        $digestsByEntry = [];
        $allDigests = [];

        foreach ($entries as $index => $entry) {
            if (($entry->createdAt ?? $entry->loggedAt) <= $cutoff) {
                $digestsByEntry[$index] = null;

                continue;
            }

            $digests = [];

            if (null !== $entry->actor?->id) {
                $digests[] = $this->digest($entry->actor->type, $entry->actor->id);
            }

            if (null !== $entry->subject?->id) {
                $digests[] = $this->digest($entry->subject->type, $entry->subject->id);

                if (null !== $entry->subject->className) {
                    $digests[] = $this->digest($entry->subject->className, $entry->subject->id);
                }
            }

            $digestsByEntry[$index] = $digests;

            foreach ($digests as $digest) {
                $allDigests[$digest] = true;
            }
        }

        $blocked = [] === $allDigests
            ? []
            : array_fill_keys($this->connection
                ->table('activity_erasure_barriers')
                ->whereIn('identity_hmac', array_keys($allDigests))
                ->where('expires_at', '>', $this->timestamp($this->now()))
                ->pluck('identity_hmac')
                ->all(), true);

        return array_values(array_filter(
            $entries,
            static function (ActivityEntry $entry, int $index) use ($digestsByEntry, $blocked): bool {
                $digests = $digestsByEntry[$index] ?? null;

                return is_array($digests) && [] === array_intersect($digests, array_keys($blocked));
            },
            ARRAY_FILTER_USE_BOTH,
        ));
    }

    public function digest(string $subjectType, string $subjectId): string
    {
        $this->validateIdentity($subjectType, $subjectId);

        return hash_hmac('sha256', json_encode([$subjectType, $subjectId], JSON_THROW_ON_ERROR), $this->identityKey());
    }

    public function purgeExpired(int $limit = 1_000): int
    {
        $ids = $this->connection
            ->table('activity_erasure_barriers')
            ->where('expires_at', '<=', $this->timestamp($this->now()))
            ->orderBy('expires_at')
            ->limit(max(1, min($limit, 1_000)))
            ->pluck('identity_hmac')
            ->all();

        return [] === $ids
            ? 0
            : $this->connection->table('activity_erasure_barriers')->whereIn('identity_hmac', $ids)->delete();
    }

    private function validateIdentity(string $subjectType, string $subjectId): void
    {
        if (
            '' === $subjectType
            || '' === $subjectId
            || mb_strlen($subjectType, '8bit') > 255
            || mb_strlen($subjectId, '8bit') > 64
            || preg_match('/[\x00-\x1F\x7F]/', $subjectType.$subjectId)
        ) {
            throw new InvalidArgumentException('Erasure identity must contain bounded non-control type and ID values.');
        }
    }

    private function assertCommitBoundary(): void
    {
        if ($this->connection->transactionLevel() > 0 || $this->connection->getPdo()->inTransaction()) {
            throw new RuntimeException('Erasure requires an independent committed database boundary.');
        }
    }

    private function identityKey(): string
    {
        return hash_hkdf('sha256', $this->key, 32, 'eagle-eye-erasure-identity-v1');
    }

    private function receiptKey(): string
    {
        return hash_hkdf('sha256', $this->key, 32, 'eagle-eye-erasure-receipt-v1');
    }

    /** @param list<ActivityEntry> $entries
     *  @param Closure(list<ActivityEntry>): void $write
     *  @param bool|Closure(): bool $acknowledged
     */
    public function deliver(array $entries, Closure $write, ?Connection $connection = null, bool|Closure $acknowledged = true): void
    {
        EnvelopeBudget::batchSize($entries);

        if ($connection === $this->connection) {
            $this->connection->transaction(function () use ($entries, $write, $acknowledged): void {
                $this->lockState();
                $allowed = $this->admit($entries);

                if ([] !== $allowed) {
                    $write($allowed);
                    $this->acknowledge($allowed, $acknowledged instanceof Closure ? $acknowledged() : $acknowledged);
                }
            }, self::TRANSACTION_ATTEMPTS);

            return;
        }

        $this->assertCommitBoundary();

        if ($connection instanceof Connection && ($connection->transactionLevel() > 0 || $connection->getPdo()->inTransaction())) {
            throw new RuntimeException('Cross-connection delivery requires a committed destination.');
        }

        $attemptId = (string) Str::ulid();
        $allowed = $this->connection->transaction(function () use ($entries, $attemptId): array {
            $this->lockState();
            $allowed = $this->admit($entries);
            $digests = [];

            foreach ($allowed as $entry) {
                foreach ($this->entryDigests($entry) as $digest) {
                    $digests[$digest] = true;
                }
            }

            if ($this->connection->table('activity_delivery_attempts')->count() + count($digests) > $this->maxAttempts) {
                throw new RuntimeException('Eagle Eye unresolved delivery quota is full.');
            }

            if ([] !== $digests) {
                $startedAt = $this->timestamp($this->now());
                $this->connection->table('activity_delivery_attempts')->insert(array_map(
                    static fn (string $digest): array => ['attempt_id' => $attemptId, 'identity_hmac' => $digest, 'started_at' => $startedAt],
                    array_keys($digests),
                ));
            }

            return $allowed;
        }, self::TRANSACTION_ATTEMPTS);

        if ([] === $allowed) {
            return;
        }

        $write($allowed);
        $acknowledged = $acknowledged instanceof Closure ? $acknowledged() : $acknowledged;
        $this->connection->transaction(function () use ($attemptId, $allowed, $acknowledged): void {
            $this->lockState();
            $this->acknowledge($allowed, $acknowledged);
            if ($acknowledged) {
                $this->connection->table('activity_delivery_attempts')->where('attempt_id', $attemptId)->delete();
            }
        }, self::TRANSACTION_ATTEMPTS);
    }

    public function assertDrained(string $subjectType, string $subjectId): void
    {
        $this->assertKey();
        $attempt = $this->connection->table('activity_delivery_attempts')
            ->where('identity_hmac', $this->digest($subjectType, $subjectId))->value('attempt_id');

        if (is_string($attempt)) {
            throw new RuntimeException("Erasure blocked by unresolved delivery [{$attempt}]; confirm remote settlement before resolving the attempt.");
        }
    }

    public function resolveAttempt(string $attemptId, string $evidence, string $subjectType, string $subjectId): void
    {
        $this->assertCommitBoundary();

        if (! preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $attemptId) || mb_strlen($evidence, '8bit') < 16 || mb_strlen($evidence, '8bit') > 2048) {
            throw new InvalidArgumentException('Resolution requires an attempt ULID and bounded settlement evidence of at least 16 bytes.');
        }

        $identity = $this->digest($subjectType, $subjectId);
        $this->connection->transaction(function () use ($attemptId, $evidence, $identity): void {
            $this->lockState();

            if (! $this->connection->table('activity_delivery_attempts')->where('attempt_id', $attemptId)->where('identity_hmac', $identity)->exists()) {
                throw new RuntimeException('Unresolved delivery attempt does not exist.');
            }

            if ($this->connection->table('activity_erasure_receipts')->count() >= $this->maxReceipts) {
                throw new RuntimeException('Eagle Eye erasure receipt quota is full.');
            }

            $now = $this->timestamp($this->now());
            $outcomes = json_encode(['attempt_id' => $attemptId, 'evidence_sha256' => hash('sha256', $evidence)], JSON_THROW_ON_ERROR);
            $digest = hash_hmac('sha256', $attemptId, $this->receiptKey());
            $this->connection->table('activity_erasure_receipts')->insert([
                'id'           => (string) Str::ulid(), 'identity_hmac' => $digest, 'state' => 'delivery_resolved',
                'outcomes'     => $outcomes, 'requested_at' => $now, 'completed_at' => $now,
                'receipt_hmac' => hash_hmac('sha256', $digest.'|'.$outcomes.'|'.$now, $this->receiptKey()),
            ]);
            $this->connection->table('activity_delivery_attempts')->where('attempt_id', $attemptId)->delete();
        }, self::TRANSACTION_ATTEMPTS);
    }

    private function lockState(): void
    {
        $this->connection->table('activity_security_state')->where('name', 'coordination')->increment('version');
        $this->assertKey();
    }

    private function assertKey(): void
    {
        $fingerprint = hash('sha256', $this->identityKey());
        $state = $this->connection->table('activity_security_state')->where('name', 'coordination');
        $stored = (clone $state)->value('key_fingerprint');

        if (null === $stored) {
            (clone $state)->whereNull('key_fingerprint')->update(['key_fingerprint' => $fingerprint]);
            $stored = (clone $state)->value('key_fingerprint');
        }

        if (! is_string($stored) || ! hash_equals($fingerprint, $stored)) {
            throw new RuntimeException('Eagle Eye security key does not match stored security state; restore the original dedicated key.');
        }
    }

    /** @param list<ActivityEntry> $entries
     *  @return list<ActivityEntry>
     */
    private function admit(array $entries): array
    {
        $allowed = $this->allowed($entries);
        $ids = array_fill_keys(array_map(static fn (ActivityEntry $entry): string => $entry->id, $allowed), true);
        $discarded = array_values(array_filter($entries, static fn (ActivityEntry $entry): bool => ! isset($ids[$entry->id])));
        $this->integrity?->transitionEntries($discarded, 'discarded');

        return $allowed;
    }

    /** @param list<ActivityEntry> $entries */
    private function acknowledge(array $entries, bool $acknowledged): void
    {
        if ($acknowledged) {
            $this->integrity?->transitionEntries($entries, 'delivered');
        }
    }

    /** @return list<string> */
    private function entryDigests(ActivityEntry $entry): array
    {
        $digests = [];

        if (null !== $entry->actor?->id) {
            $digests[] = $this->digest($entry->actor->type, $entry->actor->id);
        }

        if (null !== $entry->subject?->id) {
            $digests[] = $this->digest($entry->subject->type, $entry->subject->id);

            if (null !== $entry->subject->className) {
                $digests[] = $this->digest($entry->subject->className, $entry->subject->id);
            }
        }

        return $digests;
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    private function timestamp(DateTimeImmutable $date): string
    {
        return $date->format('Y-m-d H:i:s.u');
    }
}
