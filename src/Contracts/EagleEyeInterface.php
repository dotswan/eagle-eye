<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Contracts;

use Closure;
use Dotswan\EagleEye\Data\ActivityDraft;
use Dotswan\EagleEye\Data\ActivityEntry;
use Dotswan\EagleEye\Data\Query\ActivityQuery;
use Dotswan\EagleEye\Enums\ActivityChannel;
use Dotswan\EagleEye\Enums\ActivitySeverity;
use Dotswan\EagleEye\Repository\ActivityTrail;

interface EagleEyeInterface
{
    /** @param array<string, mixed> $payload */
    public function record(
        ActivityDraft|string $activity,
        array $payload = [],
        ActivityChannel $channel = ActivityChannel::Custom,
        ActivitySeverity $severity = ActivitySeverity::Info,
    ): ?ActivityEntry;

    public function query(?ActivityQuery $query = null): ActivityTrail;

    public function pause(): void;

    public function resume(): void;

    public function isPaused(): bool;

    /** @param list<string> $tags */
    public function withTags(array $tags, Closure $callback): mixed;

    public function withoutLogging(Closure $callback): mixed;
}
