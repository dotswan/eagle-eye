<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Contracts\Pipeline;

use Closure;
use Dotswan\EagleEye\Data\ActivityDraft;

interface ActivityPipeInterface
{
    /** @param Closure(ActivityDraft): ?ActivityDraft $next */
    public function handle(ActivityDraft $draft, Closure $next): ?ActivityDraft;
}
