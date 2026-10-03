<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Dotswan\EagleEye\Data\RequestSnapshot;
use Dotswan\EagleEye\Drivers\MemoryDriver;
use Dotswan\EagleEye\Contracts\Driver\ActivityLogDriverInterface;
use Dotswan\EagleEye\Data\ActivityDraft;
use Dotswan\EagleEye\Data\Actor;
use Dotswan\EagleEye\Data\Subject;
use Dotswan\EagleEye\Enums\ActivityChannel;
use Dotswan\EagleEye\Enums\FilterDecision;
use Dotswan\EagleEye\Filter\CompiledFilterMatcher;
use Dotswan\EagleEye\Filter\Rules\ActorRule;
use Dotswan\EagleEye\Filter\Rules\ChannelRule;
use Dotswan\EagleEye\Filter\Rules\ClassRule;
use Dotswan\EagleEye\Filter\Rules\ClosureRule;
use Dotswan\EagleEye\Filter\Rules\EnvironmentRule;
use Dotswan\EagleEye\Filter\Rules\MethodRule;
use Dotswan\EagleEye\Filter\Rules\TagRule;
use Dotswan\EagleEye\Filter\Rules\UrlRule;
use Dotswan\EagleEye\Tests\Support\NaiveFilterMatcher;

function filterDraft(): ActivityDraft
{
    $draft = ActivityDraft::make('tested', ActivityChannel::Http);
    $draft->request = new RequestSnapshot('get', 'https://app.test/admin/reports?tab=1', 'admin.reports', 200);
    $draft->environment = 'testing';

    return $draft;
}

it('matches url rules against path variants', function (): void {
    $draft = filterDraft();

    expect((new UrlRule([], ['admin/*']))->decide($draft))->toBe(FilterDecision::Deny)
        ->and((new UrlRule([], ['api/*']))->decide($draft))->toBe(FilterDecision::Abstain)
        ->and((new UrlRule(['admin/*']))->decide($draft))->toBe(FilterDecision::Allow)
        ->and((new UrlRule(['api/*']))->decide($draft))->toBe(FilterDecision::Deny);
});

it('matches http method rules case insensitively', function (): void {
    $draft = filterDraft();

    expect((new MethodRule([], ['GET']))->decide($draft))->toBe(FilterDecision::Deny)
        ->and((new MethodRule([], ['POST']))->decide($draft))->toBe(FilterDecision::Abstain)
        ->and((new MethodRule(['GET']))->decide($draft))->toBe(FilterDecision::Allow);
});

it('matches class rules with subclasses and namespace wildcards', function (): void {
    $draft = filterDraft()->withSubject(new Subject('report', '7', 'Report', Request::class));

    expect((new ClassRule([], [Request::class]))->decide($draft))->toBe(FilterDecision::Deny)
        ->and((new ClassRule([], [Symfony\Component\HttpFoundation\Request::class]))->decide($draft))->toBe(FilterDecision::Deny)
        ->and((new ClassRule([], ['Illuminate\\Http\\*']))->decide($draft))->toBe(FilterDecision::Deny)
        ->and((new ClassRule([], ['Dotswan\\*']))->decide($draft))->toBe(FilterDecision::Abstain)
        ->and((new ClassRule(['Illuminate\\Http\\*']))->decide($draft))->toBe(FilterDecision::Allow);
});

it('matches class rules for interface implementations', function (): void {
    $draft = filterDraft()->withSubject(new Subject('driver', '1', 'Driver', MemoryDriver::class));

    expect((new ClassRule([], [ActivityLogDriverInterface::class]))->decide($draft))->toBe(FilterDecision::Deny)
        ->and((new ClassRule(['Dotswan\\EagleEye\\Drivers\\*']))->decide($draft))->toBe(FilterDecision::Allow);
});

it('matches actor rules against type id pairs', function (): void {
    $draft = filterDraft()->withActor(new Actor('user', '42'));

    expect((new ActorRule([], ['user:42']))->decide($draft))->toBe(FilterDecision::Deny)
        ->and((new ActorRule([], ['user:*']))->decide($draft))->toBe(FilterDecision::Deny)
        ->and((new ActorRule([], ['admin:*']))->decide($draft))->toBe(FilterDecision::Abstain)
        ->and((new ActorRule(['42']))->decide($draft))->toBe(FilterDecision::Allow);
});

it('matches channel environment and tag rules', function (): void {
    $draft = filterDraft()->withTags(['billing', 'gdpr']);

    expect((new ChannelRule([], ['http']))->decide($draft))->toBe(FilterDecision::Deny)
        ->and((new ChannelRule(['http']))->decide($draft))->toBe(FilterDecision::Allow)
        ->and((new EnvironmentRule([], ['production']))->decide($draft))->toBe(FilterDecision::Abstain)
        ->and((new EnvironmentRule([], ['testing']))->decide($draft))->toBe(FilterDecision::Deny)
        ->and((new TagRule([], ['gdpr']))->decide($draft))->toBe(FilterDecision::Deny)
        ->and((new TagRule(['billing', 'crm']))->decide($draft))->toBe(FilterDecision::Allow)
        ->and((new TagRule(['crm']))->decide($draft))->toBe(FilterDecision::Deny);
});

it('supports closure rules and abstains on unknown returns', function (): void {
    $draft = filterDraft();

    expect((new ClosureRule(static fn (ActivityDraft $d): FilterDecision => FilterDecision::Deny))->decide($draft))->toBe(FilterDecision::Deny)
        ->and((new ClosureRule(static fn (ActivityDraft $d): string => 'nonsense'))->decide($draft))->toBe(FilterDecision::Abstain);
});

it('produces identical decisions to a naive matcher over a corpus', function (): void {
    mt_srand(4815);

    $tokens = ['up', 'health', 'admin', 'reports', 'api', 'v1', 'v2', 'horizon', 'jobs', 'user', '42', 'billing'];
    $patterns = ['up', 'health', 'horizon/*', 'admin/*/show', '*/reports', '/^api\\/(v1|v2)\\//', '/user\\.\\d+$/', 'a*c*e'];

    for ($i = 0; $i < 40; $i++) {
        $count = mt_rand(1, 4);
        $picked = [];

        for ($j = 0; $j < $count; $j++) {
            $picked[] = $tokens[mt_rand(0, count($tokens) - 1)];
        }

        $patterns[] = match (mt_rand(0, 2)) {
            0       => implode('/', $picked),
            1       => implode('/', $picked).'*',
            default => '*'.implode('/', $picked),
        };
    }

    $values = ['up', 'health', 'horizon/jobs', 'api/v1/users', 'api/v2/orders', 'admin/reports', 'user.42', 'ace'];

    for ($i = 0; $i < 120; $i++) {
        $count = mt_rand(1, 4);
        $picked = [];

        for ($j = 0; $j < $count; $j++) {
            $picked[] = $tokens[mt_rand(0, count($tokens) - 1)];
        }

        $values[] = implode('/', $picked);
    }

    $compiled = new CompiledFilterMatcher($patterns);
    $naive = new NaiveFilterMatcher($patterns);

    foreach ($values as $value) {
        expect($compiled->matches($value))->toBe($naive->matches($value), "Decision mismatch for [{$value}]");
    }
});
