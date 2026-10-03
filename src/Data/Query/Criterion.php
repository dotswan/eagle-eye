<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Data\Query;

final readonly class Criterion
{
    public function __construct(
        public string $field,
        public string $operator,
        public mixed $value,
    ) {
    }
}
