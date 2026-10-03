<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Integrity;

use Dotswan\EagleEye\Enums\ActivityChannel;
use Dotswan\EagleEye\Enums\ActivitySeverity;
use DateTimeImmutable;
use DateTimeZone;
use Dotswan\EagleEye\Contracts\Integrity\IntegrityChainInterface;
use Dotswan\EagleEye\Contracts\Integrity\IntegrityHasherInterface;
use Dotswan\EagleEye\Data\ActivityEntry;
use Dotswan\EagleEye\Data\Query\ActivityQuery;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;
use RuntimeException;
use stdClass;

final readonly class IntegrityLedger implements IntegrityChainInterface
{
    private const string ULID_ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    private const int TRANSACTION_ATTEMPTS = 10;

    public function __construct(
        private Connection $connection,
        private IntegrityHasherInterface $hasher,
        private string $key,
        private int $segmentSize = 1_000,
    ) {
    }

    public function seal(ActivityEntry $entry): string
    {
        return $this->connection->transaction(function () use ($entry): string {
            $this->assertKey();
            $state = $this->lockState();
            $existing = $this->connection->table('activity_integrity_entries')->where('entry_id', $entry->id)->lockForUpdate()->first();

            if (is_object($existing) && is_string($existing->entry_hash ?? null)) {
                return $existing->entry_hash;
            }

            $segmentId = is_string($state->active_segment_id ?? null) ? $state->active_segment_id : null;
            $segment = null !== $segmentId
                ? $this->connection->table('activity_integrity_segments')->where('id', $segmentId)->lockForUpdate()->first()
                : null;

            if (! is_object($segment)) {
                $predecessor = is_string($state->last_segment_id ?? null) ? $state->last_segment_id : null;
                $segmentId = $this->nextSegmentId($predecessor);
                $now = $this->timestamp();
                $this->connection->table('activity_integrity_segments')->insert([
                    'id'                     => $segmentId,
                    'predecessor_segment_id' => is_string($predecessor) ? $predecessor : null,
                    'first_entry_id'         => $entry->id,
                    'last_entry_id'          => $entry->id,
                    'entry_count'            => 0,
                    'head_hash'              => str_repeat('0', 64),
                    'state'                  => 'open',
                    'opened_at'              => $now,
                    'sealed_at'              => null,
                    'anchor_hmac'            => str_repeat('0', 64),
                ]);
                $this->connection->table('activity_integrity_state')->where('name', 'chain')->update([
                    'active_segment_id' => $segmentId,
                    'last_segment_id'   => $segmentId,
                    'version'           => (int) ($state->version ?? 0) + 1,
                ]);
                $segment = $this->connection->table('activity_integrity_segments')->where('id', $segmentId)->lockForUpdate()->first();
            }

            if (! is_object($segment)) {
                throw new RuntimeException('Eagle Eye integrity segment could not be allocated.');
            }

            $sequence = (int) $segment->entry_count + 1;
            $previousHash = (int) $segment->entry_count > 0 ? (string) $segment->head_hash : null;
            $entryHash = $this->hasher->hash($entry, $previousHash);
            $sealed = $sequence >= $this->segmentSize;
            $lastEntryId = $entry->id;
            $firstEntryId = (string) $segment->first_entry_id;
            $anchor = $this->anchor(
                $segmentId,
                is_string($segment->predecessor_segment_id ?? null) ? $segment->predecessor_segment_id : null,
                $firstEntryId,
                $lastEntryId,
                $sequence,
                $entryHash,
                $sealed ? 'sealed' : 'open',
            );

            $record = [
                'entry_id'       => $entry->id,
                'segment_id'     => $segmentId,
                'sequence'       => $sequence,
                'previous_hash'  => $previousHash,
                'entry_hash'     => $entryHash,
                'lifecycle'      => 'pending',
                'lifecycle_hmac' => $this->lifecycleHmac($entry->id, $entryHash, 'pending'),
                'actor_hmac'     => null !== $entry->actor?->id ? $this->identityHmac($entry->actor->type, $entry->actor->id) : null,
                'subject_hmac'   => null !== $entry->subject?->id ? $this->identityHmac($entry->subject->type, $entry->subject->id) : null,
                'class_hmac'     => null !== $entry->subject?->id && null !== $entry->subject->className ? $this->identityHmac($entry->subject->className, $entry->subject->id) : null,
                'logged_at'      => $entry->loggedAt->format('Y-m-d H:i:s.u'),
                'channel'        => $entry->channel->value,
                'severity'       => $entry->severity->value,
                'tags'           => json_encode(array_map($this->tagHmac(...), $entry->tags), JSON_THROW_ON_ERROR),
            ];
            $record['metadata_hmac'] = $this->metadataHmac($record);
            $this->connection->table('activity_integrity_entries')->insert($record);
            $this->connection->table('activity_integrity_segments')->where('id', $segmentId)->update([
                'last_entry_id' => $lastEntryId,
                'entry_count'   => $sequence,
                'head_hash'     => $entryHash,
                'state'         => $sealed ? 'sealed' : 'open',
                'sealed_at'     => $sealed ? $this->timestamp() : null,
                'anchor_hmac'   => $anchor,
            ]);

            if ($sealed) {
                $this->connection->table('activity_integrity_state')->where('name', 'chain')->update([
                    'active_segment_id' => null,
                    'version'           => (int) ($state->version ?? 0) + 2,
                ]);
            }

            return $entryHash;
        }, self::TRANSACTION_ATTEMPTS);
    }

    /** @return list<stdClass> */
    public function segmentsAfter(?string $id, int $limit): array
    {
        $query = $this->connection->table('activity_integrity_segments')->orderBy('id')->limit(max(1, min($limit, 100)));

        if (null !== $id) {
            $query->where('id', '>', $id);
        }

        return array_values($query->get()->all());
    }

    /** @return list<stdClass> */
    public function entriesAfter(string $segmentId, int $sequence, int $limit): array
    {
        return array_values($this->connection
            ->table('activity_integrity_entries')
            ->where('segment_id', $segmentId)
            ->where('sequence', '>', $sequence)
            ->orderBy('sequence')
            ->limit(max(1, min($limit, 500)))
            ->get()
            ->all());
    }

    public function validAnchor(stdClass $segment): bool
    {
        $this->assertKey();
        $expected = $this->anchor(
            (string) $segment->id,
            is_string($segment->predecessor_segment_id ?? null) ? $segment->predecessor_segment_id : null,
            (string) $segment->first_entry_id,
            (string) $segment->last_entry_id,
            (int) $segment->entry_count,
            (string) $segment->head_hash,
            (string) $segment->state,
        );

        return is_string($segment->anchor_hmac ?? null) && hash_equals($expected, $segment->anchor_hmac);
    }

    public function lastSegmentId(): ?string
    {
        $id = $this->connection->table('activity_integrity_state')->where('name', 'chain')->value('last_segment_id');

        return is_string($id) && '' !== $id ? $id : null;
    }

    /** @param list<string> $entryIds
     *  @return array<string, true>
     */
    public function knownEntryIds(array $entryIds): array
    {
        if ([] === $entryIds) {
            return [];
        }

        return array_fill_keys($this->connection
            ->table('activity_integrity_entries')
            ->whereIn('entry_id', array_slice($entryIds, 0, 500))
            ->pluck('entry_id')
            ->all(), true);
    }

    /** @param list<ActivityEntry> $entries */
    public function transitionEntries(array $entries, string $state): void
    {
        $ids = [];

        foreach ($entries as $entry) {
            if (null !== $entry->integrityHash) {
                $ids[] = $entry->id;
            }
        }

        $this->transitionIds($ids, $state);
    }

    /** @param list<string> $ids */
    public function transitionIds(array $ids, string $state): void
    {
        if ([] === $ids) {
            return;
        }

        if (! in_array($state, ['delivered', 'discarded', 'erased', 'pruned'], true)) {
            throw new RuntimeException('Invalid integrity lifecycle transition.');
        }

        foreach (array_chunk($ids, 500) as $chunk) {
            $this->connection->transaction(function () use ($chunk, $state): void {
                $this->assertKey();
                $this->lockState();
                $rows = $this->connection->table('activity_integrity_entries')->whereIn('entry_id', $chunk)->lockForUpdate()->get();

                foreach ($rows as $row) {
                    if (! $this->validLifecycle($row)) {
                        throw new RuntimeException('Integrity lifecycle authentication failed.');
                    }

                    if (in_array($state, ['delivered', 'discarded'], true) && 'pending' !== $row->lifecycle) {
                        continue;
                    }

                    $this->connection->table('activity_integrity_entries')->where('entry_id', $row->entry_id)->update([
                        'lifecycle'      => $state,
                        'lifecycle_hmac' => $this->lifecycleHmac((string) $row->entry_id, (string) $row->entry_hash, $state),
                    ]);
                }
            }, self::TRANSACTION_ATTEMPTS);
        }
    }

    public function validLifecycle(stdClass $entry): bool
    {
        $state = $entry->lifecycle ?? null;

        return is_string($state)
            && in_array($state, ['pending', 'delivered', 'discarded', 'erased', 'pruned'], true)
            && is_string($entry->lifecycle_hmac ?? null)
            && is_string($entry->metadata_hmac ?? null)
            && hash_equals($this->metadataHmac((array) $entry), $entry->metadata_hmac)
            && hash_equals($this->lifecycleHmac((string) $entry->entry_id, (string) $entry->entry_hash, $state), $entry->lifecycle_hmac);
    }

    public function assertRemovalConnection(Connection $connection): void
    {
        if ($connection !== $this->connection && $this->connection->table('activity_integrity_entries')->exists()) {
            throw new RuntimeException('SQL integrity maintenance requires the activity and integrity ledger on the same connection.');
        }
    }

    public function authorizeErasure(string $type, string $id): void
    {
        $digest = $this->identityHmac($type, $id);
        $query = $this->connection->table('activity_integrity_entries')->where(static function (Builder $query) use ($digest): void {
            $query->where('actor_hmac', $digest)->orWhere('subject_hmac', $digest)->orWhere('class_hmac', $digest);
        });
        $this->transitionQuery($query, 'erased');
    }

    public function authorizePrune(DateTimeImmutable $before, ?ActivityQuery $scope = null): void
    {
        $query = $this->connection->table('activity_integrity_entries')->where('logged_at', '<', $before->format('Y-m-d H:i:s.u'));

        if ($scope instanceof ActivityQuery) {
            if ([] !== $scope->channels) {
                $query->whereIn('channel', array_map(static fn (ActivityChannel $channel): string => $channel->value, $scope->channels));
            }

            if ([] !== $scope->severities) {
                $query->whereIn('severity', array_map(static fn (ActivitySeverity $severity): string => $severity->value, $scope->severities));
            }

            if ([] !== $scope->tags) {
                $query->where(function (Builder $query) use ($scope): void {
                    foreach ($scope->tags as $tag) {
                        $query->orWhereJsonContains('tags', $this->tagHmac($tag));
                    }
                });
            }
        }

        $this->transitionQuery($query, 'pruned');
    }

    private function transitionQuery(Builder $query, string $state): void
    {
        $after = null;

        do {
            $page = clone $query;

            if (is_string($after)) {
                $page->where('entry_id', '>', $after);
            }

            $ids = array_values(array_filter($page->orderBy('entry_id')->limit(500)->pluck('entry_id')->all(), is_string(...)));
            $this->transitionIds($ids, $state);
            $after = [] !== $ids ? $ids[array_key_last($ids)] : null;
        } while (500 === count($ids));
    }

    private function lifecycleHmac(string $id, string $hash, string $state): string
    {
        return hash_hmac('sha256', $id.'|'.$hash.'|'.$state, hash_hkdf('sha256', $this->key, 32, 'eagle-eye-integrity-lifecycle-v1'));
    }

    private function identityHmac(string $type, string $id): string
    {
        return hash_hmac('sha256', json_encode([$type, $id], JSON_THROW_ON_ERROR), hash_hkdf('sha256', $this->key, 32, 'eagle-eye-erasure-identity-v1'));
    }

    private function tagHmac(string $tag): string
    {
        return hash_hmac('sha256', $tag, hash_hkdf('sha256', $this->key, 32, 'eagle-eye-integrity-tag-v1'));
    }

    /** @param array<string, mixed> $row */
    private function metadataHmac(array $row): string
    {
        $values = [];

        foreach (['entry_id', 'actor_hmac', 'subject_hmac', 'class_hmac', 'logged_at', 'channel', 'severity', 'tags'] as $field) {
            $value = $row[$field] ?? null;

            if ('logged_at' === $field && is_string($value)) {
                $value = (new DateTimeImmutable($value))->format('Y-m-d H:i:s.u');
            }

            if ('tags' === $field && is_string($value)) {
                $value = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            }

            $values[$field] = $value;
        }

        return hash_hmac('sha256', json_encode($values, JSON_THROW_ON_ERROR), hash_hkdf('sha256', $this->key, 32, 'eagle-eye-integrity-metadata-v1'));
    }

    private function assertKey(): void
    {
        $fingerprint = hash('sha256', hash_hkdf('sha256', $this->key, 32, 'eagle-eye-erasure-identity-v1'));
        $query = $this->connection->table('activity_security_state')->where('name', 'coordination');
        (clone $query)->whereNull('key_fingerprint')->update(['key_fingerprint' => $fingerprint]);
        $stored = $query->value('key_fingerprint');

        if (! is_string($stored) || ! hash_equals($fingerprint, $stored)) {
            throw new RuntimeException('Eagle Eye security key does not match stored integrity state.');
        }
    }

    private function lockState(): stdClass
    {
        $this->connection->table('activity_integrity_state')->where('name', 'chain')->increment('version');
        $state = $this->connection->table('activity_integrity_state')->where('name', 'chain')->lockForUpdate()->first();

        if (! is_object($state)) {
            throw new RuntimeException('Eagle Eye integrity state is unavailable.');
        }

        return $state;
    }

    private function nextSegmentId(?string $predecessor): string
    {
        $segmentId = (string) Str::ulid();

        if (null === $predecessor || strcmp($segmentId, $predecessor) > 0) {
            return $segmentId;
        }

        $characters = mb_str_split($predecessor);

        for ($offset = count($characters) - 1; $offset >= 0; $offset--) {
            $position = mb_strpos(self::ULID_ALPHABET, $characters[$offset]);

            if (false === $position) {
                throw new RuntimeException('Eagle Eye integrity predecessor is not a valid ULID.');
            }

            if ($position < mb_strlen(self::ULID_ALPHABET, '8bit') - 1) {
                $characters[$offset] = self::ULID_ALPHABET[$position + 1];

                return implode('', $characters);
            }

            $characters[$offset] = self::ULID_ALPHABET[0];
        }

        throw new RuntimeException('Eagle Eye integrity segment identifier space is exhausted.');
    }

    private function anchor(
        string $segmentId,
        ?string $predecessor,
        string $firstEntryId,
        string $lastEntryId,
        int $entryCount,
        string $headHash,
        string $state,
    ): string {
        return hash_hmac('sha256', implode('|', [
            $segmentId,
            $predecessor ?? '',
            $firstEntryId,
            $lastEntryId,
            (string) $entryCount,
            $headHash,
            $state,
        ]), hash_hkdf('sha256', $this->key, 32, 'eagle-eye-integrity-anchor-v1'));
    }

    private function timestamp(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }
}
