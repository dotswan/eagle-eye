<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Metadata;

use Dotswan\EagleEye\Attributes\ActivityField;

final readonly class ActivityMetadata
{
    /**
     * @param list<string> $tags
     * @param array<string, ActivityField> $fields
     */
    public function __construct(
        public ?string $subjectType,
        public ?string $labelAttribute,
        public array $tags,
        public bool $doNotLog,
        public array $fields,
    ) {
    }
}
