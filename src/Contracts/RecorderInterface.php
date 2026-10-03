<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Contracts;

use Dotswan\EagleEye\Data\ActivityDraft;
use Dotswan\EagleEye\Data\ActivityEntry;

interface RecorderInterface
{
    public function record(ActivityDraft $draft): ?ActivityEntry;
}
