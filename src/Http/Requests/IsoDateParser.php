<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Http\Requests;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/** @internal */
final class IsoDateParser
{
    public static function parse(string $value, string $calendarTimezone = 'UTC'): ?DateTimeImmutable
    {
        if (1 === preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $date)) {
            if (! checkdate((int) $date[2], (int) $date[3], (int) $date[1])) {
                return null;
            }

            try {
                return new DateTimeImmutable($value, new DateTimeZone($calendarTimezone));
            } catch (Throwable) {
                return null;
            }
        }

        if (1 !== preg_match('/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(?:\.\d{1,6})?(Z|[+-](\d{2}):(\d{2}))$/', $value, $date)) {
            return null;
        }

        $validOffset = 'Z' === $date[7] || (isset($date[8], $date[9])
            && (int) $date[9] <= 59
            && ((int) $date[8] < 14 || (14 === (int) $date[8] && 0 === (int) $date[9])));

        if (! checkdate((int) $date[2], (int) $date[3], (int) $date[1])
            || (int) $date[4] > 23
            || (int) $date[5] > 59
            || (int) $date[6] > 59
            || ! $validOffset) {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Throwable) {
            return null;
        }
    }
}
