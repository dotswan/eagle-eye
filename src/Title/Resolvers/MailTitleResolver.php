<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Title\Resolvers;

use Dotswan\EagleEye\Data\ActivityDraft;
use Dotswan\EagleEye\Enums\ActivityChannel;

final class MailTitleResolver extends ChannelTitleResolver
{
    protected function channel(): ActivityChannel
    {
        return ActivityChannel::Mail;
    }

    protected function render(ActivityDraft $draft): string
    {
        $payload = $draft->payload;

        return $this->renderTemplate($draft, 'mail', [
            'subject'         => $payload['subject'] ?? '(no subject)',
            'recipient_count' => $this->recipientCount($payload),
            'error'           => $payload['error'] ?? 'unknown error',
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function recipientCount(array $payload): int
    {
        if (isset($payload['to_count']) && is_numeric($payload['to_count'])) {
            return (int) $payload['to_count'];
        }

        return is_array($payload['to'] ?? null) ? count($payload['to']) : 0;
    }
}
