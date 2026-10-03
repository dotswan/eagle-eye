<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Tagging;

use Dotswan\EagleEye\Contracts\Tagging\TagProviderInterface;
use Dotswan\EagleEye\Data\ActivityDraft;
use Dotswan\EagleEye\Metadata\AttributeMetadataReader;

final readonly class AttributeTagProvider implements TagProviderInterface
{
    public function __construct(private AttributeMetadataReader $metadata)
    {
    }

    public function tags(ActivityDraft $draft): array
    {
        $class = $draft->subject?->className;

        if (null === $class || ! class_exists($class)) {
            return [];
        }

        return $this->metadata->read($class)->tags;
    }
}
