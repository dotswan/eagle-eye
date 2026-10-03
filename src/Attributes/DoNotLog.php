<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class DoNotLog
{
}
