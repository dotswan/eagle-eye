<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Pipeline\Pipes;

use Closure;
use Dotswan\EagleEye\Contracts\Pipeline\ActivityPipeInterface;
use Dotswan\EagleEye\Data\ActivityDraft;
use Dotswan\EagleEye\Metadata\AttributeMetadataReader;

final readonly class ReadSubjectMetadata implements ActivityPipeInterface
{
    public function __construct(private AttributeMetadataReader $metadata)
    {
    }

    public function handle(ActivityDraft $draft, Closure $next): ?ActivityDraft
    {
        $subjectClass = $draft->subject?->className;

        if (null !== $subjectClass && class_exists($subjectClass)) {
            if ($this->metadata->read($subjectClass)->doNotLog) {
                return null;
            }
        }

        return $next($draft);
    }
}
