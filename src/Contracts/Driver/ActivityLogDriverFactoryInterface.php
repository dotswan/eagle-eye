<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Contracts\Driver;

use Closure;

interface ActivityLogDriverFactoryInterface
{
    public function driver(mixed $name = null): ActivityLogDriverInterface;

    public function extend(string $driver, Closure $callback): static;
}
