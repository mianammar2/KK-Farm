<?php

namespace App\Support;

/**
 * D-328: dates are digits only (D-277). Texts written before that (kept as written, e.g. the goat's append-only history)
 * can still say "since 24 Oct"; they are shown as "since 24/10".
 */
final class DigitDates
{
    private const MONTHS = ['Jan' => '01', 'Feb' => '02', 'Mar' => '03', 'Apr' => '04', 'May' => '05', 'Jun' => '06', 'Jul' => '07', 'Aug' => '08', 'Sep' => '09', 'Oct' => '10', 'Nov' => '11', 'Dec' => '12'];

    public static function text(?string $text): ?string
    {
        if ($text === null || $text === '') {
            return $text;
        }

        return preg_replace_callback('/\b([0-3]?\d) ('.implode('|', array_keys(self::MONTHS)).')\b/', fn ($m) => str_pad($m[1], 2, '0', STR_PAD_LEFT).'/'.self::MONTHS[$m[2]], $text);
    }
}
