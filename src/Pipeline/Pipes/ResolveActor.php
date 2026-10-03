<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Pipeline\Pipes;

use Closure;
use Dotswan\EagleEye\Contracts\Pipeline\ActivityPipeInterface;
use Dotswan\EagleEye\Contracts\Resolution\ActorResolverInterface;
use Dotswan\EagleEye\Data\ActivityDraft;
use Dotswan\EagleEye\Data\Actor;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

final readonly class ResolveActor implements ActivityPipeInterface
{
    public function __construct(
        private ActorResolverInterface $actors,
        private ConfigRepository $config,
    ) {
    }

    public function handle(ActivityDraft $draft, Closure $next): ?ActivityDraft
    {
        $draft->actor ??= $this->actors->resolve();

        $actor = $draft->actor;

        if ($actor instanceof Actor
            && null !== $actor->label
            && 'keep' !== $this->config->get('eagle-eye.privacy.actor_label', 'keep')) {
            $draft->actor = new Actor($actor->type, $actor->id, null, $actor->url);
        }

        return $next($draft);
    }
}
