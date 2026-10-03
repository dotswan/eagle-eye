<?php

declare(strict_types=1);

use Dotswan\EagleEye\Contracts\Pipeline\ActivityPipeInterface;
use Dotswan\EagleEye\Contracts\Pipeline\DraftEnricherInterface;
use Dotswan\EagleEye\Contracts\Resolution\ActorResolverInterface;
use Dotswan\EagleEye\Data\ActivityDraft;
use Dotswan\EagleEye\Facades\EagleEye;
use Dotswan\EagleEye\Pipeline\Pipes\AttachCorrelation;
use Dotswan\EagleEye\Pipeline\Pipes\NormalizePayload;
use Dotswan\EagleEye\Pipeline\Pipes\ReadSubjectMetadata;
use Dotswan\EagleEye\Pipeline\Pipes\ResolveActor;
use Dotswan\EagleEye\Pipeline\Pipes\RedactSensitive;
use Dotswan\EagleEye\Pipeline\Pipes\ResolveContext;
use Dotswan\EagleEye\Pipeline\Pipes\RunEnrichers;
use Dotswan\EagleEye\Pipeline\PipelineRunner;
use Dotswan\EagleEye\Integrity\IntegrityVerifier;
use Dotswan\EagleEye\Contracts\Driver\ActivityLogDriverFactoryInterface;
use Dotswan\EagleEye\Data\ActivityEntry;
use Dotswan\EagleEye\Data\Query\ActivityQuery;
use Dotswan\EagleEye\Enums\ActivityChannel;
use Dotswan\EagleEye\Enums\ActivitySeverity;
use Dotswan\EagleEye\Tests\Support\FakeAuthFactory;
use Dotswan\EagleEye\Testing\DriverContractTests;
use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Dotswan\EagleEye\Contracts\Integrity\IntegrityChainInterface;
use Dotswan\EagleEye\Integrity\IntegrityLedger;
use Dotswan\EagleEye\Integrity\CanonicalEntryHasher;
use Dotswan\EagleEye\Drivers\ActivityLogSchema;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\DB;

final class AuditFlagPipe implements ActivityPipeInterface
{
    public function handle(ActivityDraft $draft, Closure $next): ?ActivityDraft
    {
        $draft->summary = 'flagged-by-host-pipe';

        return $next($draft);
    }
}

final class SensitiveEnricher implements DraftEnricherInterface
{
    public function enrich(ActivityDraft $draft): void
    {
        $draft->payload['extra'] = ['api_key' => 'super-secret-value', 'kept' => 'ok'];
    }
}

final class DroppingPipe implements ActivityPipeInterface
{
    public function handle(ActivityDraft $draft, Closure $next): ?ActivityDraft
    {
        return null;
    }
}

it('lets a host insert custom pipes through configuration', function (): void {
    config()->set('eagle-eye.pipeline', [
        ResolveActor::class,
        ResolveContext::class,
        AttachCorrelation::class,
        NormalizePayload::class,
        ReadSubjectMetadata::class,
        AuditFlagPipe::class,
    ]);
    $fake = EagleEye::fake();

    eagleEye()->record('piped');

    $fake->assertLogged(static fn ($entry): bool => 'flagged-by-host-pipe' === $entry->summary);
});

it('drops entries silently when a pipe returns null', function (): void {
    config()->set('eagle-eye.pipeline', [DroppingPipe::class, AttachCorrelation::class]);
    $fake = EagleEye::fake();

    expect(eagleEye()->record('dropped'))->toBeNull();
    $fake->assertCount(0);
});

it('ignores malformed and non-pipe pipeline configuration', function (): void {
    $draft = ActivityDraft::make('pipeline.probe');
    config()->set('eagle-eye.pipeline', 'invalid');

    expect(app(PipelineRunner::class)->run($draft))->toBe($draft);

    config()->set('eagle-eye.pipeline', [42, 'Missing\\Pipe', stdClass::class, AuditFlagPipe::class]);

    expect(app(PipelineRunner::class)->run($draft)?->summary)->toBe('flagged-by-host-pipe');
});

