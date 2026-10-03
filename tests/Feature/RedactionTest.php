<?php

declare(strict_types=1);

use Dotswan\EagleEye\Contracts\Resolution\SubjectResolverInterface;
use Dotswan\EagleEye\Data\ActivityDraft;
use Dotswan\EagleEye\Enums\ActivityChannel;
use Dotswan\EagleEye\Data\RequestSnapshot;
use Dotswan\EagleEye\Attributes\ActivityField;
use Dotswan\EagleEye\Attributes\ActivitySubject;
use Dotswan\EagleEye\Contracts\Repository\ActivityRepositoryInterface;
use Dotswan\EagleEye\Contracts\Security\BlindIndexInterface;
use Dotswan\EagleEye\Contracts\Security\CipherInterface;
use Dotswan\EagleEye\Data\ActivityEntry;
use Dotswan\EagleEye\Data\Actor;
use Dotswan\EagleEye\Data\Subject;
use Dotswan\EagleEye\Enums\RedactionMode;
use Dotswan\EagleEye\Exceptions\SecretRevealDeniedException;
use Dotswan\EagleEye\Facades\EagleEye;
use Illuminate\Support\Facades\Gate;
use Dotswan\EagleEye\Drivers\DriverManager;
use Dotswan\EagleEye\Contracts\Driver\ActivityLogMaintenanceDriverInterface;
use Dotswan\EagleEye\Contracts\Driver\ActivityLogDriverInterface;
use Dotswan\EagleEye\Contracts\Driver\ActivityLogQueryDriverInterface;
use Dotswan\EagleEye\Testing\DriverContractTests;
use Dotswan\EagleEye\Tests\Support\AlwaysFailingDriver;
use Dotswan\EagleEye\Transport\BatchWriteException;
use Dotswan\EagleEye\Redaction\Strategies\DropStrategy;
use Dotswan\EagleEye\Redaction\IpTruncator;

#[ActivitySubject('bank_account')]
final class RedactedSubject
{
    public string $id = '19';

    #[ActivityField(redact: RedactionMode::Mask)]
    public string $token = '';

    #[ActivityField(redact: RedactionMode::Encrypt)]
    public string $iban = '';
}

afterEach(function (): void {
    app()->detectEnvironment(static fn (): string => 'testing');
});

it('rejects a mismatched detail identity returned by a query driver', function (): void {
    $requestedId = DriverContractTests::id(8010);
    $driver = Mockery::mock(ActivityLogDriverInterface::class.', '.ActivityLogQueryDriverInterface::class);

    if (! $driver instanceof ActivityLogDriverInterface || ! $driver instanceof ActivityLogQueryDriverInterface) {
        throw new LogicException('Expected a query driver mock.');
    }

    $driver->shouldReceive('find')->once()->with($requestedId)->andReturn(DriverContractTests::entry(8011));
    $manager = app(DriverManager::class);
    $manager->extend('wrong-detail', static fn (): ActivityLogDriverInterface => $driver);
    $manager->forgetDrivers();
    config()->set('eagle-eye.default', 'wrong-detail');
    app()->forgetInstance(ActivityRepositoryInterface::class);

    expect(app(ActivityRepositoryInterface::class)->find($requestedId))->toBeNull();
});

it('masks sensitive keys through the safety net without explicit rules', function (): void {
    $fake = EagleEye::fake();

    $entry = eagleEye()->record('checkout', [
        'customer' => ['name' => 'Sara', 'password' => 'hunter2-pass'],
        'billing'  => ['iban' => 'DE02100100100005123456', 'history' => [['card_number' => '4111111111111111'], ['note' => 'ok']]],
        'meta'     => ['otp_code' => '118822', 'authorization' => 'bearer-tok-991'],
        'safe'     => ['color' => 'blue'],
    ]);

    expect($entry)->not->toBeNull();

    if (! $entry instanceof ActivityEntry) {
        return;
    }

    $encoded = json_encode($entry->toStorage());

    foreach (['hunter2-pass', 'DE02100100100005123456', '4111111111111111', '118822', 'bearer-tok-991'] as $secret) {
        expect($encoded)->not->toContain($secret);
    }

    expect($entry->payload['safe']['color'])->toBe('blue')
        ->and($entry->payload['customer']['name'])->toBe('Sara')
        ->and(mb_strlen($entry->payload['customer']['password']))->toBe(mb_strlen('hunter2-pass'));

    $fake->assertCount(1);
});

