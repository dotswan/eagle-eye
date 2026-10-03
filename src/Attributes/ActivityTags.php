<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class ActivityTags
{
    /** @var list<string> */
    public array $tags;

    public function __construct(string ...$tags)
    {
        $this->tags = array_values($tags);
    }
}
