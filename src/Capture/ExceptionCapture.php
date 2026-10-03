<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Capture;

use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Dotswan\EagleEye\Data\ActivityDraft;
use Dotswan\EagleEye\Enums\ActivityChannel;
use Dotswan\EagleEye\Enums\ActivitySeverity;
use Illuminate\Container\Container;
use Illuminate\Log\Events\MessageLogged;
use Throwable;

final class ExceptionCapture extends EventCapture
{
    protected function listeners(): array
    {
        return [
            MessageLogged::class => function (MessageLogged $event): void {
                $exception = $event->context['exception'] ?? null;

                if (! $exception instanceof Throwable) {
                    return;
                }

                $this->onException($exception, $this->severityFromLevel($event->level));
            },
        ];
    }

    private function onException(Throwable $exception, ActivitySeverity $severity): void
    {
        $key = $exception::class.'|'.$exception->getFile().'|'.$exception->getLine();

        if (Container::getInstance()->make(ExceptionDeduplicator::class)->isDuplicate($key)) {
            return;
        }

        $this->record(ActivityDraft::make('exception.reported', ActivityChannel::Exception, $severity)
            ->withPayload([
                'exception' => $exception::class,
                'message'   => $exception->getMessage(),
                'error'     => $exception->getMessage(),
                'file'      => $exception->getFile(),
                'line'      => $exception->getLine(),
            ]));

        $this->recordMailFailure($exception);
    }

    private function recordMailFailure(Throwable $exception): void
    {
        $transportContract = TransportExceptionInterface::class;

        if (! interface_exists($transportContract) || ! $exception instanceof $transportContract) {
            return;
        }

        $events = config('eagle-eye.capture.mail.events', ['sent', 'failed']);

        if (! is_array($events) || ! in_array('failed', $events, true)) {
            return;
        }

        $this->record(ActivityDraft::make('mail.failed', ActivityChannel::Mail, ActivitySeverity::Error)
            ->withPayload([
                'exception' => $exception::class,
                'error'     => $exception->getMessage(),
                'mailer'    => (string) config('mail.default', 'default'),
            ]));
    }

    private function severityFromLevel(string $level): ActivitySeverity
    {
        return match ($level) {
            'emergency' => ActivitySeverity::Emergency,
            'alert'     => ActivitySeverity::Alert,
            'critical'  => ActivitySeverity::Critical,
            'warning'   => ActivitySeverity::Warning,
            'notice'    => ActivitySeverity::Notice,
            'info'      => ActivitySeverity::Info,
            'debug'     => ActivitySeverity::Debug,
            default     => ActivitySeverity::Error,
        };
    }
}