it('resolves unauthenticated console actors through the default resolver', function (): void {
    $actor = app(ActorResolverInterface::class)->resolve();

    expect($actor?->type)->toBe('console');
});

it('resolves authenticated users without lazy loading', function (): void {
    app()->instance(AuthFactory::class, new FakeAuthFactory(new GenericUser(['id' => 7, 'name' => 'Sara'])));

    $actor = app(ActorResolverInterface::class)->resolve();

    expect($actor?->type)->toBe('user')
        ->and($actor?->id)->toBe('7')
        ->and($actor?->label)->toBe('Sara');
});

it('stamps actor environment and host through the pipeline', function (): void {
    $fake = EagleEye::fake();

    eagleEye()->record('contextual');

    $fake->assertLogged(
        static fn ($entry): bool => 'console' === $entry->actor?->type
            && 'testing' === $entry->environment
            && 'unknown' !== $entry->hostname
            && null !== $entry->memoryKb,
    );
});

it('minimises actor labels when configured for read-time resolution', function (): void {
    config()->set('eagle-eye.privacy.actor_label', 'resolve_at_read');
    app()->instance(AuthFactory::class, new FakeAuthFactory(new GenericUser(['id' => 7, 'name' => 'Sara'])));
    $fake = EagleEye::fake();

    eagleEye()->record('privacy.safe');

    $fake->assertLogged(static fn (ActivityEntry $entry): bool => '7' === $entry->actor?->id && null === $entry->actor->label);
});

it('seals configured channels and detects tampering', function (): void {
    config()->set('eagle-eye.integrity.enabled', true);
    config()->set('eagle-eye.integrity.channels', ['auth']);
    $fake = EagleEye::fake();

    eagleEye()->record('auth.first', channel: ActivityChannel::Auth);
    eagleEye()->record('auth.second', channel: ActivityChannel::Auth);
    eagleEye()->record('custom.unsealed');

    $entries = $fake->recorded();
    expect($entries[0]->integrityHash)->not->toBeNull()
        ->and($entries[1]->integrityHash)->not->toBeNull()
        ->and($entries[2]->integrityHash)->toBeNull()
        ->and(app(IntegrityVerifier::class)->verify()->valid())->toBeTrue();

    $storage = $entries[0]->toStorage();
    $storage['title'] = 'tampered';
    app(ActivityLogDriverFactoryInterface::class)->driver()->write(ActivityEntry::fromStorage($storage));

    expect(app(IntegrityVerifier::class)->verify()->valid())->toBeFalse();
});

it('continues one persistent integrity chain after scoped worker state is replaced', function (): void {
    config()->set('eagle-eye.integrity.enabled', true);
    config()->set('eagle-eye.integrity.channels', ['auth']);
    EagleEye::fake();

    $first = eagleEye()->record('auth.first', channel: ActivityChannel::Auth);
    app()->forgetInstance(IntegrityLedger::class);
    app()->forgetInstance(IntegrityChainInterface::class);
    app()->forgetScopedInstances();
    $second = eagleEye()->record('auth.second', channel: ActivityChannel::Auth);
    $rows = DB::table('activity_integrity_entries')->orderBy('sequence')->get();
    $secondRow = $rows->get(1);

    if (! is_object($secondRow)) {
        throw new LogicException('Expected a second integrity ledger row.');
    }

    expect($first?->integrityHash)->not->toBeNull()
        ->and($second?->integrityHash)->not->toBeNull()
        ->and($rows)->toHaveCount(2)
        ->and($secondRow->previous_hash)->toBe($first?->integrityHash)
        ->and(app(IntegrityVerifier::class)->verify()->valid())->toBeTrue();
});

