<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Capture\Query;

final class Frame
{
    public int $count = 0;

    public float $totalMs = 0.0;

    public bool $truncated = false;

    public int $nestedCount = 0;

    public float $nestedMs = 0.0;

    /** @var array<string, array{sql: string, count: int, total_ms: float, max_ms: float}> */
    public array $fingerprints = [];

    /** @var array<string, string> */
    public array $memo = [];
    /** @var list<array{sql: string, ms: float}> */
    public array $slow = [];

    public function __construct(public readonly string $key)
    {
    }
}
