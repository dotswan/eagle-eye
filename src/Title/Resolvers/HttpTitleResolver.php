<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Title\Resolvers;

use Dotswan\EagleEye\Data\ActivityDraft;
use Dotswan\EagleEye\Enums\ActivityChannel;

final class HttpTitleResolver extends ChannelTitleResolver
{
    protected function channel(): ActivityChannel
    {
        return ActivityChannel::Http;
    }

    protected function render(ActivityDraft $draft): string
    {
        $request = $draft->request;
        $method = $request?->method;
        $route = $request?->route;
        $route ??= $request?->url;
        $status = $request?->statusCode;

        return $this->renderTemplate($draft, 'http', [
            'actor'  => $this->actorLabel($draft),
            'action' => $draft->action,
            'method' => $method ?? '-',
            'route'  => $route ?? '-',
            'status' => $status ?? '-',
        ]);
    }
}