it('serializes independent integrity workers across duplicate seals and segment rollover', function (): void {
    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('PCNTL is required for the process-boundary regression.');
    }

    $database = tempnam(sys_get_temp_dir(), 'eagle-eye-integrity-');

    if (! is_string($database)) {
        throw new RuntimeException('Unable to allocate the integrity concurrency database.');
    }

    $connect = static function () use ($database): SQLiteConnection {
        $pdo = new PDO('sqlite:'.$database);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA busy_timeout = 2000');

        return new SQLiteConnection($pdo, $database, '', ['name' => 'sqlite']);
    };
    $connection = $connect();
    ActivityLogSchema::createErasureState($connection->getSchemaBuilder());
    ActivityLogSchema::createIntegrityState($connection->getSchemaBuilder());
    $sequences = [801, 802, 803, 804, 805, 805];
    $go = $database.'.go';
    $children = [];

    foreach ($sequences as $worker => $sequence) {
        $child = pcntl_fork();

        if (-1 === $child) {
            throw new RuntimeException('Unable to fork an integrity worker.');
        }

        if (0 === $child) {
            $result = $database.'.result.'.$worker;

            try {
                file_put_contents($database.'.ready.'.$worker, 'ready');
                $deadline = microtime(true) + 5;

                while (! is_file($go) && microtime(true) < $deadline) {
                    usleep(1_000);
                }

                if (! is_file($go)) {
                    throw new RuntimeException('Integrity worker start barrier timed out.');
                }

                $ledger = new IntegrityLedger($connect(), new CanonicalEntryHasher(), str_repeat('s', 32), 2);
                file_put_contents($result, 'ok:'.$ledger->seal(DriverContractTests::entry($sequence)));
                exit(0);
            } catch (Throwable $exception) {
                file_put_contents($result, $exception::class.':'.$exception->getMessage());
                exit(1);
            }
        }

        $children[$worker] = $child;
    }

    try {
        $deadline = microtime(true) + 5;

        while (count(glob($database.'.ready.*') ?: []) < count($sequences) && microtime(true) < $deadline) {
            usleep(1_000);
        }

        expect(count(glob($database.'.ready.*') ?: []))->toBe(count($sequences));
        file_put_contents($go, 'go');
        $statuses = [];

        foreach ($children as $worker => $child) {
            pcntl_waitpid($child, $status);
            $statuses[$worker] = pcntl_wifexited($status) ? pcntl_wexitstatus($status) : 255;
        }

        expect($statuses)->each->toBe(0);
        $results = array_map(static fn (int $worker): string => (string) file_get_contents($database.'.result.'.$worker), array_keys($children));
        expect($results[4])->toBe($results[5]);

        $rows = $connection->table('activity_integrity_entries')->orderBy('segment_id')->orderBy('sequence')->get();
        $segments = $connection->table('activity_integrity_segments')->orderBy('id')->get();

        $firstSegment = $segments->get(0);
        $secondSegment = $segments->get(1);
        $thirdSegment = $segments->get(2);

        if (! is_object($firstSegment) || ! is_object($secondSegment) || ! is_object($thirdSegment)) {
            throw new LogicException('Expected three integrity segments.');
        }

        expect($rows)->toHaveCount(5)
            ->and($segments)->toHaveCount(3)
            ->and($segments->pluck('entry_count')->map(static fn (mixed $count): int => (int) $count)->all())->toBe([2, 2, 1])
            ->and($firstSegment->predecessor_segment_id)->toBeNull()
            ->and($secondSegment->predecessor_segment_id)->toBe($firstSegment->id)
            ->and($thirdSegment->predecessor_segment_id)->toBe($secondSegment->id);

        $restarted = new IntegrityLedger($connect(), new CanonicalEntryHasher(), str_repeat('s', 32), 2);
        $restarted->seal(DriverContractTests::entry(806));
        $segments = $connection->table('activity_integrity_segments')->orderBy('id')->get();
        $lastSegment = $segments->get(2);

        if (! is_object($lastSegment)) {
            throw new LogicException('Expected a restarted integrity segment.');
        }

        expect($connection->table('activity_integrity_entries')->count())->toBe(6)
            ->and($segments)->toHaveCount(3)
            ->and($lastSegment->entry_count)->toBe(2)
            ->and($lastSegment->state)->toBe('sealed')
            ->and($restarted->validAnchor($lastSegment))->toBeTrue();
    } finally {
        foreach (glob($database.'.*') ?: [] as $artifact) {
            unlink($artifact);
        }

        unlink($database);
    }
});

