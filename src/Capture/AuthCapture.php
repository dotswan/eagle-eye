<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Capture;

use Dotswan\EagleEye\Contracts\Capture\CaptureAdapterInterface;
use Dotswan\EagleEye\Contracts\EagleEyeInterface;
use Dotswan\EagleEye\Contracts\Resolution\ActorResolverInterface;
use Dotswan\EagleEye\Data\ActivityDraft;
use Dotswan\EagleEye\Data\RequestSnapshot;
use Dotswan\EagleEye\Enums\ActivityChannel;
use Dotswan\EagleEye\Enums\ActivitySeverity;
use Dotswan\EagleEye\Resolution\AuthActorResolver;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\OtherDeviceLogout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Container\Container as ApplicationContainer;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Throwable;

final readonly class AuthCapture implements CaptureAdapterInterface
{
    private const array EVENTS = [
        Login::class             => ['auth.login', 'notice'],
        Failed::class            => ['auth.failed', 'notice'],
        Registered::class        => ['auth.registered', 'info'],
        Logout::class            => ['auth.logout', 'info'],
        PasswordReset::class     => ['auth.password_reset', 'notice'],
        Verified::class          => ['auth.verified', 'info'],
        Lockout::class           => ['auth.lockout', 'warning'],
        OtherDeviceLogout::class => ['auth.other_device_logout', 'info'],
    ];

    public function __construct(
        private Dispatcher $events,
    ) {
    }

    public function register(): void
    {
        foreach (self::EVENTS as $class => $definition) {
            if (! class_exists($class)) {
                continue;
            }

            $this->events->listen($class, function (object $event) use ($definition): void {
                $container = ApplicationContainer::getInstance();

                if ($container->make(CaptureSuppression::class)->active()) {
                    return;
                }

                try {
                    $this->handle($container, $event, $definition);
                } catch (Throwable $exception) {
                    try {
                        $container->make(CaptureSuppression::class)->run(static fn () => report($exception));
                    } catch (Throwable) {
                    }
                }
            });
        }
    }

    /** @param array{0: string, 1: string} $definition */
    private function handle(Container $container, object $event, array $definition): void
    {
        [$action, $severity] = $definition;
        $user = $event->user ?? null;

        $actor = $user instanceof Authenticatable
            ? $container->make(AuthActorResolver::class)->fromUser($user)
            : $container->make(ActorResolverInterface::class)->resolve();

        $guard = property_exists($event, 'guard') && is_string($event->guard) ? $event->guard : null;

        $draft = ActivityDraft::make($action, ActivityChannel::Auth, ActivitySeverity::from($severity))
            ->withActor($actor);

        if (null !== $guard) {
            $draft->payload['guard'] = $guard;
        }

        $request = $container->bound('request') ? $container->make('request') : null;

        if ($request instanceof Request && '' !== ($ip = (string) $request->ip())) {
            $draft->request = new RequestSnapshot(ip: $ip);
        }

        $container->make(EagleEyeInterface::class)->record($draft);
    }
}
