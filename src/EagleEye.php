<?php

declare(strict_types=1);

namespace Dotswan\EagleEye;

use Closure;
use Dotswan\EagleEye\Contracts\EagleEyeInterface;
use Dotswan\EagleEye\Contracts\RecorderInterface;
use Dotswan\EagleEye\Contracts\Repository\ActivityRepositoryInterface;
use Dotswan\EagleEye\Data\ActivityDraft;
use Dotswan\EagleEye\Data\ActivityEntry;
use Dotswan\EagleEye\Data\Query\ActivityQuery;
use Dotswan\EagleEye\Data\TagBag;
use Dotswan\EagleEye\Enums\ActivityChannel;
use Dotswan\EagleEye\Enums\ActivitySeverity;
use Dotswan\EagleEye\Repository\ActivityTrail;

final class EagleEye implements EagleEyeInterface
{
    private int $pauseDepth = 0;

    /** @var list<list<string>> */
    private array $tagStack = [];

    /** @param list<string> $staticTags */
    public function __construct(
        private readonly RecorderInterface $recorder,
        private readonly ActivityRepositoryInterface $repository,
        private readonly int $maxTags,
        private readonly array $staticTags,
    ) {
    }

    public function record(
        ActivityDraft|string $activity,
        array $payload = [],
        ActivityChannel $channel = ActivityChannel::Custom,
        ActivitySeverity $severity = ActivitySeverity::Info,
    ): ?ActivityEntry {
        if ($this->isPaused()) {
            return null;
        }

        $draft = is_string($activity)
            ? ActivityDraft::make($activity, $channel, $severity)->withPayload($payload)
            : $activity;

        $tags = new TagBag($draft->tags->all(), $this->maxTags);
        $tags->merge($this->staticTags);

        foreach ($this->tagStack as $scopedTags) {
            $tags->merge($scopedTags);
        }

        $draft->tags = $tags;

        return $this->recorder->record($draft);
    }

    public function query(?ActivityQuery $query = null): ActivityTrail
    {
        return new ActivityTrail($this->repository, $query ?? new ActivityQuery());
    }

    public function pause(): void
    {
        $this->pauseDepth++;
    }

    public function resume(): void
    {
        $this->pauseDepth = max(0, $this->pauseDepth - 1);
    }

    public function isPaused(): bool
    {
        return $this->pauseDepth > 0;
    }

    public function withTags(array $tags, Closure $callback): mixed
    {
        $this->tagStack[] = (new TagBag($tags, $this->maxTags))->all();

        try {
            return $callback();
        } finally {
            array_pop($this->tagStack);
        }
    }

    public function withoutLogging(Closure $callback): mixed
    {
        $this->pause();

        try {
            return $callback();
        } finally {
            $this->resume();
        }
    }
}
