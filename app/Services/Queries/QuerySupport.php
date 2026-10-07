<?php

namespace App\Services\Queries;

use Illuminate\Support\Facades\DB;

/**
 * Shared reads behind the query classes — ports of the hydration and
 * aggregation routines in src/lib/data.ts (hydrateTransactions, getShopSummary,
 * buildSeries, getCachedStockRecon, getCachedStockRequests, ...).
 *
 * Helpers never decide visibility: each one takes an explicit shop id or runs
 * over an already-authorised row set, and leaves RLS scoping to the query
 * class that calls it.
 */
final class QuerySupport
{
    /** Port of TX_READ_LIMIT — cap on transactions a single read loads. */
    public const TX_LIMIT = 2000;

    /** Port of DEVICES_SALES_WINDOW_DAYS. */
    public const DEVICES_SALES_WINDOW_DAYS = 180;

    /** Port of DEVICES_MAX_SALES_PER_MODEL. */
    public const DEVICES_MAX_SALES_PER_MODEL = 200;

    private function __construct()
    {
    }

    /** Port of addDays() from src/lib/format.ts — UTC calendar days. */
    public static function addDays(string $date, int $days): string
    {
        $ts = strtotime($date.' 00:00:00 UTC');

        return $ts === false ? $date : gmdate('Y-m-d', $ts + ($days * 86400));
    }

