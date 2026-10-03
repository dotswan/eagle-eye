<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Console;

use Illuminate\Console\Command;

abstract class EagleEyeCommand extends Command
{
    protected function stringArgument(string $name): string
    {
        $value = $this->argument($name);

        return is_string($value) || is_numeric($value) ? (string) $value : '';
    }

    protected function stringOption(string $name, string $default = ''): string
    {
        $value = $this->option($name);

        return is_string($value) || is_numeric($value) ? (string) $value : $default;
    }
}
