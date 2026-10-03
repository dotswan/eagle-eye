<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Filter\Rules;

use Dotswan\EagleEye\Data\ActivityDraft;

final class MethodRule extends StringListRule
{
    protected function values(ActivityDraft $draft): array
    {
        $method = $draft->request?->method;

        return null === $method || '' === $method ? [] : [mb_strtoupper($method)];
    }
}
