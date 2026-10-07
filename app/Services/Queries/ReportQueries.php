<?php

namespace App\Services\Queries;

use App\Models\User;
use App\Support\DataCache;
use App\Support\Format;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Port of the reports page (src/app/(app)/reports/page.tsx) and the CSV route
 * (src/app/reports/export/route.ts).
 *
 * Filters come from the query string (`shop`, `from`, `to`, `type`, `payment`,
 * `status`) because the contract pins these methods to a single `User`
 * argument. Each surface validates exactly as its original did:
 *
 *  - `index()` echoes the raw query string (the page read `searchParams`
 *    unvalidated) and sends `from`/`to`/`type`/`payment` through as given —
 *    only `status` was gated on the select's option list. MariaDB answers an
 *    unknown ENUM member with zero rows and coerces an unparsable day instead
 *    of failing the query the way Postgres did, so a malformed filter is never
 *    more permissive than an absent one and never 500s the page.
 *  - `export()` applies the route's own allow-lists: `isDate()` on the dates
 *    and the fixed enum lists, dropping anything else (the route's comment
 *    says exactly why: unknown values used to 500 the query).
 *
 * Visibility mirrors the original: the owner is filtered by the requested
 * shop (or all shops), an attendant is pinned to `$actor->shop_id` and can
 * never widen it with `?shop=`.
 */
final class ReportQueries
{
    private const TX_TYPES = ['sale', 'swap', 'repair'];

    private const PAYMENT_METHODS = ['cash', 'mobile_money', 'card', 'bank_transfer', 'other'];

    /** Statuses the page's select offers on top of the `completed` default. */
    private const STATUSES = ['all', 'pending_review', 'voided', 'rejected'];

    /** Port of MAX_ROWS in export/route.ts. */
    private const EXPORT_MAX_ROWS = 5000;

    private function __construct()
    {
    }

    /**
     * @return array{
     *     is_owner: bool,
     *     shops: list<array<string, mixed>>,
     *     filters: array<string, string|null>,
     *     transactions: list<array<string, mixed>>,
     *     revenue: float,
     *     sales: int,
     *     swaps: int,
     *     repairs: int,
     *     payment_breakdown: array<string, float>
     * }
     */
    public static function index(User $actor): array
    {
        $filters = self::filters();

        if ($actor->isAdmin()) {
            $key = self::key('reports:owner', $filters);

            return DataCache::remember($key, fn (): array => self::indexBody($filters, true));
        }

        // Attendants have nothing to report on without a shop — the original
        // page redirected them instead of falling through to an unscoped read.
        $shopId = $actor->shop_id;
        if ($shopId === null) {
            return self::emptyBody($filters);
        }

        $filters['shop'] = $shopId;
        $key = self::key("reports:attendant:{$shopId}", $filters);

        return DataCache::remember($key, fn (): array => self::indexBody($filters, false));
    }

    /**
     * Port of GET /reports/export. Returns the route's column order and row
     * set as raw cell values; the controller turns them into CSV with the
     * original `esc()` helper (formula-prefix quoting plus `"` doubling).
     *
     * @return array{headers: list<string>, rows: list<list<string|float|null>>}
     */
    public static function export(User $actor): array
    {
        $filters = self::filters();
        unset($filters['status']);

        if ($actor->isAdmin()) {
            $key = self::key('export:owner', $filters);

            return DataCache::remember($key, fn (): array => self::exportBody($filters));
        }

        $shopId = $actor->shop_id;
        if ($shopId === null) {
            // The original route answered 403 here ("Forbidden").
            throw new AccessDeniedHttpException;
        }

        $filters['shop'] = $shopId;
        $key = self::key("export:attendant:{$shopId}", $filters);

        return DataCache::remember($key, fn (): array => self::exportBody($filters));
    }

    /**
     * Raw query-string filters. The page read `searchParams` and passed the
     * values straight through (the `<input type="date">` and `<select>` elements
     * ignore anything they cannot render), so the same raw strings are echoed
     * back for the filter form's defaults and sent to the index query.
     *
     * @return array{shop: string|null, from: string|null, to: string|null, type: string|null, payment: string|null, status: string|null}
     */
    private static function filters(): array
    {
        $input = request();
        $filters = [];
        foreach (['shop', 'from', 'to', 'type', 'payment', 'status'] as $key) {
            $value = $input->query($key);
            $filters[$key] = is_string($value) && $value !== '' ? $value : null;
        }

        return $filters;
    }

    /**
     * @param  array<string, string|null>  $filters
     * @return array<string, mixed>
     */
    private static function indexBody(array $filters, bool $isOwner): array
    {
        $txs = QuerySupport::transactions([
            // The page handed these four to Postgres untouched; only `status`
            // was checked against the select's option list before use, so an
            // unknown status falls back to `completed` below.
            'shopId' => $filters['shop'],
            'from' => $filters['from'],
            'to' => $filters['to'],
            'type' => $filters['type'],
            'paymentMethod' => $filters['payment'],
            'status' => in_array($filters['status'], self::STATUSES, true) ? $filters['status'] : null,
        ]);

        $revenue = 0.0;
        $sales = 0;
        $swaps = 0;
        $repairs = 0;
        $byPayment = [];
        foreach ($txs as $tx) {
            $revenue += $tx['amount'];
            if ($tx['type'] === 'sale') {
                $sales++;
            } elseif ($tx['type'] === 'swap') {
                $swaps++;
            } elseif ($tx['type'] === 'repair') {
                $repairs++;
            }
            $method = $tx['payment_method'];
            $byPayment[$method] = ($byPayment[$method] ?? 0.0) + $tx['amount'];
        }

        return [
            'is_owner' => $isOwner,
            'shops' => $isOwner ? QuerySupport::shops() : [],
            'filters' => $filters,
            'transactions' => $txs,
            'revenue' => $revenue,
            'sales' => $sales,
            'swaps' => $swaps,
            'repairs' => $repairs,
            'payment_breakdown' => $byPayment,
        ];
    }

