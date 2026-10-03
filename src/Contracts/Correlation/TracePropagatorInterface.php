<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Contracts\Correlation;

use Dotswan\EagleEye\Data\TraceContext;

interface TracePropagatorInterface
{
    /** @param array<string, string> $carrier */
    public function extract(array $carrier): TraceContext;

    /**
     * @param array<string, string> $carrier
     * @return array<string, string>
     */
    public function inject(TraceContext $context, array $carrier): array;
}
