<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Title\Resolvers;

use Dotswan\EagleEye\Data\ActivityDraft;
use Dotswan\EagleEye\Enums\ActivityChannel;

final class AuthTitleResolver extends ChannelTitleResolver
{
    protected function channel(): ActivityChannel
    {
        return ActivityChannel::Auth;
    }

    protected function render(ActivityDraft $draft): string
    {
        $ip = $draft->request?->ip;

        return $this->renderTemplate($draft, 'auth', [
            'guard'  => $draft->payload['guard'] ?? 'unknown guard',
            'ip'     => $ip ?? 'unknown host',
            'action' => $draft->action,
        ]);
    }
}
