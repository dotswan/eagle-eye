<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Pipeline\Pipes;

use Closure;
use Dotswan\EagleEye\Contracts\Pipeline\ActivityPipeInterface;
use Dotswan\EagleEye\Contracts\Resolution\PayloadNormalizerInterface;
use Dotswan\EagleEye\Data\ActivityDraft;

final readonly class NormalizePayload implements ActivityPipeInterface
{
    public function __construct(private PayloadNormalizerInterface $normalizer)
    {
    }

    public function handle(ActivityDraft $draft, Closure $next): ?ActivityDraft
    {
        $payload = $this->normalizer->normalize($draft->payload);
        $draft->payload = is_array($payload) ? $payload : [];

        return $next($draft);
    }
}
