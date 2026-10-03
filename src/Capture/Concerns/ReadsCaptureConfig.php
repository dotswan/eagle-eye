<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Capture\Concerns;

trait ReadsCaptureConfig
{
    protected function captureConfig(string $key, mixed $default = null): mixed
    {
        return config('eagle-eye.capture.'.$key, $default);
    }

    /**
     * @param  list<string>  $default
     * @return list<string>
     */
    protected function captureEvents(string $key, array $default): array
    {
        $events = $this->captureConfig($key.'.events', $default);

        if (! is_array($events)) {
            return $default;
        }

        return array_values(array_filter($events, is_string(...)));
    }
}
