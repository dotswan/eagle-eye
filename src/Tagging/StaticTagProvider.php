<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Tagging;

use Dotswan\EagleEye\Contracts\Tagging\TagProviderInterface;
use Dotswan\EagleEye\Data\ActivityDraft;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

final readonly class StaticTagProvider implements TagProviderInterface
{
    public function __construct(private ConfigRepository $config)
    {
    }

    public function tags(ActivityDraft $draft): array
    {
        $tags = $this->config->get('eagle-eye.tags.static', []);

        if (! is_array($tags)) {
            return [];
        }

        return array_values(array_filter($tags, is_string(...)));
    }
}
