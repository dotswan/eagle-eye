<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Filter\Rules;

use Dotswan\EagleEye\Data\Actor;
use Dotswan\EagleEye\Data\ActivityDraft;

final class ActorRule extends StringListRule
{
    protected function values(ActivityDraft $draft): array
    {
        $actor = $draft->actor;

        if (!$actor instanceof Actor) {
            return [];
        }

        $values = [$actor->type, $actor->type.':'.($actor->id ?? '')];

        if (null !== $actor->id) {
            $values[] = $actor->id;
        }

        return $values;
    }
}
