<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Contracts\Pipeline;

use Dotswan\EagleEye\Data\ActivityDraft;

interface DraftEnricherInterface
{
    public function enrich(ActivityDraft $draft): void;
}
