<?php

declare(strict_types=1);

use Dotswan\EagleEye\Data\ActivityEntry;
use Dotswan\EagleEye\Contracts\Title\TemplateRendererInterface;
use Dotswan\EagleEye\Contracts\Title\TitleResolverInterface;
use Dotswan\EagleEye\Data\ActivityDraft;
use Dotswan\EagleEye\Data\Actor;
use Dotswan\EagleEye\Data\Subject;
use Dotswan\EagleEye\EagleEyeServiceProvider;
use Dotswan\EagleEye\Enums\ActivityChannel;
use Dotswan\EagleEye\Facades\EagleEye;
use Dotswan\EagleEye\Title\TitleResolverChain;
use Illuminate\Support\ServiceProvider;

class TaggedTitleResolver implements TitleResolverInterface
{
    public function resolve(ActivityDraft $draft): string
    {
        return 'Tagged '.$draft->action;
    }
}

it('renders a human sentence for model updates', function (): void {
    EagleEye::fake();

    $draft = ActivityDraft::make('campaign.updated', ActivityChannel::Model)
        ->withActor(new Actor('user', '7', 'Sara'))
        ->withSubject(new Subject('campaign', '42', 'Campaign #42'))
        ->withPayload(['changes' => ['status' => ['old' => 'pending', 'new' => 'approved']]]);

    $entry = eagleEye()->record($draft);

    expect($entry)->not->toBeNull();

    if (!$entry instanceof ActivityEntry) {
        return;
    }

    expect($entry->title)->toBe('Updated Campaign #42 (Status)')
        ->and($entry->summary)->toBe('Status: pending → approved')
        ->and($entry->payload['title_source']['key'])->toBe('titles.model.updated');
});

it('lists changed field names and summarizes more than three with a count', function (): void {
    EagleEye::fake();

    $draft = ActivityDraft::make('campaign.updated', ActivityChannel::Model)
        ->withActor(new Actor('user', '7', 'Sara'))
        ->withSubject(new Subject('campaign', '42', 'Campaign #42'))
        ->withPayload(['changes' => [
            'status'   => ['old' => 'pending', 'new' => 'approved'],
            'title'    => ['old' => 'Old', 'new' => 'New'],
            'notes_a'  => ['old' => 'a', 'new' => 'b'],
            'owner_id' => ['old' => '1', 'new' => '2'],
        ]]);

    $entry = eagleEye()->record($draft);

    expect($entry?->title)->toBe('Updated Campaign #42 (Status, Title, Notes A +1 more)');
});

it('excludes timestamp fields from the changed field list', function (): void {
    EagleEye::fake();

    $draft = ActivityDraft::make('campaign.updated', ActivityChannel::Model)
        ->withSubject(new Subject('campaign', '42', 'Campaign #42'))
        ->withPayload(['changes' => [
            'status'     => ['old' => 'a', 'new' => 'b'],
            'updated_at' => ['old' => '2026-01-01', 'new' => '2026-01-02'],
        ]]);

    $entry = eagleEye()->record($draft);

    expect($entry?->title)->toBe('Updated Campaign #42 (Status)');
});

it('renders null and boolean change values truthfully in the summary', function (): void {
    EagleEye::fake();

    $draft = ActivityDraft::make('campaign.updated', ActivityChannel::Model)
        ->withActor(new Actor('user', '7', 'Sara'))
        ->withSubject(new Subject('campaign', '42', 'Campaign #42'))
        ->withPayload(['changes' => [
            'payment_id' => ['old' => null, 'new' => 'abc'],
            'active'     => ['old' => false, 'new' => true],
        ]]);

    $entry = eagleEye()->record($draft);

    expect($entry?->summary)->toBe('Payment Id: null → abc, Active: false → true');
});

it('summarises object and list change values without dumping json', function (): void {
    EagleEye::fake();

    $draft = ActivityDraft::make('campaign.updated', ActivityChannel::Model)
        ->withActor(new Actor('user', '7', 'Sara'))
        ->withSubject(new Subject('campaign', '42', 'Campaign #42'))
        ->withPayload(['changes' => [
            'meta' => ['old' => ['a' => 1], 'new' => ['b' => 2]],
            'tags' => ['old' => ['x'], 'new' => ['y']],
        ]]);

    $entry = eagleEye()->record($draft);

    expect($entry?->summary)->toBe('Meta: {…} → {…}, Tags: […] → […]');
});

it('never renders empty parentheses when no fields changed', function (): void {
    EagleEye::fake();

    $draft = ActivityDraft::make('campaign.updated', ActivityChannel::Model)
        ->withActor(new Actor('user', '7', 'Sara'))
        ->withSubject(new Subject('campaign', '42', 'Campaign #42'))
        ->withPayload(['changes' => []]);

    $entry = eagleEye()->record($draft);

    expect($entry?->title)->toBe('Updated Campaign #42')
        ->and($entry?->title)->not->toContain('()');
});

it('renders a model state change title', function (): void {
    EagleEye::fake();

    $draft = ActivityDraft::make('model.updated', ActivityChannel::Model)
        ->withSubject(new Subject('campaign', '42', 'Campaign #42'))
        ->withPayload(['state' => ['field' => 'status', 'old' => 'pending', 'new' => 'failed']]);

    $entry = eagleEye()->record($draft);

    expect($entry?->title)->toBe('Campaign #42 Status changed to failed')
        ->and($entry?->payload['title_source']['key'])->toBe('titles.model.state_changed');
});

