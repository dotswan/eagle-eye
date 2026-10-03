<?php

declare(strict_types=1);

use Dotswan\EagleEye\Enums\ActivityChannel;
use Dotswan\EagleEye\Enums\ActivitySeverity;
use Dotswan\EagleEye\Data\ActivityEntry;
use Dotswan\EagleEye\Contracts\Pipeline\ActivityPipeInterface;
use Dotswan\EagleEye\Data\ActivityDraft;
use Dotswan\EagleEye\Data\RequestSnapshot;
use Dotswan\EagleEye\Enums\FilterDecision;
use Dotswan\EagleEye\Facades\EagleEye;
use Dotswan\EagleEye\Filter\FilterChain;
use Dotswan\EagleEye\Filter\Gate;
use Dotswan\EagleEye\Pipeline\Pipes\AttachCorrelation;
use Dotswan\EagleEye\Contracts\Filter\FilterRuleInterface;
use Dotswan\EagleEye\Data\Actor;
use Dotswan\EagleEye\Filter\CompiledFilterMatcher;
use Dotswan\EagleEye\Pipeline\Pipes\ProjectRequestParts;

final class GateSpyPipe implements ActivityPipeInterface
{
    public static int $invocations = 0;

    public function handle(ActivityDraft $draft, Closure $next): ?ActivityDraft
    {
        self::$invocations++;

        return $next($draft);
    }
}

final class AllowFilterRule implements FilterRuleInterface
{
    public function decide(ActivityDraft $draft): FilterDecision
    {
        return FilterDecision::Allow;
    }
}

function httpDraft(string $method = 'GET', string $url = 'https://app.test/orders', ?string $route = null): ActivityDraft
{
    $draft = ActivityDraft::make('tested', ActivityChannel::Http);
    $draft->request = new RequestSnapshot($method, $url, $route, 200, null, '145.23.11.9', 'Mozilla/5.0');

    return $draft;
}

it('logs entries from any channel under default configuration', function (): void {
    $fake = EagleEye::fake();

    expect(eagleEye()->record('anything.happened'))->not->toBeNull();
    $fake->assertCount(1);
});

it('denies requests matching the url except list', function (): void {
    $fake = EagleEye::fake();

    config()->set('eagle-eye.filters.url.except', ['blocked/*']);

    expect(eagleEye()->record(httpDraft(url: 'https://app.test/blocked/report')))->toBeNull()
        ->and(eagleEye()->record(httpDraft(url: 'https://app.test/allowed/report')))->not->toBeNull();

    $fake->assertCount(1);
});

it('suppresses default noise urls and methods', function (string $method, string $url): void {
    EagleEye::fake();

    expect(eagleEye()->record(httpDraft($method, $url)))->toBeNull();
})->with([
    ['GET', 'https://app.test/horizon/jobs'],
    ['GET', 'https://app.test/up'],
    ['OPTIONS', 'https://app.test/orders'],
    ['HEAD', 'https://app.test/orders'],
]);

it('excludes livewire assets but keeps livewire update requests', function (): void {
    EagleEye::fake();

    expect(eagleEye()->record(httpDraft(url: 'https://app.test/livewire-3c457c3d/livewire.js')))->toBeNull()
        ->and(eagleEye()->record(httpDraft(url: 'https://app.test/livewire-3c457c3d/livewire.min.js.map')))->toBeNull()
        ->and(eagleEye()->record(httpDraft('POST', 'https://app.test/livewire-3c457c3d/update')))->not->toBeNull();
});

it('lets deny beat allow while abstains fall through to the default action', function (): void {
    $fake = EagleEye::fake();

    config()->set('eagle-eye.filters.rules', [static fn (ActivityDraft $draft): FilterDecision => FilterDecision::Allow]);
    config()->set('eagle-eye.filters.default_action', 'deny');

    expect(eagleEye()->record('abstained'))->not->toBeNull();
    expect(eagleEye()->record(httpDraft(url: 'https://app.test/horizon/metrics')))->toBeNull();

    $fake->assertCount(1);
});

it('drops entries below the configured severity floor', function (): void {
    EagleEye::fake();
    config()->set('eagle-eye.filters.severity.min', 'warning');

    expect(eagleEye()->record('quiet.debug', [], ActivityChannel::Custom, ActivitySeverity::Debug))->toBeNull()
        ->and(eagleEye()->record('loud.error', [], ActivityChannel::Custom, ActivitySeverity::Error))->not->toBeNull();
});

it('skips the whole pipeline when the gate rejects', function (): void {
    EagleEye::fake();
    GateSpyPipe::$invocations = 0;

    config()->set('eagle-eye.filters.channels.except', ['custom']);
    config()->set('eagle-eye.pipeline', [GateSpyPipe::class, AttachCorrelation::class]);

    expect(eagleEye()->record('rejected'))->toBeNull()
        ->and(GateSpyPipe::$invocations)->toBe(0);
});

