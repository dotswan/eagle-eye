<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Resolution;

use Dotswan\EagleEye\Contracts\DeclaresActivityPrivacy;
use Dotswan\EagleEye\Enums\RedactionMode;
use WeakMap;

final class ActivityPrivacyDeclaration
{
    /** @var WeakMap<object, array<string, RedactionMode>> */
    private WeakMap $memo;

    public function __construct()
    {
        $this->memo = new WeakMap();
    }

    /** @return array<string, RedactionMode> */
    public function for(object $model): array
    {
        if (! $model instanceof DeclaresActivityPrivacy) {
            return [];
        }

        return $this->memo[$model] ??= $model->activityPrivacy();
    }
}
