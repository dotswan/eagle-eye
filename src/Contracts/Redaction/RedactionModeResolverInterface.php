<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Contracts\Redaction;

use Dotswan\EagleEye\Enums\RedactionMode;

interface RedactionModeResolverInterface
{
    /** @return array<string, RedactionMode> */
    public function fieldModes(?string $subjectClass): array;

    /** @param array<string, RedactionMode> $fieldModes */
    public function modeForKey(string $key, array $fieldModes = []): ?RedactionMode;
}
