<?php

declare(strict_types=1);

use Dotswan\EagleEye\Contracts\EagleEyeInterface;

if (! function_exists('eagleEye')) {
    function eagleEye(): EagleEyeInterface
    {
        return app(EagleEyeInterface::class);
    }
}

if (! function_exists('eagle_eye_tag')) {
    /** @param list<string> $tags */
    function eagle_eye_tag(array $tags, Closure $callback): mixed
    {
        return eagleEye()->withTags($tags, $callback);
    }
}

if (! function_exists('eagle_eye_silent')) {
    function eagle_eye_silent(Closure $callback): mixed
    {
        return eagleEye()->withoutLogging($callback);
    }
}
