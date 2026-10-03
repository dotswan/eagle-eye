<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Filter\Rules;

use Dotswan\EagleEye\Data\ActivityDraft;

final class UrlRule extends StringListRule
{
    protected function values(ActivityDraft $draft): array
    {
        $url = $draft->request?->url;

        if (null === $url || '' === $url) {
            return [];
        }

        $path = parse_url($url, PHP_URL_PATH);
        $path = is_string($path) && '' !== $path ? $path : $url;

        return array_values(array_unique([$url, $path, mb_ltrim($path, '/')]));
    }
}
