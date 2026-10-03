<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Redaction;

final class IpTruncator
{
    public static function truncate(string $ip, int $ipv4Bits = 24, int $ipv6Bits = 48): ?string
    {
        $packed = inet_pton($ip);

        if (false === $packed) {
            return null;
        }

        $isV4 = 4 === mb_strlen($packed, '8bit');
        $bits = max(0, min($isV4 ? $ipv4Bits : $ipv6Bits, $isV4 ? 32 : 128));

        $bytes = intdiv($bits, 8);
        $remainder = $bits % 8;
        $result = mb_substr($packed, 0, $bytes, '8bit');

        if ($remainder > 0 && mb_strlen($packed, '8bit') > $bytes) {
            $byte = ord($packed[$bytes]) & (0xFF << (8 - $remainder) & 0xFF);
            $result .= chr($byte);
            $bytes++;
        }

        $result .= str_repeat("\0", mb_strlen($packed, '8bit') - $bytes);

        $rendered = inet_ntop($result);

        return false === $rendered ? null : $rendered;
    }
}
