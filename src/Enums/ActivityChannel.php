<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Enums;

enum ActivityChannel: string
{
    case Http = 'http';
    case Model = 'model';
    case Auth = 'auth';
    case Queue = 'queue';
    case Console = 'console';
    case Mail = 'mail';
    case Notification = 'notification';
    case OutboundHttp = 'outbound_http';
    case Exception = 'exception';
    case Log = 'log';
    case Schedule = 'schedule';
    case Query = 'query';
    case Custom = 'custom';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $channel): string => $channel->value, self::cases());
    }
}
