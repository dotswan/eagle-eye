<?php

declare(strict_types=1);

namespace Dotswan\EagleEye\Capture\Query;

class SqlFingerprint
{
    public const int MAX_SQL_BYTES = 20480;

    public const string TRUNCATED = '[truncated]';

    public function normalise(string $sql): string
    {
        if (mb_strlen($sql, '8bit') > self::MAX_SQL_BYTES) {
            return self::TRUNCATED;
        }

        $sql = preg_replace('/\s+/', ' ', $sql) ?? $sql;
        $sql = preg_replace("/'(?:[^'\\\\]|\\\\.)*'/", '?', $sql) ?? $sql;
        $sql = preg_replace('/"(?:[^"\\\\]|\\\\.)*"/', '?', $sql) ?? $sql;
        $sql = preg_replace('/\b\d+(?:\.\d+)?\b/', '?', $sql) ?? $sql;
        $sql = preg_replace('/\bin\s*\(\s*(?:\?\s*,\s*)*\?\s*\)/i', 'in (?)', $sql) ?? $sql;

        return mb_trim($sql);
    }
}
