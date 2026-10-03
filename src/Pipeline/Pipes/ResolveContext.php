<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Pipeline\Pipes;

use Closure;
use Dotswan\EagleEye\Contracts\Pipeline\ActivityPipeInterface;
use Dotswan\EagleEye\Data\ActivityDraft;
use Illuminate\Contracts\Foundation\Application;

final readonly class ResolveContext implements ActivityPipeInterface
{
    public function __construct(private Application $app)
    {
    }

    public function handle(ActivityDraft $draft, Closure $next): ?ActivityDraft
    {
        $draft->environment = $this->app->environment();
        $draft->hostname = gethostname() ?: 'unknown';
        $draft->memoryKb ??= intdiv(memory_get_peak_usage(true), 1024);

        return $next($draft);
    }
}