it('drops keys matched by explicit path rules', function (): void {
    EagleEye::fake();
    config()->set('eagle-eye.privacy.paths', ['account.iban' => 'drop']);

    $entry = eagleEye()->record('account.viewed', ['account' => ['iban' => 'DE02100100100005123456', 'owner' => 'Sara']]);

    expect($entry?->payload['account'] ?? [])->toBe(['owner' => 'Sara']);
});

it('encrypts marked values into revealable secrets with blind indexes', function (): void {
    $fake = EagleEye::fake();
    config()->set('eagle-eye.privacy.paths', ['account.iban' => 'encrypt']);

    $entry = eagleEye()->record('account.viewed', ['account' => ['iban' => 'DE02100100100005123456']]);

    expect($entry)->not->toBeNull();

    if (! $entry instanceof ActivityEntry) {
        return;
    }

    expect($entry->payload['account']['iban'])->toBe(['__secret' => 'payload.account.iban']);
    $secret = $entry->secrets['payload.account.iban'] ?? null;
    expect($secret)->not->toBeNull();

    if (null === $secret) {
        return;
    }

    expect($secret->blindIndex)->toBe(app(BlindIndexInterface::class)->hash('DE02100100100005123456'));

    Gate::define('revealActivityLog', fn ($viewer = null): bool => true);
    $repository = app(ActivityRepositoryInterface::class);

    expect($repository->reveal($entry, 'payload.account.iban'))->toBe('DE02100100100005123456');

    $fake->assertLogged(
        static fn (ActivityEntry $audit): bool => 'eagle-eye.secret.revealed' === $audit->action
            && 'console' === $audit->actor?->type
            && 'payload.account.iban' === ($audit->payload['revealed_path'] ?? null),
    );
});

it('denies reveals without policy consent and records nothing', function (): void {
    $fake = EagleEye::fake();
    config()->set('eagle-eye.privacy.paths', ['account.iban' => 'encrypt']);

    $entry = eagleEye()->record('account.viewed', ['account' => ['iban' => 'DE02100100100005123456']]);
    $before = count($fake->recorded());

    expect($entry)->not->toBeNull();

    if (! $entry instanceof ActivityEntry) {
        return;
    }

    $denied = false;

    try {
        app(ActivityRepositoryInterface::class)->reveal($entry, 'payload.account.iban');
    } catch (SecretRevealDeniedException) {
        $denied = true;
    }

    expect($denied)->toBeTrue()
        ->and(count($fake->recorded()))->toBe($before);
});

it('returns no plaintext when the mandatory reveal audit is rejected', function (): void {
    EagleEye::fake();
    config()->set('eagle-eye.privacy.paths', ['account.iban' => 'encrypt']);
    $entry = eagleEye()->record('account.viewed', ['account' => ['iban' => 'DE02100100100005123456']]);

    if (!$entry instanceof ActivityEntry) {
        throw new LogicException('Expected a sealed activity entry.');
    }

    Gate::define('revealActivityLog', static fn ($viewer = null): bool => true);
    $manager = app(DriverManager::class);
    $manager->extend('audit-failure', static fn (): AlwaysFailingDriver => new AlwaysFailingDriver());
    $manager->forgetDrivers();
    config()->set('eagle-eye.default', 'audit-failure');

    expect(fn () => app(ActivityRepositoryInterface::class)->reveal($entry, 'payload.account.iban'))
        ->toThrow(BatchWriteException::class);
});

