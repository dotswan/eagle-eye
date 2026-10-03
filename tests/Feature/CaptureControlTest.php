<?php

declare(strict_types=1);

use Dotswan\EagleEye\Attributes\ActivitySubject;
use Dotswan\EagleEye\Attributes\ActivityTags;
use Dotswan\EagleEye\Attributes\DoNotLog;
use Dotswan\EagleEye\Contracts\EagleEyeInterface;
use Dotswan\EagleEye\Contracts\Resolution\SubjectResolverInterface;
use Dotswan\EagleEye\Data\ActivityDraft;
use Dotswan\EagleEye\Data\ActivityEntry;
use Dotswan\EagleEye\Facades\EagleEye;

#[ActivitySubject('sensitive_record')]
#[ActivityTags('pii')]
#[DoNotLog]
final class DoNotLogSubject
{
    public string $id = '91';
}

it('honors the enabled kill switch', function (): void {
    config()->set('eagle-eye.enabled', false);
    $fake = EagleEye::fake();

    expect(app(EagleEyeInterface::class)->record('hidden'))->toBeNull();
    $fake->assertCount(0);
});

it('stops recording when the kill switch flips at runtime', function (): void {
    $fake = EagleEye::fake();

    eagleEye()->record('before');
    config()->set('eagle-eye.enabled', false);
    eagleEye()->record('after');

    $fake->assertCount(1);
    $fake->assertNotLogged(static fn (ActivityEntry $entry): bool => 'after' === $entry->action);
});

it('honors the environment allowlist', function (): void {
    config()->set('eagle-eye.environments', ['production']);
    $fake = EagleEye::fake();

    expect(app(EagleEyeInterface::class)->record('hidden'))->toBeNull();
    $fake->assertCount(0);
});

it('enforces tag configuration and metadata opt-out', function (): void {
    config()->set('eagle-eye.tags.static', ['application:my-app']);
    config()->set('eagle-eye.tags.max', 2);
    $fake = EagleEye::fake();

    eagleEye()->record('visible');
    eagle_eye_tag(['request:42', 'ignored'], static function (): void {
        eagleEye()->record('tagged');
    });

    $subject = app(SubjectResolverInterface::class)->resolve(new DoNotLogSubject());
    eagleEye()->record(ActivityDraft::make('hidden')->withSubject($subject));

    $fake->assertCount(2);
    $fake->assertTagged('application:my-app');
    $fake->assertLogged(static fn (ActivityEntry $entry): bool => 'tagged' === $entry->action && ['application:my-app', 'request:42'] === $entry->tags);
    $fake->assertNotLogged(static fn (ActivityEntry $entry): bool => 'hidden' === $entry->action);
});

it('normalizes unsupported payload values through the real recorder', function (): void {
    $fake = EagleEye::fake();

    expect(EagleEye::record('normalized', ['callback' => static fn (): bool => true]))->not->toBeNull();
    $fake->assertLogged(static fn (ActivityEntry $entry): bool => '[closure]' === $entry->payload['callback']);
});
