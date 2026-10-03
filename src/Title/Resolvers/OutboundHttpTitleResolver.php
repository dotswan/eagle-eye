<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Title\Resolvers;

use Dotswan\EagleEye\Data\ActivityDraft;
use Dotswan\EagleEye\Enums\ActivityChannel;

final class OutboundHttpTitleResolver extends ChannelTitleResolver
{
    protected function channel(): ActivityChannel
    {
        return ActivityChannel::OutboundHttp;
    }

    protected function render(ActivityDraft $draft): string
    {
        $payload = $draft->payload;

        return $this->renderTemplate($draft, 'outbound_http', [
            'method' => $payload['method'] ?? 'CALL',
            'host'   => $payload['host'] ?? 'unknown host',
            'path'   => $payload['path'] ?? '',
            'status' => $payload['status'] ?? '?',
            'error'  => $payload['error'] ?? 'unknown error',
        ]);
    }
}
