<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Contracts\Tagging;

use Dotswan\EagleEye\Data\ActivityDraft;

interface TagProviderInterface
{
    /** @return list<string> */
    public function tags(ActivityDraft $draft): array;
}
