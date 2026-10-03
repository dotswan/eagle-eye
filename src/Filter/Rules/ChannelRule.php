<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Filter\Rules;

use Dotswan\EagleEye\Data\ActivityDraft;

final class ChannelRule extends StringListRule
{
    protected function values(ActivityDraft $draft): array
    {
        return [$draft->channel->value];
    }
}
