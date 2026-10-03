<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Filter\Rules;

use Dotswan\EagleEye\Data\ActivityDraft;

final class EnvironmentRule extends StringListRule
{
    protected function values(ActivityDraft $draft): array
    {
        return [$draft->environment];
    }
}
