<?php

declare(strict_types=1);

use Dotswan\EagleEye\Redaction\PathMatcher;

it('matches exact paths and single segment wildcards', function (): void {
    expect(PathMatcher::matches('user.password', 'user.password'))->toBeTrue()
        ->and(PathMatcher::matches('user.*', 'user.password'))->toBeTrue()
        ->and(PathMatcher::matches('user.*', 'user.settings.password'))->toBeFalse()
        ->and(PathMatcher::matches('user.*', 'other.password'))->toBeFalse();
});

it('matches deep wildcards across any depth', function (): void {
    expect(PathMatcher::matches('**.password', 'password'))->toBeTrue()
        ->and(PathMatcher::matches('**.password', 'user.password'))->toBeTrue()
        ->and(PathMatcher::matches('**.password', 'a.b.c.password'))->toBeTrue()
        ->and(PathMatcher::matches('billing.**.iban', 'billing.iban'))->toBeTrue()
        ->and(PathMatcher::matches('billing.**.iban', 'billing.debtor.primary.iban'))->toBeTrue()
        ->and(PathMatcher::matches('billing.**.iban', 'other.iban'))->toBeFalse();
});

it('matches a terminal deep wildcard and rejects an exhausted search', function (): void {
    expect(PathMatcher::matches('payload.**', 'payload.any.depth'))->toBeTrue()
        ->and(PathMatcher::matches('payload.**.token', 'payload.any.depth'))->toBeFalse();
});

it('matches array element paths produced by numeric keys', function (): void {
    expect(PathMatcher::matches('users.*.token', 'users.0.token'))->toBeTrue()
        ->and(PathMatcher::matches('users.**.token', 'users.14.address.token'))->toBeTrue()
        ->and(PathMatcher::matches('*', 'password'))->toBeTrue();
});

it('bounds malicious patterns and treats regex delimiters literally', function (): void {
    expect(PathMatcher::matches('query.token~', 'query.token~'))->toBeTrue()
        ->and(PathMatcher::matches(str_repeat('a', 1025), 'a'))->toBeFalse()
        ->and(PathMatcher::matches(implode('.', array_fill(0, 33, '**')), 'a'))->toBeFalse()
        ->and(PathMatcher::matches('**.token', implode('.', array_fill(0, 65, 'a')).'.token'))->toBeFalse();
});