it('resolves an actor-free human title for every channel', function (ActivityChannel $channel): void {
    EagleEye::fake();

    $draft = ActivityDraft::make('thing.happened', $channel)
        ->withActor(new Actor('user', '7', 'Sara'));

    $entry = eagleEye()->record($draft);

    expect($entry?->title)->not->toBeNull()
        ->and($entry?->title)->not->toContain('Sara')
        ->and($entry?->title)->not->toMatch('/:[a-z_]+/');
})->with(static fn (): iterable => array_map(static fn (ActivityChannel $channel): array => [$channel], ActivityChannel::cases()));

it('never leaves a placeholder for any template rendered with empty params', function (): void {
    $titles = require dirname(__DIR__, 2).'/lang/en/titles.php';
    $renderer = app(TemplateRendererInterface::class);

    foreach (array_keys($titles) as $key) {
        $rendered = $renderer->render((string) __('eagle-eye::titles.'.$key), []);

        expect($rendered)->not->toMatch('/:[a-z_]+/');
    }
});

it('collapses punctuation left behind by missing placeholders', function (): void {
    $renderer = app(TemplateRendererInterface::class);

    expect($renderer->render('Updated X (:changed_fields)', []))->toBe('Updated X')
        ->and($renderer->render('Ran :command (exit :exit_code, :duration)', ['command' => 'cache:clear']))
        ->toBe('Ran cache:clear (exit)');
});

it('does not strip colons that belong to substitution values', function (): void {
    $renderer = app(TemplateRendererInterface::class);

    expect($renderer->render('Ran :command', ['command' => 'cache:clear']))->toBe('Ran cache:clear');
});

it('includes the command and exit code in console titles', function (): void {
    EagleEye::fake();

    $draft = ActivityDraft::make('console.finished', ActivityChannel::Console)
        ->withPayload(['command' => 'cache:clear', 'exit_code' => 0, 'duration_ms' => 1200]);

    $entry = eagleEye()->record($draft);

    expect($entry?->title)->toBe('Ran cache:clear (exit 0, 1.2s)');
});

it('uses a short job name in queue titles', function (): void {
    EagleEye::fake();

    $draft = ActivityDraft::make('queue.processed', ActivityChannel::Queue)
        ->withPayload(['job' => 'App\\Jobs\\ProcessOrderJob', 'duration_ms' => 5000]);

    $entry = eagleEye()->record($draft);

    expect($entry?->title)->toBe('Ran ProcessOrder (5s)');
});

it('re-renders title sources in a second locale', function (): void {
    EagleEye::fake();

    app('translator')->addLines([
        'titles.model.updated' => ':subject_label aktualisiert',
    ], 'de', 'eagle-eye');

    $draft = ActivityDraft::make('campaign.updated', ActivityChannel::Model)
        ->withActor(new Actor('user', '7', 'Sara'))
        ->withSubject(new Subject('campaign', '42', 'Campaign #42'));

    $entry = eagleEye()->record($draft);

    expect($entry)->not->toBeNull();

    if (!$entry instanceof ActivityEntry) {
        return;
    }

    $source = $entry->payload['title_source'];
    $template = (string) __('eagle-eye::'.$source['key'], [], 'de');
    $rendered = app(TemplateRendererInterface::class)->render($template, $source['params']);

    expect($rendered)->toBe('Campaign #42 aktualisiert');
});

it('publishes translation files under the eagle-eye-lang tag', function (): void {
    expect(ServiceProvider::pathsToPublish(EagleEyeServiceProvider::class, 'eagle-eye-lang'))->not->toBeEmpty();
});

it('uses a host title resolver bound through the public contract', function (): void {
    EagleEye::fake();
    app()->instance(TitleResolverInterface::class, new class () implements TitleResolverInterface {
        public function resolve(ActivityDraft $draft): string
        {
            return 'Host title for '.$draft->action;
        }
    });

    $entry = eagleEye()->record('custom.bound');

    expect($entry?->title)->toBe('Host title for custom.bound');
});

it('runs a tagged title resolver before the built-ins', function (): void {
    EagleEye::fake();
    app()->tag([TaggedTitleResolver::class], 'eagle-eye.title_resolvers');
    app()->forgetInstance(TitleResolverChain::class);
    app()->forgetInstance(TitleResolverInterface::class);

    $entry = eagleEye()->record('custom.tagged');

    expect($entry?->title)->toBe('Tagged custom.tagged');
});

it('preserves punctuation inside substituted values', function (): void {
    $renderer = app(TemplateRendererInterface::class);

    expect($renderer->render('Sent ":subject" to :count recipients', [
        'subject' => 'Hello, World (v2)',
        'count'   => 2,
    ]))->toBe('Sent "Hello, World (v2)" to 2 recipients');
});

it('stores only the title params the template actually uses', function (): void {
    EagleEye::fake();

    $entry = eagleEye()->record(
        ActivityDraft::make('queue.processed', ActivityChannel::Queue)->withPayload([
            'job'         => 'App\\Jobs\\GenerateReportJob',
            'connection'  => 'redis',
            'attempts'    => 1,
            'duration_ms' => 12,
        ]),
    );

    expect($entry?->payload['title_source']['params'] ?? [])->not->toHaveKey('error')
        ->and($entry?->payload['title_source']['params'] ?? [])->not->toHaveKey('attempts')
        ->and($entry?->payload['title_source']['params'] ?? [])->toHaveKey('job_short');
});

it('never stores the actor in a title source', function (): void {
    EagleEye::fake();

    $entry = eagleEye()->record(
        ActivityDraft::make('mystery.happened', ActivityChannel::Custom)
            ->withActor(new Actor('user', '7', 'Sara'))
            ->withSubject(new Subject('thing', '42', 'Thing')),
    );

    if (! $entry instanceof ActivityEntry) {
        throw new RuntimeException('Expected a recorded entry.');
    }

    expect($entry->payload['title_source']['params'] ?? [])->not->toHaveKey('actor')
        ->and($entry->title)->not->toContain('Sara');
});