it('authenticates integrity lifecycle transitions for delivery erasure and scoped pruning', function (): void {
    $ledger = app(IntegrityLedger::class);
    $erased = DriverContractTests::entry(701, actorId: 'erase-me', tags: ['other'], channel: ActivityChannel::Auth, severity: ActivitySeverity::Warning);
    $pruned = DriverContractTests::entry(702, actorId: 'keep-me', tags: ['billing'], channel: ActivityChannel::Auth, severity: ActivitySeverity::Warning);
    $tampered = DriverContractTests::entry(703, actorId: 'tampered');

    foreach ([$erased, $pruned, $tampered] as $entry) {
        $ledger->seal($entry);
    }

    $ledger->transitionIds([$erased->id], 'delivered');
    $ledger->transitionIds([$erased->id], 'discarded');
    $ledger->authorizeErasure('user', 'erase-me');
    $ledger->authorizePrune(new DateTimeImmutable(), (new ActivityQuery())
        ->channels(ActivityChannel::Auth)
        ->severities(ActivitySeverity::Warning)
        ->withAnyTag(['billing']));

    expect(DB::table('activity_integrity_entries')->where('entry_id', $erased->id)->value('lifecycle'))->toBe('erased')
        ->and(DB::table('activity_integrity_entries')->where('entry_id', $pruned->id)->value('lifecycle'))->toBe('pruned');

    expect(fn () => $ledger->transitionIds([$tampered->id], 'unknown'))->toThrow(RuntimeException::class, 'Invalid integrity lifecycle transition');

    DB::table('activity_integrity_entries')->where('entry_id', $tampered->id)->update(['lifecycle_hmac' => str_repeat('0', 64)]);
    expect(fn () => $ledger->transitionIds([$tampered->id], 'delivered'))->toThrow(RuntimeException::class, 'Integrity lifecycle authentication failed');

    config()->set('database.connections.integrity_other', [
        'driver'   => 'sqlite',
        'database' => ':memory:',
        'prefix'   => '',
    ]);
    expect(fn () => $ledger->assertRemovalConnection(DB::connection('integrity_other')))
        ->toThrow(RuntimeException::class, 'same connection');
});

it('treats a missing hash on a required channel as an integrity failure', function (): void {
    config()->set('eagle-eye.integrity.enabled', true);
    config()->set('eagle-eye.integrity.channels', ['auth']);
    $fake = EagleEye::fake();
    $entry = eagleEye()->record('auth.missing-hash', channel: ActivityChannel::Auth);

    if (! $entry instanceof ActivityEntry) {
        throw new LogicException('Expected a recorded integrity entry.');
    }

    $storage = $entry->toStorage();
    $storage['integrity_hash'] = null;
    app(ActivityLogDriverFactoryInterface::class)->driver()->write(ActivityEntry::fromStorage($storage));
    $result = app(IntegrityVerifier::class)->verify();

    expect($result->valid())->toBeFalse()
        ->and($result->invalidEntryIds)->toContain($entry->id)
        ->and($fake->recorded())->toHaveCount(1);
});

