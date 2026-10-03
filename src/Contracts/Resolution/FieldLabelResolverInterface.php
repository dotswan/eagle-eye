<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Contracts\Resolution;

interface FieldLabelResolverInterface
{
    public function resolve(object|string $subject, string $field): string;
}
