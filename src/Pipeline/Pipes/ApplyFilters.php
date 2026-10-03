<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Pipeline\Pipes;

use Closure;
use Dotswan\EagleEye\Contracts\Pipeline\ActivityPipeInterface;
use Dotswan\EagleEye\Data\ActivityDraft;
use Dotswan\EagleEye\Enums\FilterDecision;
use Dotswan\EagleEye\Filter\FilterChain;

final readonly class ApplyFilters implements ActivityPipeInterface
{
    public function __construct(private FilterChain $filters)
    {
    }

    public function handle(ActivityDraft $draft, Closure $next): ?ActivityDraft
    {
        if (FilterDecision::Deny === $this->filters->decide($draft)) {
            return null;
        }

        return $next($draft);
    }
}
