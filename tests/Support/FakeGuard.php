<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Tests\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;

final readonly class FakeGuard implements Guard
{
    public function __construct(private ?Authenticatable $user)
    {
    }

    public function check(): bool
    {
        return $this->user instanceof Authenticatable;
    }

    public function guest(): bool
    {
        return !$this->user instanceof Authenticatable;
    }

    public function user(): ?Authenticatable
    {
        return $this->user;
    }

    public function id(): int|string|null
    {
        return $this->user?->getAuthIdentifier();
    }

    /** @param array<string, mixed> $credentials */
    public function validate(array $credentials = []): bool
    {
        return false;
    }

    public function hasUser(): bool
    {
        return $this->user instanceof Authenticatable;
    }

    public function setUser(Authenticatable $user): Guard
    {
        return $this;
    }
}
