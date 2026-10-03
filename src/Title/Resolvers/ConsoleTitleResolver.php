<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Title\Resolvers;

use Dotswan\EagleEye\Data\ActivityDraft;
use Dotswan\EagleEye\Enums\ActivityChannel;

final class ConsoleTitleResolver extends ChannelTitleResolver
{
    protected function channel(): ActivityChannel
    {
        return ActivityChannel::Console;
    }

    protected function render(ActivityDraft $draft): string
    {
        return $this->renderTemplate($draft, 'console', [
            'command'   => $draft->payload['command'] ?? 'unknown command',
            'exit_code' => $draft->payload['exit_code'] ?? '?',
            'duration'  => $this->durationLabel($draft),
        ]);
    }
}
