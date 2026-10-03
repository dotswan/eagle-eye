<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Contracts\Title;

use Dotswan\EagleEye\Data\ActivityDraft;

interface TitleResolverInterface
{
    public function resolve(ActivityDraft $draft): ?string;
}