    /** Port of isDate() from src/app/reports/export/route.ts. */
    public static function isDate(mixed $value): bool
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return false;
        }

        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));

        return $parsed !== false && $parsed->format('Y-m-d') === $value;
    }

    /** Port of getCachedShops() — every shop, name order. */
    public static function shops(): array
    {
        return DB::table('shops')
            ->orderBy('name')
            ->get()
            ->map(fn (object $row): array => self::shop($row))
            ->all();
    }

    /** @return array<string, mixed> */
    public static function shop(object $row): array
    {
        return [
            'id' => (string) $row->id,
            'name' => (string) $row->name,
            'location' => $row->location === null ? null : (string) $row->location,
            'phone' => $row->phone === null ? null : (string) $row->phone,
            'created_at' => (string) $row->created_at,
        ];
    }

    /** Port of getCachedStock(shopId) — one shop's models, name order. */
    public static function stock(string $shopId): array
    {
        return DB::table('phone_models')
            ->where('shop_id', $shopId)
            ->orderBy('model_name')
            ->orderBy('condition')
            ->get()
            ->map(fn (object $row): array => self::phoneModel($row))
            ->all();
    }

    /** @return array<string, mixed> */
    public static function phoneModel(object $row): array
    {
        return [
            'id' => (string) $row->id,
            'shop_id' => (string) $row->shop_id,
            'model_name' => (string) $row->model_name,
            'condition' => (string) $row->condition,
            'sim_type' => (string) ($row->sim_type ?? ''),
            'color' => (string) ($row->color ?? ''),
            'category' => (string) ($row->category ?? 'phone'),
            'cost_price' => $row->cost_price === null ? null : (float) $row->cost_price,
            'sale_price' => $row->sale_price === null ? null : (float) $row->sale_price,
            'opening_stock' => (int) $row->opening_stock,
            'bought_in' => (int) $row->bought_in,
            'available' => (int) $row->available,
            'low_stock_threshold' => (int) $row->low_stock_threshold,
            'created_at' => (string) $row->created_at,
        ];
    }

    /**
     * Port of getCachedTransactions()/getTransactions(): date-descending read
     * with the original's filters and defaults — status defaults to
     * `completed`, `status: "all"` disables the filter, no explicit limit
     * falls back to TX_READ_LIMIT.
     *
     * @param  array{shopId?: string|null, from?: string|null, to?: string|null, type?: string|null, paymentMethod?: string|null, status?: string|null, limit?: int}  $opts
     * @return list<array<string, mixed>>
     */
    public static function transactions(array $opts): array
    {
        $status = $opts['status'] ?? 'completed';

        $query = DB::table('transactions');
        if (! empty($opts['shopId'])) {
            $query->where('shop_id', $opts['shopId']);
        }
        if (! empty($opts['from'])) {
            $query->where('date', '>=', $opts['from'].' 00:00:00');
        }
        if (! empty($opts['to'])) {
            $query->where('date', '<', self::addDays($opts['to'], 1).' 00:00:00');
        }
        if (! empty($opts['type'])) {
            $query->where('type', $opts['type']);
        }
        if (! empty($opts['paymentMethod'])) {
            $query->where('payment_method', $opts['paymentMethod']);
        }
        if ($status !== 'all') {
            $query->where('status', $status);
        }

        $query->orderBy('date', 'desc')
            ->orderBy('id')
            ->limit((int) ($opts['limit'] ?? self::TX_LIMIT));

        return self::hydrateTransactions($query->get()->all());
    }

    /**
     * Port of hydrateTransactions(): shop/staff names plus model-backed items.
     * Trade-in items are listed after out-items because the record RPC inserts
     * them that way.
     *
     * @param  list<object>  $txs
     * @return list<array<string, mixed>>
     */
    public static function hydrateTransactions(array $txs): array
    {
        if ($txs === []) {
            return [];
        }

        $rows = array_map(fn (object $row): array => self::transaction($row), $txs);

        $txIds = array_column($rows, 'id');
        $shopIds = array_values(array_unique(array_column($rows, 'shop_id')));
        $staffIds = array_values(array_unique(array_column($rows, 'staff_id')));

        $items = DB::table('transaction_items')->whereIn('transaction_id', $txIds)->get();
        $shopNames = DB::table('shops')->whereIn('id', $shopIds)->pluck('name', 'id')->all();
        $staffNames = DB::table('users')->whereIn('id', $staffIds)->pluck('name', 'id')->all();

        $modelIds = $items->pluck('phone_model_id')->unique()->values()->all();
        $models = [];
        if ($modelIds !== []) {
            foreach (DB::table('phone_models')->whereIn('id', $modelIds)->get(['id', 'model_name', 'condition', 'sim_type', 'color', 'category', 'cost_price']) as $model) {
                $models[$model->id] = $model;
            }
        }

        $itemsByTx = [];
        foreach ($items as $item) {
            $itemsByTx[$item->transaction_id][] = $item;
        }
        foreach ($itemsByTx as $txId => $list) {
            usort($list, static fn (object $a, object $b): int => ($a->direction === 'out' ? 0 : 1) <=> ($b->direction === 'out' ? 0 : 1));
            $itemsByTx[$txId] = $list;
        }

        foreach ($rows as $index => $row) {
            $row['shop_name'] = $shopNames[$row['shop_id']] ?? null;
            $row['staff_name'] = $staffNames[$row['staff_id']] ?? null;

            $rowItems = [];
            foreach ($itemsByTx[$row['id']] ?? [] as $item) {
                $model = $models[$item->phone_model_id] ?? null;
                $rowItems[] = [
                    'id' => (string) $item->id,
                    'direction' => (string) $item->direction,
                    'qty' => (int) $item->qty,
                    'model_name' => $model === null ? 'Unknown model' : (string) $model->model_name,
                    'condition' => $model === null ? 'used' : (string) $model->condition,
                    'sim_type' => $model === null ? '' : (string) ($model->sim_type ?? ''),
                    'color' => $model === null ? '' : (string) ($model->color ?? ''),
                    'category' => $model === null ? 'phone' : (string) ($model->category ?? 'phone'),
                    'cost_price' => $model === null || $model->cost_price === null ? null : (float) $model->cost_price,
                ];
            }
            $row['items'] = $rowItems;
            $rows[$index] = $row;
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    public static function transaction(object $row): array
    {
        return [
            'id' => (string) $row->id,
            'shop_id' => (string) $row->shop_id,
            'staff_id' => (string) $row->staff_id,
            'customer_name' => $row->customer_name === null ? null : (string) $row->customer_name,
            'customer_phone' => $row->customer_phone === null ? null : (string) $row->customer_phone,
            'type' => (string) $row->type,
            'payment_method' => (string) $row->payment_method,
            'amount' => $row->amount === null ? 0.0 : (float) $row->amount,
            'date' => (string) $row->date,
            'created_at' => (string) $row->created_at,
            'idempotency_key' => $row->idempotency_key === null ? null : (string) $row->idempotency_key,
            'status' => (string) $row->status,
            'listed_amount' => $row->listed_amount === null ? null : (float) $row->listed_amount,
            'review_reason' => $row->review_reason === null ? null : (string) $row->review_reason,
            'discount_reason' => $row->discount_reason === null ? null : (string) $row->discount_reason,
            'payment_reference' => $row->payment_reference === null ? null : (string) $row->payment_reference,
            'reviewed_by' => $row->reviewed_by === null ? null : (string) $row->reviewed_by,
            'reviewed_at' => $row->reviewed_at === null ? null : (string) $row->reviewed_at,
            'voided_by' => $row->voided_by === null ? null : (string) $row->voided_by,
            'voided_at' => $row->voided_at === null ? null : (string) $row->voided_at,
            'void_reason' => $row->void_reason === null ? null : (string) $row->void_reason,
        ];
    }

    /**
     * Port of getCachedStockRequests()/getStockRequests(): newest first, with
     * the shop/staff/model names the approval panel renders.
     *
     * @return list<array<string, mixed>>
     */
    public static function stockRequests(?string $shopId, ?string $status, int $limit = 100): array
    {
        $query = DB::table('stock_requests');
        if ($shopId !== null) {
            $query->where('shop_id', $shopId);
        }
        if ($status !== null) {
            $query->where('status', $status);
        }

        $requests = $query->orderBy('created_at', 'desc')->limit($limit)->get();
        if ($requests->isEmpty()) {
            return [];
        }

        $shopIds = $requests->pluck('shop_id')->unique()->values()->all();
        $staffIds = $requests->pluck('staff_id')->unique()->values()->all();
        $modelIds = $requests->pluck('phone_model_id')->filter()->unique()->values()->all();

        $shopNames = DB::table('shops')->whereIn('id', $shopIds)->pluck('name', 'id')->all();
        $staffNames = DB::table('users')->whereIn('id', $staffIds)->pluck('name', 'id')->all();
        $modelNames = DB::table('phone_models')->whereIn('id', $modelIds)->pluck('model_name', 'id')->all();

        return $requests->map(function (object $row) use ($shopNames, $staffNames, $modelNames): array {
            $request = self::stockRequest($row);
            $request['shop_name'] = $shopNames[$row->shop_id] ?? null;
            $request['staff_name'] = $staffNames[$row->staff_id] ?? null;
            $modelId = $row->phone_model_id;
            $request['model_name_display'] = $row->model_name
                ?? ($modelId !== null ? ($modelNames[$modelId] ?? null) : null);

            return $request;
        })->all();
    }

    /** @return array<string, mixed> */
    public static function stockRequest(object $row): array
    {
        return [
            'id' => (string) $row->id,
            'shop_id' => (string) $row->shop_id,
            'staff_id' => (string) $row->staff_id,
            'type' => (string) $row->type,
            'status' => (string) $row->status,
            'model_name' => $row->model_name === null ? null : (string) $row->model_name,
            'condition' => $row->condition === null ? null : (string) $row->condition,
            'sim_type' => (string) ($row->sim_type ?? ''),
            'color' => (string) ($row->color ?? ''),
            'category' => (string) ($row->category ?? 'phone'),
            'cost_price' => $row->cost_price === null ? null : (float) $row->cost_price,
            'sale_price' => $row->sale_price === null ? null : (float) $row->sale_price,
            'low_stock_threshold' => $row->low_stock_threshold === null ? null : (int) $row->low_stock_threshold,
            'opening_stock' => $row->opening_stock === null ? null : (int) $row->opening_stock,
            'phone_model_id' => $row->phone_model_id === null ? null : (string) $row->phone_model_id,
            'delta' => $row->delta === null ? null : (int) $row->delta,
            'reason' => $row->reason === null ? null : (string) $row->reason,
            'created_at' => (string) $row->created_at,
            'decided_at' => $row->decided_at === null ? null : (string) $row->decided_at,
            'decided_by' => $row->decided_by === null ? null : (string) $row->decided_by,
            'error_note' => $row->error_note === null ? null : (string) $row->error_note,
        ];
    }

    /** Port of getCachedAdjustments()/getAdjustments(): newest first. */
    public static function adjustments(?string $shopId, int $limit = 50): array
    {
        $query = DB::table('stock_adjustments');
        if ($shopId !== null) {
            $query->where('shop_id', $shopId);
        }

        return $query->orderBy('date', 'desc')->limit($limit)->get()
            ->map(fn (object $row): array => self::adjustment($row))
            ->all();
    }

    /** @return array<string, mixed> */
    public static function adjustment(object $row): array
    {
        return [
            'id' => (string) $row->id,
            'shop_id' => (string) $row->shop_id,
            'phone_model_id' => (string) $row->phone_model_id,
            'staff_id' => (string) $row->staff_id,
            'type' => (string) $row->type,
            'delta' => (int) $row->delta,
            'reason' => $row->reason === null ? null : (string) $row->reason,
            'date' => (string) $row->date,
        ];
    }

    /**
     * Port of getCachedSwappedPhones()/getSwappedPhones(): newest first.
     *
     * @param  array{shopId?: string|null, transactionId?: string|null, status?: string|null, limit?: int}  $opts
     * @return list<array<string, mixed>>
     */
    public static function swappedPhones(array $opts): array
    {
        $query = DB::table('swapped_phones');
        if (! empty($opts['shopId'])) {
            $query->where('shop_id', $opts['shopId']);
        }
        if (! empty($opts['transactionId'])) {
            $query->where('transaction_id', $opts['transactionId']);
        }
        if (! empty($opts['status'])) {
            $query->where('status', $opts['status']);
        }

        return $query->orderBy('created_at', 'desc')->limit((int) ($opts['limit'] ?? 200))->get()
            ->map(fn (object $row): array => self::swappedPhone($row))
            ->all();
    }

    /** @return array<string, mixed> */
    public static function swappedPhone(object $row): array
    {
        return [
            'id' => (string) $row->id,
            'shop_id' => (string) $row->shop_id,
            'transaction_id' => $row->transaction_id === null ? null : (string) $row->transaction_id,
            'staff_id' => $row->staff_id === null ? null : (string) $row->staff_id,
            'model_name' => (string) $row->model_name,
            'condition' => (string) $row->condition,
            'customer_name' => $row->customer_name === null ? null : (string) $row->customer_name,
            'customer_phone' => $row->customer_phone === null ? null : (string) $row->customer_phone,
            'status' => (string) $row->status,
            'notes' => $row->notes === null ? null : (string) $row->notes,
            'created_at' => (string) $row->created_at,
        ];
    }

    /**
     * Port of getCachedShopSummary()/getShopSummary(): one shop's aggregated
     * read over a date range. Returns null when the shop row is missing, which
     * is what made the original throw "Shop not found".
     *
     * @return array<string, mixed>|null
     */
    public static function shopSummary(string $shopId, string $from, string $to): ?array
    {
        $shopRow = DB::table('shops')->where('id', $shopId)->first();
        if ($shopRow === null) {
            return null;
        }

        $stock = self::stock($shopId);
        $txs = self::transactions(['shopId' => $shopId, 'from' => $from, 'to' => $to]);

        $rows = [];
        foreach ($txs as $tx) {
            foreach ($tx['items'] as $item) {
                $key = $item['model_name'].'|'.$item['condition'].'|'.($item['sim_type'] ?? '').'|'.($item['color'] ?? '').'|'.($item['category'] ?? 'phone');
                if (! isset($rows[$key])) {
                    $rows[$key] = [
                        'phone_model_id' => '',
                        'model_name' => $item['model_name'],
                        'condition' => $item['condition'],
                        'sim_type' => $item['sim_type'] ?? '',
                        'color' => $item['color'] ?? '',
                        'category' => $item['category'] ?? 'phone',
                        'sold' => 0,
                        'swapped_out' => 0,
                    ];
                }
                if ($item['direction'] !== 'out') {
                    continue;
                }
                if ($tx['type'] === 'swap') {
                    $rows[$key]['swapped_out'] += $item['qty'];
                } else {
                    $rows[$key]['sold'] += $item['qty'];
                }
            }
        }

        $revenue = 0.0;
        $cogs = 0.0;
        $sales = 0;
        $swaps = 0;
        $repairs = 0;
        foreach ($txs as $tx) {
            $revenue += $tx['amount'];
            if ($tx['type'] === 'sale') {
                $sales++;
            } elseif ($tx['type'] === 'swap') {
                $swaps++;
            } elseif ($tx['type'] === 'repair') {
                $repairs++;
            }
            foreach ($tx['items'] as $item) {
                if ($item['direction'] === 'out') {
                    $cogs += $item['qty'] * ($item['cost_price'] ?? 0);
                }
            }
        }

        return [
            'shop' => self::shop($shopRow),
            'rows' => array_values($rows),
            'total_sales' => $sales,
            'total_swaps' => $swaps,
            'total_repairs' => $repairs,
            'revenue' => $revenue,
            'cogs' => $cogs,
            'profit' => $revenue - $cogs,
            'low_stock' => array_values(array_filter(
                $stock,
                fn (array $model): bool => $model['available'] <= $model['low_stock_threshold']
            )),
        ];
    }

    /**
     * Port of buildSeries(): one zeroed point per day of the range, then the
     * completed transactions folded into their UTC calendar day.
     *
     * @return list<array{date: string, revenue: float, profit: float, units: int, sales: int}>
     */
    public static function buildSeries(array $txs, string $from, string $to): array
    {
        $days = [];
        $current = $from;
        for ($i = 0; $i < 400; $i++) {
            $days[$current] = ['date' => $current, 'revenue' => 0.0, 'profit' => 0.0, 'units' => 0, 'sales' => 0];
            if ($current === $to) {
                break;
            }
            $current = self::addDays($current, 1);
        }

        foreach ($txs as $tx) {
            $day = substr((string) $tx['date'], 0, 10);
            if (! isset($days[$day])) {
                continue;
            }

            $amount = $tx['amount'] ?? 0.0;
            $cogs = 0.0;
            $units = 0;
            foreach ($tx['items'] as $item) {
                if ($item['direction'] !== 'out') {
                    continue;
                }
                $cogs += $item['qty'] * ($item['cost_price'] ?? 0);
                $units += $item['qty'];
            }

            $days[$day]['revenue'] += $amount;
            $days[$day]['profit'] += $amount - $cogs;
            $days[$day]['units'] += $units;
            if ($tx['type'] === 'sale') {
                $days[$day]['sales'] += 1;
            }
        }

        return array_values($days);
    }

    /**
     * Port of aggregateTotals(): the dashboard stat cards.
     *
     * @return array{revenue: float, profit: float, sales: int, swaps: int, repairs: int, units_out: int, low_stock: int}
     */
    public static function aggregateTotals(array $summaries): array
    {
        $totals = [
            'revenue' => 0.0,
            'profit' => 0.0,
            'sales' => 0,
            'swaps' => 0,
            'repairs' => 0,
            'units_out' => 0,
            'low_stock' => 0,
        ];

        foreach ($summaries as $summary) {
            $totals['revenue'] += $summary['revenue'];
            $totals['profit'] += $summary['profit'];
            $totals['sales'] += $summary['total_sales'];
            $totals['swaps'] += $summary['total_swaps'];
            $totals['repairs'] += $summary['total_repairs'];
            $totals['low_stock'] += count($summary['low_stock']);
            foreach ($summary['rows'] as $row) {
                $totals['units_out'] += $row['sold'] + $row['swapped_out'];
            }
        }

        return $totals;
    }

    /**
     * Port of getCachedStockRecon(): morning → bought → left per model, derived
     * from the ledger (closing = available − movement after the day, opening =
     * closing − movement during the day).
     *
     * @return array{date: string, rows: list<array<string, mixed>>, totalOpening: int, totalSold: int, totalClosing: int}
     */
    public static function stockRecon(string $shopId, string $date): array
    {
        $dayStart = $date.' 00:00:00';
        $nextDayStart = self::addDays($date, 1).' 00:00:00';
        $nextDayTs = strtotime($nextDayStart);

        $models = DB::table('phone_models')
            ->where('shop_id', $shopId)
            ->get(['id', 'model_name', 'condition', 'available']);
        $txs = DB::table('transactions')
            ->where('shop_id', $shopId)
            ->where('date', '>=', $dayStart)
            ->orderBy('date')
            ->orderBy('id')
            ->limit(self::TX_LIMIT)
            ->get(['id', 'type', 'status', 'date']);
        $adjustments = DB::table('stock_adjustments')
            ->where('shop_id', $shopId)
            ->where('date', '>=', $dayStart)
            ->orderBy('date')
            ->orderBy('id')
            ->limit(self::TX_LIMIT)
            ->get(['phone_model_id', 'delta', 'date']);

        $txIds = $txs->pluck('id')->all();
        $items = $txIds === []
            ? []
            : DB::table('transaction_items')
                ->whereIn('transaction_id', $txIds)
                ->get(['transaction_id', 'phone_model_id', 'direction', 'qty']);

        $txDate = [];
        $txStatus = [];
        foreach ($txs as $tx) {
            $txDate[$tx->id] = strtotime($tx->date);
            $txStatus[$tx->id] = (string) $tx->status;
        }

        $rows = [];
        foreach ($models as $model) {
            $rows[$model->id] = [
                'phone_model_id' => (string) $model->id,
                'model_name' => (string) $model->model_name,
                'condition' => (string) $model->condition,
                'opening' => 0,
                'sold' => 0,
                'pending' => 0,
                'trade_in' => 0,
                'restocked' => 0,
                'removed' => 0,
                'closing' => 0,
                'available' => (int) $model->available,
                'dayNet' => 0,
                'afterNet' => 0,
            ];
        }

        foreach ($items as $item) {
            if (! isset($rows[$item->phone_model_id]) || ! isset($txDate[$item->transaction_id])) {
                continue;
            }
            $row = &$rows[$item->phone_model_id];
            $txTs = $txDate[$item->transaction_id];
            $contrib = $item->direction === 'in' ? (int) $item->qty : -((int) $item->qty);

            if ($txTs !== false && $nextDayTs !== false && $txTs >= $nextDayTs) {
                $row['afterNet'] += $contrib;
                unset($row);

                continue;
            }

            $row['dayNet'] += $contrib;
            $status = $txStatus[$item->transaction_id];
            if ($status === 'completed') {
                if ($item->direction === 'out') {
                    $row['sold'] += (int) $item->qty;
                } else {
                    $row['trade_in'] += (int) $item->qty;
                }
            } elseif ($status === 'pending_review' && $item->direction === 'out') {
                $row['pending'] += (int) $item->qty;
            }
            unset($row);
        }

        foreach ($adjustments as $adjustment) {
            if (! isset($rows[$adjustment->phone_model_id])) {
                continue;
            }
            $row = &$rows[$adjustment->phone_model_id];
            $ts = strtotime($adjustment->date);
            $delta = (int) $adjustment->delta;

            if ($ts !== false && $nextDayTs !== false && $ts >= $nextDayTs) {
                $row['afterNet'] += $delta;
            } else {
                $row['dayNet'] += $delta;
                if ($delta > 0) {
                    $row['restocked'] += $delta;
                } else {
                    $row['removed'] += -$delta;
                }
            }
            unset($row);
        }

        $list = [];
        foreach ($rows as $row) {
            $closing = $row['available'] - $row['afterNet'];
            $list[] = [
                'phone_model_id' => $row['phone_model_id'],
                'model_name' => $row['model_name'],
                'condition' => $row['condition'],
                'sold' => $row['sold'],
                'pending' => $row['pending'],
                'trade_in' => $row['trade_in'],
                'restocked' => $row['restocked'],
                'removed' => $row['removed'],
                'closing' => $closing,
                'opening' => $closing - $row['dayNet'],
            ];
        }

        usort($list, static function (array $a, array $b): int {
            $cmp = strcasecmp($a['model_name'], $b['model_name']);

            return $cmp !== 0 ? $cmp : strcmp($a['condition'], $b['condition']);
        });

        $totalOpening = 0;
        $totalSold = 0;
        $totalClosing = 0;
        foreach ($list as $row) {
            $totalOpening += $row['opening'];
            $totalSold += $row['sold'];
            $totalClosing += $row['closing'];
        }

        return [
            'date' => $date,
            'rows' => $list,
            'totalOpening' => $totalOpening,
            'totalSold' => $totalSold,
            'totalClosing' => $totalClosing,
        ];
    }

    /** Port of getDailyClose(): the day's cash reconciliation row, or null. */
    public static function dailyClose(string $shopId, string $date): ?array
    {
        $row = DB::table('daily_closes')
            ->where('shop_id', $shopId)
            ->where('close_date', $date)
            ->first();

        return $row === null ? null : self::dailyCloseRow($row);
    }

    /** @return array<string, mixed> */
    public static function dailyCloseRow(object $row): array
    {
        return [
            'id' => (string) $row->id,
            'shop_id' => (string) $row->shop_id,
            'close_date' => substr((string) $row->close_date, 0, 10),
            'status' => (string) $row->status,
            'expected_cash' => (float) $row->expected_cash,
            'expected_mobile_money' => (float) $row->expected_mobile_money,
            'expected_other' => (float) $row->expected_other,
            'counted_cash' => $row->counted_cash === null ? null : (float) $row->counted_cash,
            'counted_mobile_money' => $row->counted_mobile_money === null ? null : (float) $row->counted_mobile_money,
            'counted_other' => $row->counted_other === null ? null : (float) $row->counted_other,
            'notes' => $row->notes === null ? null : (string) $row->notes,
            'submitted_by' => (string) $row->submitted_by,
            'submitted_at' => (string) $row->submitted_at,
            'locked_by' => $row->locked_by === null ? null : (string) $row->locked_by,
            'locked_at' => $row->locked_at === null ? null : (string) $row->locked_at,
            'created_at' => (string) $row->created_at,
        ];
    }

    /** Port of getLatestStockCount(): newest count for a shop, with items. */
    public static function latestStockCount(string $shopId): ?array
    {
        $count = DB::table('stock_counts')
            ->where('shop_id', $shopId)
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->first();

        return $count === null ? null : self::stockCount($count);
    }

    /** Port of getStockCountForDate(): newest count recorded for that day. */
    public static function stockCountForDate(string $shopId, string $date): ?array
    {
        $count = DB::table('stock_counts')
            ->where('shop_id', $shopId)
            ->where('count_date', $date)
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->first();

        return $count === null ? null : self::stockCount($count);
    }

    /** Port of getStockCountForDate()'s item hydration (model names attached). */
    public static function stockCount(object $count): array
    {
        $items = DB::table('stock_count_items')->where('count_id', $count->id)->get();
        $modelIds = $items->pluck('phone_model_id')->unique()->values()->all();
        $modelNames = $modelIds === []
            ? []
            : DB::table('phone_models')->whereIn('id', $modelIds)->pluck('model_name', 'id')->all();

        return [
            'id' => (string) $count->id,
            'shop_id' => (string) $count->shop_id,
            'count_date' => substr((string) $count->count_date, 0, 10),
            'status' => (string) $count->status,
            'submitted_by' => (string) $count->submitted_by,
            'approved_by' => $count->approved_by === null ? null : (string) $count->approved_by,
            'notes' => $count->notes === null ? null : (string) $count->notes,
            'created_at' => (string) $count->created_at,
            'items' => $items->map(function (object $item) use ($modelNames): array {
                return [
                    'id' => (string) $item->id,
                    'count_id' => (string) $item->count_id,
                    'phone_model_id' => (string) $item->phone_model_id,
                    'expected_qty' => (int) $item->expected_qty,
                    'counted_qty' => (int) $item->counted_qty,
                    'model_name' => $modelNames[$item->phone_model_id] ?? 'Unknown model',
                ];
            })->all(),
        ];
    }
}