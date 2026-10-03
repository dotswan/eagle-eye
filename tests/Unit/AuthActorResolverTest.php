<?php

declare(strict_types=1);

use Dotswan\EagleEye\Resolution\AuthActorResolver;
use Dotswan\EagleEye\Tests\Support\FakeAuthFactory;

it('falls back to a guest actor for unauthenticated http contexts', function (): void {
    $actor = new AuthActorResolver(new FakeAuthFactory(null), runningInConsole: false)->resolve();

    expect($actor->type)->toBe('guest');
});

it('falls back to a console actor for unauthenticated cli contexts', function (): void {
    $actor = new AuthActorResolver(new FakeAuthFactory(null), runningInConsole: true)->resolve();

    expect($actor->type)->toBe('console');
});
