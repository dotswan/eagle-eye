<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Title\Resolvers;

use Dotswan\EagleEye\Data\ActivityDraft;
use Dotswan\EagleEye\Enums\ActivityChannel;

final class ExceptionTitleResolver extends ChannelTitleResolver
{
    protected function channel(): ActivityChannel
    {
        return ActivityChannel::Exception;
    }

    protected function render(ActivityDraft $draft): string
    {
        return $this->renderTemplate($draft, 'exception', [
            'actor'  => $this->actorLabel($draft),
            'action' => $draft->action,
            'error'  => $draft->payload['error'] ?? $draft->action,
        ]);
    }
}