it('requires an explicit confirmed database audit destination in production', function (): void {
    EagleEye::fake();
    config()->set('eagle-eye.privacy.paths', ['account.iban' => 'encrypt']);
    $entry = eagleEye()->record('account.viewed', ['account' => ['iban' => 'DE02100100100005123456']]);

    if (! $entry instanceof ActivityEntry) {
        throw new LogicException('Expected a sealed activity entry.');
    }

    Gate::define('revealActivityLog', static fn ($viewer = null): bool => true);
    app()->detectEnvironment(static fn (): string => 'production');

    expect(fn () => app(ActivityRepositoryInterface::class)->reveal($entry, 'payload.account.iban'))
        ->toThrow(LogicException::class, 'explicitly configured database reveal audit driver');
});

it('writes production reveal audits to the configured database destination before decrypting', function (): void {
    EagleEye::fake();
    config()->set('eagle-eye.privacy.paths', ['account.iban' => 'encrypt']);
    config()->set('eagle-eye.privacy.reveal_audit_driver', 'database');
    $entry = eagleEye()->record('account.viewed', ['account' => ['iban' => 'DE02100100100005123456']]);

    if (! $entry instanceof ActivityEntry) {
        throw new LogicException('Expected a sealed activity entry.');
    }

    $auditDriver = app(DriverManager::class)->driver('database');

    if (! $auditDriver instanceof ActivityLogMaintenanceDriverInterface) {
        throw new LogicException('Expected the database audit driver to support installation.');
    }

    $auditDriver->install();
    Gate::define('revealActivityLog', static fn ($viewer = null): bool => true);
    app()->detectEnvironment(static fn (): string => 'production');

    expect(app(ActivityRepositoryInterface::class)->reveal($entry, 'payload.account.iban'))
        ->toBe('DE02100100100005123456')
        ->and(app('db')->connection()->table('activity_logs')->where('action', 'eagle-eye.secret.revealed')->count())->toBe(1);
});

it('returns no plaintext when the configured audit database is unavailable', function (): void {
    EagleEye::fake();
    config()->set('eagle-eye.privacy.paths', ['account.iban' => 'encrypt']);
    config()->set('eagle-eye.privacy.reveal_audit_driver', 'database');
    $entry = eagleEye()->record('account.viewed', ['account' => ['iban' => 'DE02100100100005123456']]);

    if (! $entry instanceof ActivityEntry) {
        throw new LogicException('Expected a sealed activity entry.');
    }

    Gate::define('revealActivityLog', static fn ($viewer = null): bool => true);
    app()->detectEnvironment(static fn (): string => 'production');
    app('db')->connection()->getSchemaBuilder()->dropIfExists('activity_logs');
    $cipher = Mockery::mock(CipherInterface::class);
    $cipher->shouldNotReceive('decrypt');
    app()->instance(CipherInterface::class, $cipher);
    app()->forgetInstance(ActivityRepositoryInterface::class);

    expect(fn () => app(ActivityRepositoryInterface::class)->reveal($entry, 'payload.account.iban'))
        ->toThrow(BatchWriteException::class);
});

it('returns no plaintext while the audit connection has an outer or nested transaction', function (int $levels): void {
    EagleEye::fake();
    config()->set('eagle-eye.privacy.paths', ['account.iban' => 'encrypt']);
    config()->set('eagle-eye.privacy.reveal_audit_driver', 'database');
    $entry = eagleEye()->record('account.viewed', ['account' => ['iban' => 'DE02100100100005123456']]);

    if (! $entry instanceof ActivityEntry) {
        throw new LogicException('Expected a sealed activity entry.');
    }

    $auditDriver = app(DriverManager::class)->driver('database');

    if (! $auditDriver instanceof ActivityLogMaintenanceDriverInterface) {
        throw new LogicException('Expected the database audit driver to support installation.');
    }

    $auditDriver->install();
    Gate::define('revealActivityLog', static fn ($viewer = null): bool => true);
    app()->detectEnvironment(static fn (): string => 'production');
    $connection = app('db')->connection();

    for ($level = 0; $level < $levels; $level++) {
        $connection->beginTransaction();
    }

    try {
        expect(fn () => app(ActivityRepositoryInterface::class)->reveal($entry, 'payload.account.iban'))
            ->toThrow(LogicException::class, 'uncommitted transaction')
            ->and($connection->table('activity_logs')->where('action', 'eagle-eye.secret.revealed')->count())->toBe(0);
    } finally {
        while ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }
    }
})->with(['outer transaction' => [1], 'nested transaction' => [2]]);

