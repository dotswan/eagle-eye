<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Pipeline\Pipes;

use Closure;
use Dotswan\EagleEye\Contracts\Pipeline\ActivityPipeInterface;
use Dotswan\EagleEye\Contracts\Sampling\SamplerInterface;
use Dotswan\EagleEye\Data\ActivityDraft;

final readonly class SampleEntry implements ActivityPipeInterface
{
    public function __construct(private SamplerInterface $sampler)
    {
    }

    public function handle(ActivityDraft $draft, Closure $next): ?ActivityDraft
    {
        if (! $this->sampler->passes($draft)) {
            return null;
        }

        return $next($draft);
    }
}
