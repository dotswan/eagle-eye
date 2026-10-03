<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Contracts\Privacy;

use Dotswan\EagleEye\Data\ErasureResult;

interface SubjectEraserInterface
{
    public function erase(string $subjectType, string $subjectId): ErasureResult;
}
