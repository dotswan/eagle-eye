<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Contracts;

interface DescribesActivity
{
    public function activityLogLabel(): string;
}
