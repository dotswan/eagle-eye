<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Resolution;

use Dotswan\EagleEye\Contracts\Resolution\ActorResolverInterface;
use Dotswan\EagleEye\Data\Actor;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Throwable;

final readonly class AuthActorResolver implements ActorResolverInterface
{
    public function __construct(
        private AuthFactory $auth,
        private bool $runningInConsole,
    ) {
    }

    public function resolve(): Actor
    {
        try {
            $user = $this->auth->guard()->user();
        } catch (Throwable) {
            $user = null;
        }

        if ($user instanceof Authenticatable) {
            return $this->fromUser($user);
        }

        return new Actor($this->runningInConsole ? 'console' : 'guest');
    }

    public function fromUser(Authenticatable $user): Actor
    {
        $identifier = $user->getAuthIdentifier();

        return new Actor(
            type: 'user',
            id: null === $identifier ? null : (string) $identifier,
            label: $this->label($user),
        );
    }

    private function label(Authenticatable $user): ?string
    {
        foreach (['name', 'email', 'title'] as $attribute) {
            try {
                $value = $user->{$attribute} ?? null;
            } catch (Throwable) {
                continue;
            }

            if (is_string($value) && '' !== $value) {
                return $value;
            }
        }

        return null;
    }
}
