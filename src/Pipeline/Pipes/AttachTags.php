<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Pipeline\Pipes;

use Closure;
use Dotswan\EagleEye\Contracts\Pipeline\ActivityPipeInterface;
use Dotswan\EagleEye\Data\ActivityDraft;
use Dotswan\EagleEye\Data\TagBag;
use Dotswan\EagleEye\Tagging\AttributeTagProvider;
use Dotswan\EagleEye\Tagging\AutomaticTagProvider;
use Dotswan\EagleEye\Tagging\StaticTagProvider;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

final readonly class AttachTags implements ActivityPipeInterface
{
    public function __construct(
        private StaticTagProvider $staticTags,
        private AutomaticTagProvider $automaticTags,
        private AttributeTagProvider $attributeTags,
        private ConfigRepository $config,
    ) {
    }

    public function handle(ActivityDraft $draft, Closure $next): ?ActivityDraft
    {
        $max = max(1, min((int) $this->config->get('eagle-eye.tags.max', 24), 100));

        $tags = [
            ...$draft->tags->all(),
            ...$this->staticTags->tags($draft),
            ...$this->automaticTags->tags($draft),
            ...$this->attributeTags->tags($draft),
        ];

        $draft->tags = new TagBag($tags, $max);

        return $next($draft);
    }
}
