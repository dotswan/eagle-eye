<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Capture;

use Dotswan\EagleEye\Data\ActivityDraft;
use Dotswan\EagleEye\Enums\ActivityChannel;
use Dotswan\EagleEye\Enums\ActivitySeverity;
use Dotswan\EagleEye\Enums\RedactionMode;
use Dotswan\EagleEye\Capture\Concerns\ReadsCaptureConfig;
use Dotswan\EagleEye\Redaction\Strategies\PartialMaskStrategy;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

final class MailCapture extends EventCapture
{
    use ReadsCaptureConfig;

    protected function listeners(): array
    {
        $events = $this->captureEvents('mail', ['sent', 'failed']);
        $listeners = [];

        if (in_array('sending', $events, true) && class_exists(MessageSending::class)) {
            $listeners[MessageSending::class] = function (MessageSending $event): void {
                $draft = ActivityDraft::make('mail.sending', ActivityChannel::Mail, ActivitySeverity::Info)
                    ->withPayload($this->payload($event->message, $event->data));
                $this->keepFullRecipients($draft);
                $this->record($draft);
            };
        }

        if (in_array('sent', $events, true) && class_exists(MessageSent::class)) {
            $listeners[MessageSent::class] = function (MessageSent $event): void {
                $draft = ActivityDraft::make('mail.sent', ActivityChannel::Mail, ActivitySeverity::Info)
                    ->withPayload($this->payload($event->sent->getOriginalMessage(), $event->data, $event->sent->getMessageId()));
                $this->keepFullRecipients($draft);
                $this->record($draft);
            };
        }

        return $listeners;
    }

    private function keepFullRecipients(ActivityDraft $draft): void
    {
        if ('full' === $this->captureConfig('mail.store_recipients', 'masked')) {
            $draft->fieldModes['to'] = RedactionMode::Keep;
        }
    }

    /**
     * @param array<array-key, mixed> $data
     * @return array<string, mixed>
     */
    private function payload(mixed $message, array $data, ?string $messageId = null): array
    {
        if (! $message instanceof Email) {
            return [];
        }

        $to = $message->getTo();
        $payload = [
            'subject'   => $message->getSubject(),
            'to_count'  => count($to),
            'cc_count'  => count($message->getCc()),
            'bcc_count' => count($message->getBcc()),
        ];

        $domains = array_values(array_unique(array_filter(array_map(
            static function (Address $address): string {
                $at = mb_strrpos($address->getAddress(), '@');

                return false === $at ? '' : mb_substr($address->getAddress(), $at + 1);
            },
            $to,
        ))));
        $payload['to_domains'] = array_slice($domains, 0, 5);

        $recipients = $this->recipients(array_values($to));

        if ([] !== $recipients) {
            $payload['to'] = $recipients;
        }

        if (isset($data['mailer']) && is_scalar($data['mailer'])) {
            $payload['mailer'] = (string) $data['mailer'];
        }

        if (null !== $messageId && '' !== $messageId) {
            $payload['message_id'] = $messageId;
        }

        return $payload;
    }

    /**
     * @param list<Address> $to
     * @return list<string>
     */
    private function recipients(array $to): array
    {
        $mode = $this->captureConfig('mail.store_recipients', 'masked');
        $max = max(0, (int) $this->captureConfig('mail.max_recipients', 5));

        if (false === $mode || 0 === $max || (is_string($mode) && in_array(mb_strtolower($mode), ['false', '0', 'none', 'off'], true))) {
            return [];
        }

        $addresses = array_map(
            static fn (Address $address): string => $address->getAddress(),
            array_slice($to, 0, $max),
        );

        if ('full' === $mode) {
            return $addresses;
        }

        return array_map($this->mask(...), $addresses);
    }

    private function mask(string $address): string
    {
        $strategy = $this->container->make(PartialMaskStrategy::class);
        $masked = $strategy->apply($address, 'mail.recipient');

        return is_string($masked) ? $masked : '***';
    }
}