it('withholds configured request parts', function (): void {
    $fake = EagleEye::fake();
    config()->set('eagle-eye.filters.request_parts', ['only' => [], 'except' => ['ip', 'user_agent']]);

    $entry = eagleEye()->record(httpDraft());

    expect($entry)->not->toBeNull();

    if (!$entry instanceof ActivityEntry) {
        return;
    }

    expect($entry->request?->ip)->toBeNull()
        ->and($entry->request?->userAgent)->toBeNull();

    $fake->assertCount(1);
});

it('merges per-route overrides over the global filter configuration', function (): void {
    EagleEye::fake();
    config()->set('eagle-eye.overrides', [
        'admin/*' => ['request_parts' => ['only' => ['query'], 'except' => []]],
    ]);

    $entry = eagleEye()->record(httpDraft(url: 'https://app.test/admin/reports'));

    expect($entry)->not->toBeNull();

    if (!$entry instanceof ActivityEntry) {
        return;
    }

    expect($entry->request?->ip)->toBeNull('override only-list should withhold ip');
});

it('refreshes gate and chain rules when configuration changes within one scope', function (): void {
    EagleEye::fake();
    $draft = httpDraft();
    $gate = app(Gate::class);
    $chain = app(FilterChain::class);

    expect($gate->allows($draft))->toBeTrue()
        ->and($chain->decide($draft))->toBe(FilterDecision::Allow);

    config()->set('eagle-eye.filters.method.except', ['GET']);

    expect($gate->allows($draft))->toBeFalse()
        ->and($chain->decide($draft))->toBe(FilterDecision::Deny);

    config()->set('eagle-eye.filters.method.except', []);

    expect($gate->allows($draft))->toBeTrue()
        ->and($chain->decide($draft))->toBe(FilterDecision::Allow);
});

it('replaces exclusion lists in matching overrides before the scalar gate', function (): void {
    EagleEye::fake();
    config()->set('eagle-eye.overrides', [
        'health.check' => [
            'url'    => ['except' => []],
            'method' => ['except' => []],
        ],
    ]);

    expect(eagleEye()->record(httpDraft('HEAD', 'https://app.test/up', 'health.check')))->not->toBeNull()
        ->and(eagleEye()->record(httpDraft('HEAD', 'https://app.test/up', 'other.route')))->toBeNull();
});

it('replaces lists without retaining global tail values and refreshes route overrides', function (): void {
    $chain = app(FilterChain::class);
    $draft = httpDraft(route: 'orders.show');
    config()->set('eagle-eye.filters.method.only', ['GET', 'POST']);
    config()->set('eagle-eye.overrides', ['orders.*' => ['method' => ['only' => ['PUT']]]]);

    expect($chain->effectiveFilters($draft)['method'])->toBe(['only' => ['PUT'], 'except' => ['OPTIONS', 'HEAD']]);

    config()->set('eagle-eye.overrides', ['orders.*' => ['method' => ['only' => []]]]);

    expect($chain->effectiveFilters($draft)['method'])->toBe(['only' => [], 'except' => ['OPTIONS', 'HEAD']]);
});

it('handles empty patterns and malformed filter configuration safely', function (): void {
    expect((new CompiledFilterMatcher(['']))->isEmpty())->toBeTrue();

    $chain = app(FilterChain::class);
    config()->set('eagle-eye.filters', 'invalid');
    config()->set('eagle-eye.overrides', ['broken' => 'invalid', 42 => []]);

    expect($chain->effectiveFilters(ActivityDraft::make('plain')))->toBe([]);

    config()->set('eagle-eye.filters', [
        'default_action' => 'invalid',
        'method'         => ['only' => 'invalid'],
        'rules'          => [AllowFilterRule::class, stdClass::class, 'Missing\\Rule'],
    ]);

    expect($chain->decide(httpDraft()))->toBe(FilterDecision::Allow);
});

it('applies actor exclusions and only-list misses in the cheap gate', function (): void {
    $gate = app(Gate::class);
    $draft = httpDraft()->withActor(new Actor('user', '42'));
    config()->set('eagle-eye.filters.actors.except', ['user:42']);

    expect($gate->allows($draft))->toBeFalse();

    config()->set('eagle-eye.filters.actors.except', []);
    config()->set('eagle-eye.filters.method.only', ['POST']);

    expect($gate->allows($draft))->toBeFalse();

    config()->set('eagle-eye.filters.method.only', ['GET']);
    config()->set('eagle-eye.filters.url.only', 'invalid');

    expect($gate->allows($draft))->toBeTrue();
});

it('withholds response bodies with malformed optional request-part lists', function (): void {
    config()->set('eagle-eye.filters.request_parts', [
        'only'   => 'invalid',
        'except' => ['response_body'],
    ]);
    $draft = httpDraft()->withPayload([
        'request'  => ['query' => ['page' => 1]],
        'response' => ['body' => ['secret' => true], 'size' => 20],
    ]);

    app(ProjectRequestParts::class)->handle($draft, static fn (ActivityDraft $entry): ActivityDraft => $entry);

    expect($draft->payload['response'])->toBe(['size' => 20]);
});
