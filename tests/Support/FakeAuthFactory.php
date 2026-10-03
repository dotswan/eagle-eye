<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Tests\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Auth\Guard;

final readonly class FakeAuthFactory implements AuthFactory
{
    public function __construct(private ?Authenticatable $user)
    {
    }

    public function guard($name = null): Guard
    {
        return new FakeGuard($this->user);
    }

    public function shouldUse($name): void
    {
    }
}
