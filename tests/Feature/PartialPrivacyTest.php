<?php

declare(strict_types=1);

use Dotswan\EagleEye\Data\ActivityDraft;
use Dotswan\EagleEye\Enums\ActivityChannel;
use Dotswan\EagleEye\Contracts\DeclaresActivityPrivacy;
use Dotswan\EagleEye\Redaction\Strategies\PartialMaskStrategy;
use Dotswan\EagleEye\Resolution\ActivityPrivacyDeclaration;
use Dotswan\EagleEye\Contracts\Resolution\SubjectResolverInterface;
use Dotswan\EagleEye\Data\ActivityEntry;
use Dotswan\EagleEye\Enums\RedactionMode;
use Dotswan\EagleEye\Facades\EagleEye;
use Dotswan\EagleEye\Redaction\Redactor;
use Illuminate\Database\Eloquent\Model;

final class PartialPrivacyModel extends Model implements DeclaresActivityPrivacy
{
    /** @var list<string> */
    protected $guarded = [];

    public $timestamps = false;

    /** @return array<string, RedactionMode> */
    public function activityPrivacy(): array
    {
        return ['content' => RedactionMode::Drop];
    }
}

final class PartialLabelSubject implements DeclaresActivityPrivacy
{
    public static int $calls = 0;

    public function __construct(
        public string $id = 'f111c688-0000-0000-0000-000000000000',
        public string $name = 'Jane Doe',
    ) {
    }

    /** @return array<string, RedactionMode> */
    public function activityPrivacy(): array
    {
        self::$calls++;

        return ['name' => RedactionMode::Partial];
    }

    public function getKey(): string
    {
        return $this->id;
    }
}

it('partially masks emails, phones, names and short strings', function (): void {
    $strategy = new PartialMaskStrategy();

    expect($strategy->apply('q@mailinator.com', 'x'))->toBe('q***@mailinator.com')
        ->and($strategy->apply('Jane Doe', 'x'))->toBe('J*** D***')
        ->and($strategy->apply('ab', 'x'))->toBe('**')
        ->and($strategy->apply('+31 6 123 45 78', 'x'))->toBe('+316*****78')
        ->and($strategy->apply(42, 'x'))->toBe(42)
        ->and($strategy->apply(null, 'x'))->toBeNull();
});

it('keeps change pairs while masking each side separately', function (): void {
    $redactor = app(Redactor::class);
    $secrets = [];
    $allowed = [RedactionMode::Mask->value, RedactionMode::Partial->value, RedactionMode::Hash->value, RedactionMode::Drop->value];

    $payload = $redactor->redact([
        'changes' => [
            'email'    => ['old' => 'alice@example.com', 'new' => 'bob@example.com'],
            'password' => ['old' => 'hunter2', 'new' => 'hunter3'],
        ],
    ], $secrets, [], $allowed);

    $email = $payload['changes']['email'];
    $password = $payload['changes']['password'];

    expect($email)->toBe(['old' => 'a***@example.com', 'new' => 'b***@example.com'])
        ->and($password)->toBeArray()
        ->and(array_keys($password))->toBe(['old', 'new'])
        ->and($password['old'])->not->toBe('hunter2')
        ->and($password['new'])->not->toBe('hunter3');
});

it('detects and partially masks email values in nested leaves', function (): void {
    $redactor = app(Redactor::class);
    $secrets = [];

    $payload = $redactor->redact([
        'body' => ['contact' => 'reach.me@example.com'],
    ], $secrets, [], [RedactionMode::Mask->value, RedactionMode::Partial->value]);

    expect($payload['body']['contact'])->toBe('r***@example.com');
});

it('applies a declared drop mode to captured model attributes', function (): void {
    $fake = EagleEye::fake();
    $model = new PartialPrivacyModel();
    $model->setRawAttributes([
        'id'      => 7,
        'content' => '<a href="https://example.com/verify">Verify</a>',
        'title'   => 'Verify Email Address',
    ], true);

    event('eloquent.created: '.PartialPrivacyModel::class, [$model]);

    /** @var ActivityEntry|null $entry */
    $entry = null;

    foreach ($fake->recorded() as $recorded) {
        if ('model.created' === $recorded->action) {
            $entry = $recorded;
        }
    }

    expect($entry)->not->toBeNull()
        ->and($entry?->payload['changes'] ?? [])->not->toHaveKey('content')
        ->and($entry?->payload['changes']['title']['new'] ?? null)->toBe('Verify Email Address');
});

it('skips masked attributes when resolving a subject label', function (): void {
    $subject = app(SubjectResolverInterface::class)->resolve(new PartialLabelSubject());

    expect($subject->label)->toBe('Partial Label Subject #f111c688')
        ->and($subject->label)->not->toContain('Jane');
});

it('does not partially mask a field declared for encryption', function (): void {
    EagleEye::fake();
    $draft = ActivityDraft::make('model.created', ActivityChannel::Model)
        ->withPayload(['secret_mail' => 'q@example.com']);
    $draft->fieldModes = ['secret_mail' => RedactionMode::Encrypt];

    $entry = eagleEye()->record($draft);

    expect($entry?->payload['secret_mail'] ?? null)->toBeArray()
        ->and($entry?->payload['secret_mail'] ?? [])->toHaveKey('__secret');
});

it('leaves a field declared keep untouched', function (): void {
    EagleEye::fake();
    $draft = ActivityDraft::make('model.created', ActivityChannel::Model)
        ->withPayload(['keep_mail' => 'q@example.com']);
    $draft->fieldModes = ['keep_mail' => RedactionMode::Keep];

    $entry = eagleEye()->record($draft);

    expect($entry?->payload['keep_mail'] ?? null)->toBe('q@example.com');
});

it('partially masks an undeclared email value', function (): void {
    EagleEye::fake();
    $draft = ActivityDraft::make('model.created', ActivityChannel::Model)
        ->withPayload(['note' => 'q@example.com']);

    $entry = eagleEye()->record($draft);

    expect($entry?->payload['note'] ?? null)->toBe('q***@example.com');
});

it('treats a unicode email local part as an email', function (): void {
    $strategy = new PartialMaskStrategy();

    expect($strategy->isEmail('é@ex.com'))->toBeTrue()
        ->and($strategy->apply('é@ex.com', 'email'))->toBe('é***@ex.com');
});

it('resolves a model privacy declaration once per model', function (): void {
    PartialLabelSubject::$calls = 0;
    $subject = new PartialLabelSubject();
    $declaration = app(ActivityPrivacyDeclaration::class);
    $declaration->for($subject);
    $declaration->for($subject);

    expect(PartialLabelSubject::$calls)->toBe(1);
});

it('masks change values in the summary and title source', function (): void {
    $fake = EagleEye::fake();
    $model = new PartialPrivacyModel();
    $model->setRawAttributes(['id' => 1, 'email' => 'old@example.test'], true);
    $model->setAttribute('email', 'new@example.test');

    event('eloquent.updated: '.PartialPrivacyModel::class, [$model]);

    $entry = null;

    foreach ($fake->recorded() as $recorded) {
        if ('model.updated' === $recorded->action) {
            $entry = $recorded;
        }
    }

    expect($entry)->not->toBeNull()
        ->and($entry?->summary)->not->toContain('new@example.test')
        ->and($entry?->summary)->toContain('***')
        ->and(json_encode($entry?->payload['title_source']['params']['change_summary'] ?? []))->not->toContain('new@example.test');
});
