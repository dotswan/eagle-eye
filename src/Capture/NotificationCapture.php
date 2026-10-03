<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Capture;

use Dotswan\EagleEye\Capture\Concerns\ReadsCaptureConfig;
use Dotswan\EagleEye\Contracts\Resolution\SubjectResolverInterface;
use Dotswan\EagleEye\Data\ActivityDraft;
use Dotswan\EagleEye\Enums\ActivityChannel;
use Dotswan\EagleEye\Enums\ActivitySeverity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Events\NotificationSent;

final class NotificationCapture extends EventCapture
{
    use ReadsCaptureConfig;

    protected function listeners(): array
    {
        $events = $this->captureEvents('notification', ['sent', 'failed']);
        $listeners = [];

        if (in_array('sending', $events, true) && class_exists(NotificationSending::class)) {
            $listeners[NotificationSending::class] = fn (NotificationSending $event) => $this->onEvent(
                'notification.sending',
                ActivitySeverity::Info,
                $event->notification,
                $event->notifiable,
                $event->channel,
            );
        }

        if (in_array('sent', $events, true) && class_exists(NotificationSent::class)) {
            $listeners[NotificationSent::class] = fn (NotificationSent $event) => $this->onEvent(
                'notification.sent',
                ActivitySeverity::Info,
                $event->notification,
                $event->notifiable,
                $event->channel,
            );
        }

        if (in_array('failed', $events, true) && class_exists(NotificationFailed::class)) {
            $listeners[NotificationFailed::class] = fn (NotificationFailed $event) => $this->onEvent(
                'notification.failed',
                ActivitySeverity::Warning,
                $event->notification,
                $event->notifiable,
                $event->channel,
                $event->data,
            );
        }

        return $listeners;
    }

    /** @param array<array-key, mixed> $data */
    private function onEvent(string $action, ActivitySeverity $severity, object $notification, mixed $notifiable, string $channel, array $data = []): void
    {
        $payload = [
            'notification'       => $notification::class,
            'notification_short' => class_basename($notification),
            'channel'            => $channel,
        ];

        $error = $data['message'] ?? $data['error'] ?? $data['exception'] ?? null;

        if (is_scalar($error) && '' !== (string) $error) {
            $payload['error'] = (string) $error;
        }

        $draft = ActivityDraft::make($action, ActivityChannel::Notification, $severity)->withPayload($payload);

        if ($notifiable instanceof Model) {
            $draft->withSubject($this->container->make(SubjectResolverInterface::class)->resolve($notifiable));
        }

        $this->record($draft);
    }
}
