<?php

declare(strict_types=1);

use Dotswan\EagleEye\Attributes\ActivityField;
use Dotswan\EagleEye\Attributes\ActivityLabel;
use Dotswan\EagleEye\Attributes\ActivitySubject;
use Dotswan\EagleEye\Contracts\Resolution\FieldLabelResolverInterface;
use Dotswan\EagleEye\Contracts\Resolution\PayloadNormalizerInterface;
use Dotswan\EagleEye\Contracts\Resolution\SubjectResolverInterface;
use Dotswan\EagleEye\Contracts\Security\LogAccessPolicyInterface;
use Dotswan\EagleEye\Contracts\Title\TemplateRendererInterface;
use Dotswan\EagleEye\Privacy\ErasureRegistry;

#[ActivitySubject('api_key')]
#[ActivityLabel('reference')]
final class DefaultBindingSubject
{
    public string $id = '42';

    public string $reference = 'Production key';

    #[ActivityField(label: 'Access level')]
    public string $accessLevel = 'admin';
}

it('binds usable Laravel-only defaults', function (): void {
    $subject = app(SubjectResolverInterface::class)->resolve(new DefaultBindingSubject());

    expect($subject->type)->toBe('api_key')
        ->and($subject->id)->toBe('42')
        ->and($subject->label)->toBe('Production key')
        ->and(app(FieldLabelResolverInterface::class)->resolve(DefaultBindingSubject::class, 'accessLevel'))->toBe('Access level')
        ->and(app(PayloadNormalizerInterface::class)->normalize(['safe' => true]))->toBe(['safe' => true])
        ->and(app(TemplateRendererInterface::class)->render(':actor updated :subject', ['actor' => 'Sara', 'subject' => 'Campaign']))->toBe('Sara updated Campaign')
        ->and(app(LogAccessPolicyInterface::class)->canView())->toBeFalse();
});

it('publishes only configuration backed by current behavior', function (): void {
    expect(array_keys(config('eagle-eye')))->toBe([
        'security_key',
        'enabled',
        'environments',
        'default',
        'drivers',
        'tags',
        'pipeline',
        'filters',
        'overrides',
        'privacy',
        'retention',
        'integrity',
        'sampling',
        'transport',
        'api',
        'capture',
        'payload',
        'subjects',
    ]);
});

it('rejects an erasure barrier lifetime that cannot cover delayed replay', function (): void {
    config()->set('eagle-eye.privacy.erasure_barrier_days', 30);
    config()->set('eagle-eye.transport.spool.max_age_days', 30);
    app()->forgetInstance(ErasureRegistry::class);

    expect(fn () => app(ErasureRegistry::class))
        ->toThrow(InvalidArgumentException::class, 'must exceed maximum replay age');
});
