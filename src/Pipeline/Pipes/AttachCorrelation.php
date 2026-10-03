<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Pipeline\Pipes;

use Closure;
use Dotswan\EagleEye\Contracts\Pipeline\ActivityPipeInterface;
use Dotswan\EagleEye\Correlation\TraceManager;
use Dotswan\EagleEye\Data\ActivityDraft;

final readonly class AttachCorrelation implements ActivityPipeInterface
{
    public function __construct(private TraceManager $traces)
    {
    }

    public function handle(ActivityDraft $draft, Closure $next): ?ActivityDraft
    {
        $draft->trace ??= $this->traces->next();

        return $next($draft);
    }
}
