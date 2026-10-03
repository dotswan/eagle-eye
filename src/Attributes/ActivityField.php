<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Attributes;

use Attribute;
use Dotswan\EagleEye\Enums\RedactionMode;

#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class ActivityField
{
    public function __construct(
        public ?string $label = null,
        public RedactionMode $redact = RedactionMode::Keep,
    ) {
    }
}
