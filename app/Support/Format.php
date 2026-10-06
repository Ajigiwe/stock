<?php

namespace App\Support;

/**
 * Ports of src/lib/format.ts. Ghana is UTC+0 all year, so dates are rendered
 * straight from the stored UTC values (same as the original, which rendered
 * ISO strings with local-time Date methods on a UTC machine).
 */
class Format
{
    /** "GHS 1,234.56" — matches formatMoney(). */
    public static function money(mixed $n): string
    {
        return 'GHS '.number_format((float) ($n ?? 0), 2, '.', ',');
    }

    /** Matches formatNumber(). */
    public static function number(mixed $n): string
    {
        return (string) (int) ($n ?? 0);
    }

    /** "14:30" today, otherwise "5 Oct 2026, 14:30" — matches formatDateTime(). */
    public static function dateTime(?string $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        $ts = strtotime($value);
        if ($ts === false) {
            return '—';
        }

        $time = date('H:i', $ts);

        return date('Y-m-d', $ts) === date('Y-m-d')
            ? $time
            : date('j M Y', $ts).', '.$time;
    }

    /** "5 Oct 2026" — matches the React date labels. */
    public static function date(?string $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        $ts = strtotime($value);

        return $ts === false ? '—' : date('j M Y', $ts);
    }

    /** Today's calendar day in UTC — matches todayISO(). */
    public static function today(): string
    {
        return gmdate('Y-m-d');
    }

    /** "2026-10-06" for a timestamp column. */
    public static function day(?string $value): string
    {
        $ts = $value ? strtotime($value) : false;

        return $ts === false ? self::today() : gmdate('Y-m-d', $ts);
    }
}