it('returns no plaintext while PDO owns an untracked audit transaction', function (): void {
    EagleEye::fake();
    config()->set('eagle-eye.privacy.paths', ['account.iban' => 'encrypt']);
    config()->set('eagle-eye.privacy.reveal_audit_driver', 'database');
    $entry = eagleEye()->record('account.viewed', ['account' => ['iban' => 'DE02100100100005123456']]);

    if (! $entry instanceof ActivityEntry) {
        throw new LogicException('Expected a sealed activity entry.');
    }

    $auditDriver = app(DriverManager::class)->driver('database');

    if (! $auditDriver instanceof ActivityLogMaintenanceDriverInterface) {
        throw new LogicException('Expected the database audit driver to support installation.');
    }

    $auditDriver->install();
    Gate::define('revealActivityLog', static fn ($viewer = null): bool => true);
    app()->detectEnvironment(static fn (): string => 'production');
    $connection = app('db')->connection();
    $pdo = $connection->getPdo();
    $cipher = Mockery::mock(CipherInterface::class);
    $cipher->shouldNotReceive('decrypt');
    app()->instance(CipherInterface::class, $cipher);
    app()->forgetInstance(ActivityRepositoryInterface::class);
    $pdo->beginTransaction();

    try {
        expect($connection->transactionLevel())->toBe(0)
            ->and(fn () => app(ActivityRepositoryInterface::class)->reveal($entry, 'payload.account.iban'))
            ->toThrow(LogicException::class, 'uncommitted transaction');
    } finally {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
});

it('records only committed audit acceptance when decryption later fails', function (): void {
    EagleEye::fake();
    config()->set('eagle-eye.privacy.paths', ['account.iban' => 'encrypt']);
    config()->set('eagle-eye.privacy.reveal_audit_driver', 'database');
    $entry = eagleEye()->record('account.viewed', ['account' => ['iban' => 'DE02100100100005123456']]);

    if (! $entry instanceof ActivityEntry) {
        throw new LogicException('Expected a sealed activity entry.');
    }

    $auditDriver = app(DriverManager::class)->driver('database');

    if (! $auditDriver instanceof ActivityLogMaintenanceDriverInterface) {
        throw new LogicException('Expected the database audit driver to support installation.');
    }

    $auditDriver->install();
    Gate::define('revealActivityLog', static fn ($viewer = null): bool => true);
    app()->detectEnvironment(static fn (): string => 'production');
    $cipher = Mockery::mock(CipherInterface::class);
    $cipher->shouldReceive('decrypt')->once()->andThrow(new RuntimeException('key unavailable'));
    app()->instance(CipherInterface::class, $cipher);
    app()->forgetInstance(ActivityRepositoryInterface::class);

    expect(fn () => app(ActivityRepositoryInterface::class)->reveal($entry, 'payload.account.iban'))
        ->toThrow(RuntimeException::class, 'key unavailable');

    $audit = app('db')->connection()->table('activity_logs')->where('action', 'eagle-eye.secret.revealed')->first();
    $payload = is_object($audit) ? json_decode((string) $audit->payload, true) : null;

    expect($payload)->toMatchArray([
        'revealed_entry' => $entry->id,
        'revealed_path'  => 'payload.account.iban',
        'outcome'        => 'audit_committed',
    ]);
    expect(json_encode($payload, JSON_THROW_ON_ERROR))->not->toContain('DE02100100100005123456');
});

it('honors activity field redaction attributes from the subject class', function (): void {
    config()->set('eagle-eye.privacy.paths', []);
    $fake = EagleEye::fake();

    $resolved = app(SubjectResolverInterface::class)->resolve(new RedactedSubject());

    $entry = eagleEye()->record(
        ActivityDraft::make('account.opened')
            ->withSubject($resolved)
            ->withPayload(['token' => 'tok-live-77', 'iban' => 'DE02100100100005123456', 'note' => 'ok']),
    );

    expect($entry)->not->toBeNull();

    if (! $entry instanceof ActivityEntry) {
        return;
    }

    expect($entry->payload['token'])->not->toBe('tok-live-77')
        ->and($entry->payload['iban'])->toBe(['__secret' => 'payload.iban'])
        ->and($entry->payload['note'])->toBe('ok');
});

it('truncates request ip addresses by default', function (): void {
    EagleEye::fake();

    $draft = ActivityDraft::make('request.handled', ActivityChannel::Http);
    $draft->request = new RequestSnapshot('GET', 'https://app.test/orders', 'orders.index', 200, null, '145.23.11.9', 'Mozilla');

    $entry = eagleEye()->record($draft);

    expect($entry?->request?->ip)->toBe('145.23.11.0');
});

it('redacts credentials copied into request actor and subject URLs', function (): void {
    EagleEye::fake();
    $draft = ActivityDraft::make('request.handled', ActivityChannel::Http)
        ->withActor(new Actor('user', '1', 'Sara', '/users/1?token=actor-secret&tab=profile'))
        ->withSubject(new Subject('order', '2', 'Order', url: '/orders/2?api_key=subject-secret'));
    $draft->request = new RequestSnapshot('GET', '/orders?password=request-secret&tab=summary');

    $entry = eagleEye()->record($draft);
    $encoded = json_encode($entry?->toStorage(), JSON_THROW_ON_ERROR);

    expect($encoded)->not->toContain('actor-secret', 'subject-secret', 'request-secret')
        ->and($entry?->request?->url)->toContain('tab=summary');
});

it('preserves URL fragments and supports explicit IP retention', function (): void {
    EagleEye::fake();
    config()->set('eagle-eye.privacy.ip.mode', 'keep');
    $draft = ActivityDraft::make('request.handled', ActivityChannel::Http);
    $draft->request = new RequestSnapshot('GET', '/orders?token=secret&tab=summary#history', ip: '145.23.11.9');

    $entry = eagleEye()->record($draft);

    expect($entry?->request?->url)->toEndWith('tab=summary#history')
        ->and($entry?->request?->ip)->toBe('145.23.11.9');
});

it('never leaks seeded secrets through randomized payload shapes', function (): void {
    mt_srand(20260904);

    $secrets = [
        'hunter2-pass'           => ['password', 'new_password', 'user_password'],
        'DE02100100100005123456' => ['iban', 'debtor_iban'],
        '4111111111111111'       => ['card_number', 'card'],
        '118822'                 => ['otp_code', 'otp'],
        'bearer-tok-991'         => ['authorization', 'access_token'],
    ];

    $safeValues = [];

    for ($run = 0; $run < 8; $run++) {
        $fake = EagleEye::fake();
        $payload = [];
        $safe = 'safe-value-'.$run;

        foreach ($secrets as $value => $keys) {
            $key = $keys[mt_rand(0, count($keys) - 1)];

            $payload = match (mt_rand(0, 2)) {
                0       => array_merge_recursive($payload, [$key => $value]),
                1       => array_merge_recursive($payload, ['level'.$run => [$key => $value]]),
                default => array_merge_recursive($payload, ['items' => [[$key => $value, 'note' => 'ok']]]),
            };
        }

        $payload['marker'] = $safe;
        $safeValues[] = $safe;

        $entry = eagleEye()->record('seeded.run.'.$run, $payload);

        expect($entry)->not->toBeNull();

        if (! $entry instanceof ActivityEntry) {
            continue;
        }

        $encoded = json_encode($entry->toStorage());

        foreach (array_keys($secrets) as $secret) {
            expect($encoded)->not->toContain($secret);
        }

        expect($encoded)->toContain($safe);
        $fake->assertCount(1);
    }
});

it('drops configured values without inspecting them', function (): void {
    expect((new DropStrategy())->apply('secret', 'payload.password'))->toBeNull();
});

it('supports non-byte-aligned IP truncation', function (): void {
    expect(IpTruncator::truncate('192.0.2.129', 25))->toBe('192.0.2.128')
        ->and(IpTruncator::truncate('2001:db8:ffff::', ipv6Bits: 49))->toBe('2001:db8:ffff::');
});
