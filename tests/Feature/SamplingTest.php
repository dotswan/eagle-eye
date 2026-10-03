<?php

declare(strict_types=1);

use Dotswan\EagleEye\Contracts\Sampling\SamplerInterface;
use Dotswan\EagleEye\Data\ActivityDraft;
use Dotswan\EagleEye\Data\ActivityEntry;
use Dotswan\EagleEye\Data\RequestSnapshot;
use Dotswan\EagleEye\Data\TraceContext;
use Dotswan\EagleEye\Enums\ActivityChannel;
use Dotswan\EagleEye\Facades\EagleEye;

function sampledDraft(ActivityChannel $channel = ActivityChannel::Custom, ?string $route = null): ActivityDraft
{
    $draft = ActivityDraft::make('sampled', $channel)->withTrace(TraceContext::create());

    if (null !== $route) {
        $draft->request = new RequestSnapshot('GET', 'https://app.test/'.$route, $route, 200);
    }

    return $draft;
}

it('makes the same sampling decision for the same trace', function (): void {
    config()->set('eagle-eye.sampling.rate', 0.5);
    $sampler = app(SamplerInterface::class);
    $draft = sampledDraft();

    $first = $sampler->passes($draft);
    $second = $sampler->passes(clone $draft);

    expect($second)->toBe($first);
});

it('treats zero and one rates exactly', function (): void {
    $sampler = app(SamplerInterface::class);

    config()->set('eagle-eye.sampling.rate', 0.0);
    expect($sampler->passes(sampledDraft()))->toBeFalse();

    config()->set('eagle-eye.sampling.rate', 1.0);
    expect($sampler->passes(sampledDraft()))->toBeTrue();
});

it('lands within tolerance around a half rate', function (): void {
    config()->set('eagle-eye.sampling.rate', 0.5);
    $sampler = app(SamplerInterface::class);

    $draws = 10_000;
    $passes = 0;

    for ($i = 0; $i < $draws; $i++) {
        if ($sampler->passes(sampledDraft())) {
            $passes++;
        }
    }

    expect($passes)->toBeGreaterThan(4_600)
        ->and($passes)->toBeLessThan(5_400);
});

it('honours channel and route overrides', function (): void {
    $sampler = app(SamplerInterface::class);

    config()->set('eagle-eye.sampling.rate', 1.0);
    config()->set('eagle-eye.sampling.channels', ['http' => 0.0]);
    config()->set('eagle-eye.sampling.routes', ['orders.*' => 0.0]);

    expect($sampler->passes(sampledDraft(ActivityChannel::Http)))->toBeFalse()
        ->and($sampler->passes(sampledDraft(ActivityChannel::Custom, 'orders.index')))->toBeFalse()
        ->and($sampler->passes(sampledDraft(ActivityChannel::Custom, 'reports.show')))->toBeTrue();
});

it('drops sampled-out entries through the real pipeline', function (): void {
    $fake = EagleEye::fake();
    config()->set('eagle-eye.sampling.rate', 0.0);

    expect(eagleEye()->record('never.seen'))->toBeNull();
    $fake->assertCount(0);

    config()->set('eagle-eye.sampling.rate', 1.0);
    expect(eagleEye()->record('seen'))->toBeInstanceOf(ActivityEntry::class);
});
