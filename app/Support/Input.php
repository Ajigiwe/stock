<?php

namespace App\Support;

/**
 * Ports of the input-validation helpers at the top of src/lib/actions.ts.
 * Server Actions received raw JSON regardless of declared types; HTTP form
 * input is no different, so everything that reaches the database passes
 * through these first.
 */
class Input
{
    /** numeric(12,2) tops out at 10 digits before the decimal point. */
    public const MAX_MONEY = 9999999999.99;

    public const MAX_QTY = 1000000;

    /** Optional money field: blank/absent -> null. Returns [ok, value|error]. */
    public static function money(mixed $raw, string $label): array
    {
        if ($raw === null || $raw === '') {
            return [true, null];
        }

        if (! is_numeric($raw)) {
            return [false, "{$label} must be a number."];
        }

        $n = (float) $raw;
        if ($n < 0) {
            return [false, "{$label} can't be negative."];
        }
        if ($n > self::MAX_MONEY) {
            return [false, "{$label} is too large."];
        }

        return [true, round($n, 2)];
    }

    /** Required count with a default when blank. Returns [ok, value|error]. */
    public static function count(mixed $raw, string $label, int $fallback): array
    {
        if ($raw === null || $raw === '') {
            return [true, $fallback];
        }

        if (! is_numeric($raw) || (string) (int) $raw !== (string) $raw && ! preg_match('/^-?\d+$/', (string) $raw)) {
            return [false, "{$label} must be a whole number."];
        }

        $n = (int) $raw;
        if ($n < 0) {
            return [false, "{$label} can't be negative."];
        }
        if ($n > self::MAX_QTY) {
            return [false, "{$label} is too large."];
        }

        return [true, $n];
    }

    /** Returns [ok, value|error] where error defaults to the original wording. */
    public static function qty(mixed $raw, ?string $error = null): array
    {
        if (! is_numeric($raw) || (string) (int) $raw !== (string) $raw || (int) $raw <= 0) {
            return [false, $error ?? 'Quantity must be a whole number above 0.'];
        }

        if ((int) $raw > self::MAX_QTY) {
            return [false, 'Quantity is too large.'];
        }

        return [true, (int) $raw];
    }

    public static function isUuid(mixed $v): bool
    {
        return is_string($v)
            && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $v) === 1;
    }

    public static function trimmed(mixed $raw): string
    {
        return is_string($raw) ? trim($raw) : '';
    }

    /**
     * A YYYY-MM-DD transaction date anchored at midday UTC so the calendar day
     * is stable, bounded to the same window as parseTxDate(): 10 years back,
     * tomorrow at the latest. Returns [ok, 'Y-m-d H:i:s'|error].
     */
    public static function txDate(mixed $raw): array
    {
        if ($raw === null || $raw === '') {
            return [true, gmdate('Y-m-d H:i:s')];
        }

        if (! is_string($raw) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            return [false, 'Enter a valid date.'];
        }

        $ts = strtotime($raw.'T12:00:00Z');
        if ($ts === false) {
            return [false, 'Enter a valid date.'];
        }

        $now = time();
        if ($ts < $now - 10 * 365 * 24 * 60 * 60) {
            return [false, 'That date is too far in the past.'];
        }
        if ($ts > $now + 24 * 60 * 60) {
            return [false, "The date can't be in the future."];
        }

        return [true, gmdate('Y-m-d H:i:s', $ts)];
    }

    /**
     * Only same-origin, non-protocol-relative paths — port of safeNextPath().
     * "//evil.com" is protocol-relative and would leave the site.
     */
    public static function safeNextPath(mixed $raw): string
    {
        $next = is_string($raw) ? $raw : '';
        if (! str_starts_with($next, '/')) {
            return '/';
        }
        if (str_starts_with($next, '//') || str_starts_with($next, '/\\')) {
            return '/';
        }

        return $next;
    }

    public static function email(mixed $raw): bool
    {
        return is_string($raw) && preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/', $raw) === 1;
    }

    /**
     * Normalise a phone number for storage and login lookup: strip spaces,
     * dashes, brackets and dots, keep a leading `+`. Anything left must be
     * at least 7 digits (with an optional `+`); otherwise it is not a number
     * at all and comes back as ''.
     */
    public static function phone(mixed $raw): string
    {
        if (! is_string($raw)) {
            return '';
        }

        $cleaned = preg_replace('/[\s\-().]/', '', trim($raw)) ?? '';

        return preg_match('/^\+?\d{7,15}$/', $cleaned) === 1 ? $cleaned : '';
    }

    /**
     * Deterministic fallback idempotency key — port of derivedIdempotencyKey():
     * a UUID-shaped SHA-256 of the whole submission, so a client that omits its
     * key still dedupes in the DB instead of deducting stock twice.
     */
    public static function idempotencyKey(array $input): string
    {
        $outItems = array_map(
            fn ($i) => [(string) ($i['modelId'] ?? ''), (int) ($i['qty'] ?? 0)],
            $input['outItems'] ?? []
        );
        usort($outItems, fn ($a, $b) => strcmp($a[0], $b[0]));

        $swapIn = array_map(fn ($s) => mb_strtolower(trim((string) ($s['name'] ?? ''))), $input['swapIn'] ?? []);
        sort($swapIn);

        $canonical = json_encode([
            $input['shopId'] ?? '',
            $input['type'] ?? '',
            $input['paymentMethod'] ?? '',
            $input['amount'] ?? '',
            trim((string) ($input['discountReason'] ?? '')),
            trim((string) ($input['paymentReference'] ?? '')),
            $input['date'] ?? '',
            mb_strtolower(trim((string) ($input['customerName'] ?? ''))),
            (string) ($input['customerPhone'] ?? ''),
            $outItems,
            $swapIn,
        ], JSON_UNESCAPED_SLASHES);

        $hex = substr(hash('sha256', $canonical), 0, 32);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20),
        );
    }
}
