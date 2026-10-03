<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Pipeline\Pipes;

use Closure;
use Dotswan\EagleEye\Contracts\Pipeline\ActivityPipeInterface;
use Dotswan\EagleEye\Contracts\Title\TitleResolverInterface;
use Dotswan\EagleEye\Data\ActivityDraft;

final readonly class ResolveTitle implements ActivityPipeInterface
{
    public function __construct(private TitleResolverInterface $titles)
    {
    }

    public function handle(ActivityDraft $draft, Closure $next): ?ActivityDraft
    {
        $title = $this->titles->resolve($draft);

        if (null !== $title && '' !== $title) {
            $draft->title = $title;
        }

        return $next($draft);
    }
}
