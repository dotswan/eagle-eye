<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Resolution;

use Dotswan\EagleEye\Contracts\Resolution\FieldLabelResolverInterface;
use Dotswan\EagleEye\Metadata\AttributeMetadataReader;
use Illuminate\Support\Str;

final readonly class AttributeFieldLabelResolver implements FieldLabelResolverInterface
{
    public function __construct(private AttributeMetadataReader $metadata)
    {
    }

    public function resolve(object|string $subject, string $field): string
    {
        if (is_string($subject) && ! class_exists($subject)) {
            return Str::headline($field);
        }

        return $this->metadata->read($subject)->fields[$field]->label ?? Str::headline($field);
    }
}