it('detects missing integrity records and whole segment deletion', function (): void {
    config()->set('eagle-eye.integrity.enabled', true);
    config()->set('eagle-eye.integrity.channels', ['auth']);
    config()->set('eagle-eye.integrity.segment_size', 1);
    EagleEye::fake();

    eagleEye()->record('auth.first', channel: ActivityChannel::Auth);
    eagleEye()->record('auth.second', channel: ActivityChannel::Auth);
    eagleEye()->record('auth.third', channel: ActivityChannel::Auth);
    $segments = DB::table('activity_integrity_segments')->orderBy('id')->pluck('id')->all();

    DB::table('activity_integrity_entries')->where('segment_id', $segments[1])->delete();
    DB::table('activity_integrity_segments')->where('id', $segments[1])->delete();
    $middle = app(IntegrityVerifier::class)->verify();

    DB::table('activity_integrity_entries')->where('segment_id', $segments[2])->delete();
    DB::table('activity_integrity_segments')->where('id', $segments[2])->delete();
    $tail = app(IntegrityVerifier::class)->verify();

    expect($middle->valid())->toBeFalse()
        ->and($middle->invalidCount)->toBeGreaterThanOrEqual(1)
        ->and($tail->valid())->toBeFalse()
        ->and($tail->invalidEntryIds)->toContain('chain:tail');
});

it('bounds retained integrity failures as invalid history grows', function (): void {
    EagleEye::fake();

    foreach (array_chunk(range(1, 1_001), 100) as $sequences) {
        $segments = [];
        $entries = [];

        foreach ($sequences as $sequence) {
            $segmentId = sprintf('01J%023d', $sequence);
            $entryId = sprintf('01K%023d', $sequence);
            $segments[] = [
                'id'                     => $segmentId,
                'predecessor_segment_id' => 1 === $sequence ? null : sprintf('01J%023d', $sequence - 1),
                'first_entry_id'         => $entryId,
                'last_entry_id'          => $entryId,
                'entry_count'            => 1,
                'head_hash'              => str_repeat('a', 64),
                'state'                  => 'sealed',
                'opened_at'              => '2026-09-10 00:00:00.000000',
                'sealed_at'              => '2026-09-10 00:00:00.000000',
                'anchor_hmac'            => str_repeat('b', 64),
            ];
            $entries[] = [
                'entry_id'      => $entryId,
                'segment_id'    => $segmentId,
                'sequence'      => 1,
                'previous_hash' => null,
                'entry_hash'    => str_repeat('a', 64),
            ];
        }

        DB::table('activity_integrity_segments')->insert($segments);
        DB::table('activity_integrity_entries')->insert($entries);
    }

    DB::table('activity_integrity_state')->where('name', 'chain')->update([
        'last_segment_id' => sprintf('01J%023d', 1_001),
    ]);
    memory_reset_peak_usage();
    $baseline = memory_get_usage(true);
    $result = app(IntegrityVerifier::class)->verify();

    expect($result->valid())->toBeFalse()
        ->and($result->invalidCount)->toBe(2_002)
        ->and($result->invalidEntryIds)->toHaveCount(1_000)
        ->and($result->truncated())->toBeTrue()
        ->and(memory_get_peak_usage(true) - $baseline)->toBeLessThan(32 * 1024 * 1024);
})->group('performance');

it('runs enrichers before redaction in the default pipeline', function (): void {
    $pipeline = config('eagle-eye.pipeline');
    $enrich = array_search(RunEnrichers::class, $pipeline, true);
    $redact = array_search(RedactSensitive::class, $pipeline, true);

    expect($enrich)->not->toBeFalse()
        ->and($redact)->not->toBeFalse();

    if (false === $enrich || false === $redact) {
        return;
    }

    expect((int) $enrich)->toBeLessThan((int) $redact);
});

it('redacts data added by a tagged enricher', function (): void {
    app()->tag([SensitiveEnricher::class], 'eagle-eye.enrichers');
    app()->forgetInstance(RunEnrichers::class);
    $fake = EagleEye::fake();

    eagleEye()->record('enriched');

    $fake->assertLogged(static function (ActivityEntry $entry): bool {
        $extra = $entry->payload['extra'] ?? [];

        return is_array($extra)
            && 'ok' === ($extra['kept'] ?? null)
            && 'super-secret-value' !== ($extra['api_key'] ?? 'super-secret-value');
    });
});
