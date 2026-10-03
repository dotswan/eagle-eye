<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Capture\Query;

use Dotswan\EagleEye\Contracts\Pipeline\DraftEnricherInterface;
use Dotswan\EagleEye\Data\ActivityDraft;
use Dotswan\EagleEye\Enums\ActivityChannel;
use Illuminate\Contracts\Container\Container;

final readonly class QuerySummaryEnricher implements DraftEnricherInterface
{
    public function __construct(private Container $container)
    {
    }

    public function enrich(ActivityDraft $draft): void
    {
        $key = $this->frameKey($draft);

        if (null === $key) {
            return;
        }

        $summary = $this->container->make(QuerySummaryCollector::class)->peek($key);

        if (null !== $summary) {
            $draft->payload['queries'] = $summary;
        }
    }

    private function frameKey(ActivityDraft $draft): ?string
    {
        if (null !== $draft->queryFrameKey) {
            return $draft->queryFrameKey;
        }

        return ActivityChannel::Http === $draft->channel && 'http.request' === $draft->action
            ? QuerySummaryCollector::ROOT
            : null;
    }
}
