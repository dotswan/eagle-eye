<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Drivers;

use Closure;
use Dotswan\EagleEye\Contracts\Driver\ActivityLogDriverFactoryInterface;
use Dotswan\EagleEye\Contracts\Driver\ActivityLogDriverInterface;
use Illuminate\Support\Manager;
use InvalidArgumentException;
use Override;

final class DriverManager extends Manager implements ActivityLogDriverFactoryInterface
{
    #[Override]
    public function getDefaultDriver(): string
    {
        return (string) $this->config->get('eagle-eye.default', 'null');
    }

    #[Override]
    public function driver(mixed $name = null): ActivityLogDriverInterface
    {
        $driver = parent::driver($name);

        if (! $driver instanceof ActivityLogDriverInterface) {
            throw new InvalidArgumentException('Eagle Eye drivers must implement ActivityLogDriverInterface.');
        }

        return $driver;
    }

    #[Override]
    public function extend(mixed $driver, Closure $callback): static
    {
        parent::extend($driver, $callback);

        return $this;
    }

    protected function createNullDriver(): ActivityLogDriverInterface
    {
        return new NullDriver();
    }
}
