<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Title\Resolvers;

use Dotswan\EagleEye\Data\ActivityDraft;
use Dotswan\EagleEye\Enums\ActivityChannel;

final class NotificationTitleResolver extends ChannelTitleResolver
{
    protected function channel(): ActivityChannel
    {
        return ActivityChannel::Notification;
    }

    protected function render(ActivityDraft $draft): string
    {
        return $this->renderTemplate($draft, 'notification', [
            'notification_short' => $this->notificationShort($draft),
            'channel'            => $draft->payload['channel'] ?? 'unknown channel',
            'subject_label'      => $this->subjectLabel($draft),
        ]);
    }
}
