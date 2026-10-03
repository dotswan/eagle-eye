<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Title\Resolvers;

use Dotswan\EagleEye\Data\ActivityDraft;
use Dotswan\EagleEye\Enums\ActivityChannel;

final class QueryTitleResolver extends ChannelTitleResolver
{
    protected function channel(): ActivityChannel
    {
        return ActivityChannel::Query;
    }

    protected function render(ActivityDraft $draft): string
    {
        $payload = $draft->payload;

        return $this->renderTemplate($draft, 'query', [
            'connection'  => $payload['connection'] ?? 'default',
            'duration_ms' => $payload['duration_ms'] ?? '?',
            'query_count' => $payload['query_count'] ?? '?',
            'duration'    => $this->durationLabel($draft),
        ]);
    }
}