    /**
     * Fail-closed payload for an attendant with no shop.
     *
     * @param  array<string, string|null>  $filters
     * @return array<string, mixed>
     */
    private static function emptyBody(array $filters): array
    {
        $filters['shop'] = null;

        return [
            'is_owner' => false,
            'shops' => [],
            'filters' => $filters,
            'transactions' => [],
            'revenue' => 0.0,
            'sales' => 0,
            'swaps' => 0,
            'repairs' => 0,
            'payment_breakdown' => [],
        ];
    }

    /**
     * Port of the CSV route's row builder: every status, newest first, capped
     * at 5000 rows, with the same ten columns in the same order. The route's
     * own input rules apply here — `isDate()` on the dates, the fixed enum
     * lists on type/payment, no status parameter at all.
     *
     * The controller turns the cells into CSV: `esc()` each cell (quote the
     * formula-leading `=`, `+`, `-`, `@`, tab or CR, double embedded quotes),
     * join with `,` and `\n`, prepend the UTF-8 BOM and answer with filename
     * `report-{from}-{to}.csv`, where from/to are the validated dates above or
     * `all` when they were dropped. The amount cell is a float — cast it with
     * `(string)` (no separators, no decimals forced) to match the original's
     * `String(t.amount ?? 0)`.
     *
     * @param  array<string, string|null>  $filters
     * @return array{headers: list<string>, rows: list<list<string|float|null>>}
     */
    private static function exportBody(array $filters): array
    {
        $from = QuerySupport::isDate($filters['from']) ? $filters['from'] : null;
        $to = QuerySupport::isDate($filters['to']) ? $filters['to'] : null;
        $type = is_string($filters['type']) && in_array($filters['type'], self::TX_TYPES, true) ? $filters['type'] : null;
        $payment = is_string($filters['payment']) && in_array($filters['payment'], self::PAYMENT_METHODS, true) ? $filters['payment'] : null;

        $txs = QuerySupport::transactions([
            'shopId' => $filters['shop'],
            'from' => $from,
            'to' => $to,
            'type' => $type,
            'paymentMethod' => $payment,
            'status' => 'all',
            'limit' => self::EXPORT_MAX_ROWS,
        ]);

        $headers = [
            'date',
            'shop',
            'staff',
            'type',
            'customer',
            'customer_phone',
            'payment_method',
            'amount_ghs',
            'items_out',
            'items_in',
        ];

        $rows = [];
        foreach ($txs as $tx) {
            $out = [];
            $in = [];
            foreach ($tx['items'] as $item) {
                $line = $item['qty'].' x '.$item['model_name'].' ('.$item['condition'].')';
                if ($item['direction'] === 'out') {
                    $out[] = $line;
                } else {
                    $in[] = $line;
                }
            }

            $rows[] = [
                self::isoDate($tx['date']),
                $tx['shop_name'] ?? '',
                $tx['staff_name'] ?? '',
                $tx['type'],
                $tx['customer_name'] ?? '',
                $tx['customer_phone'] ?? '',
                $tx['payment_method'],
                $tx['amount'],
                implode('; ', $out),
                implode('; ', $in),
            ];
        }

        return ['headers' => $headers, 'rows' => $rows];
    }

    /**
     * PostgREST printed the `timestamptz` cell as RFC 3339 UTC
     * (`2026-10-06T11:30:00+00:00`); MariaDB returns `2026-10-06 11:30:00`, so
     * re-attach the offset the original CSV carried. The column stores UTC
     * (`app.timezone` and the server time zone are both UTC), and anything that
     * is not a plain `Y-m-d H:i:s` timestamp is passed through untouched.
     */
    private static function isoDate(string $value): string
    {
        if (preg_match('/^(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2}:\d{2})$/', $value, $parts) === 1) {
            return $parts[1].'T'.$parts[2].'+00:00';
        }

        return $value;
    }

    /**
     * Cache key carrying the role, the shop scope and every applied filter, so
     * two owners' filter sets can never collide.
     *
     * @param  array<string, string|null>  $filters
     */
    private static function key(string $prefix, array $filters): string
    {
        // Segments are URL-encoded so a filter value containing the `:`
        // separator can never produce the same key as a different filter set.
        $segment = static fn (?string $value): string => rawurlencode($value ?? '-');

        return $prefix.':'.implode(':', [
            $segment($filters['shop'] ?? null),
            $segment($filters['from'] ?? null),
            $segment($filters['to'] ?? null),
            $segment($filters['type'] ?? null),
            $segment($filters['payment'] ?? null),
            $segment($filters['status'] ?? null),
            Format::today(),
        ]);
    }
}
