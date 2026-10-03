<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Contracts\Capture;

interface CaptureAdapterInterface
{
    public function register(): void;
}
