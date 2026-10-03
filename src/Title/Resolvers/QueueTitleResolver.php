<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Title\Resolvers;

use Dotswan\EagleEye\Data\ActivityDraft;
use Dotswan\EagleEye\Enums\ActivityChannel;

final class QueueTitleResolver extends ChannelTitleResolver
{
    protected function channel(): ActivityChannel
    {
        return ActivityChannel::Queue;
    }

    protected function render(ActivityDraft $draft): string
    {
        return $this->renderTemplate($draft, 'queue', [
            'job_short'  => $this->jobShort($draft),
            'connection' => $draft->payload['connection'] ?? 'unknown connection',
            'attempts'   => $draft->payload['attempts'] ?? '?',
            'error'      => $draft->payload['error'] ?? 'no error recorded',
            'duration'   => $this->durationLabel($draft),
        ]);
    }
}
