<?php

namespace App\Services\Queries;

use App\Models\User;
use App\Services\StockService;
use App\Support\DataCache;
use App\Support\Format;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Port of getDevicesData() (src/lib/data.ts) — the owner's device matrix:
 * available units per model across every shop, how many shops are running low,
 * and the recent sold history (last 180 days, capped at 200 sales per model).
 *
 * The devices page redirected non-owners to `/`, so a non-owner read here is
 * fail-closed with 403 — attendants must not see owner-only aggregates.
 */
final class DeviceQueries
{
    private function __construct()
    {
    }

    /**
     * @return array{shops: list<array<string, mixed>>, rows: list<array<string, mixed>>, salesFrom: string}
     */
    public static function index(User $actor): array
    {
        if (! $actor->isOwner()) {
            throw new AccessDeniedHttpException;
        }

        $salesFrom = QuerySupport::addDays(Format::today(), -QuerySupport::DEVICES_SALES_WINDOW_DAYS);

        return DataCache::remember("devices:{$salesFrom}", fn (): array => self::body($salesFrom));
    }

    /**
     * @return array{shops: list<array<string, mixed>>, rows: list<array<string, mixed>>, salesFrom: string}
     */
    private static function body(string $salesFrom): array
    {
        $shops = QuerySupport::shops();
        $stock = DB::table('phone_models')->orderBy('model_name')->orderBy('condition')->get();
        $txs = QuerySupport::transactions(['from' => $salesFrom]);

        $shopIndex = [];
        foreach ($shops as $index => $shop) {
            $shopIndex[$shop['id']] = $index;
        }

        $rows = [];
        $initRow = fn (string $modelName, string $condition, string $sim, string $color): array => [
            'key' => $modelName.'|'.$condition.'|'.$sim.'|'.$color,
            'model_name' => $modelName,
            'condition' => $condition,
            'sim_type' => $sim,
            'sim_label' => StockService::simLabel($sim),
            'color' => $color,
            'total' => 0,
            'sold' => 0,
            'low' => 0,
            'perShop' => array_map(fn (array $shop): array => [
                'shopId' => $shop['id'],
                'available' => 0,
                'low' => false,
                'threshold' => 0,
            ], $shops),
            'sales' => [],
        ];

        foreach ($stock as $model) {
            $sim = (string) ($model->sim_type ?? '');
            $color = (string) ($model->color ?? '');
            $key = $model->model_name.'|'.$model->condition.'|'.$sim.'|'.$color;
            if (! isset($rows[$key])) {
                $rows[$key] = $initRow((string) $model->model_name, (string) $model->condition, $sim, $color);
            }

            $index = $shopIndex[$model->shop_id] ?? null;
            if ($index !== null) {
                $cell = &$rows[$key]['perShop'][$index];
                $cell['available'] = (int) $model->available;
                $cell['threshold'] = (int) $model->low_stock_threshold;
                $cell['low'] = (int) $model->available <= (int) $model->low_stock_threshold;
                unset($cell);

                $rows[$key]['low'] = count(array_filter(
                    $rows[$key]['perShop'],
                    fn (array $cell): bool => $cell['low']
                ));
            }
            $rows[$key]['total'] += (int) $model->available;
        }

        foreach ($txs as $tx) {
            foreach ($tx['items'] as $item) {
                if ($item['direction'] !== 'out') {
                    continue;
                }

                $key = $item['model_name'].'|'.$item['condition'].'|'.($item['sim_type'] ?? '').'|'.($item['color'] ?? '');
                if (! isset($rows[$key])) {
                    $rows[$key] = $initRow($item['model_name'], $item['condition'], $item['sim_type'] ?? '', $item['color'] ?? '');
                }

                $rows[$key]['sold'] += $item['qty'];
                $rows[$key]['sales'][] = [
                    'transactionId' => $tx['id'],
                    'date' => $tx['date'],
                    'shopId' => $tx['shop_id'],
                    'shopName' => $tx['shop_name'],
                    'staffName' => $tx['staff_name'],
                    'customerName' => $tx['customer_name'],
                    'customerPhone' => $tx['customer_phone'],
                    'qty' => $item['qty'],
                    'amount' => $tx['amount'],
                ];
            }
        }

        foreach ($rows as $key => $row) {
            usort($row['sales'], static fn (array $a, array $b): int => strcmp($b['date'], $a['date']));
            if (count($row['sales']) > QuerySupport::DEVICES_MAX_SALES_PER_MODEL) {
                $row['sales'] = array_slice($row['sales'], 0, QuerySupport::DEVICES_MAX_SALES_PER_MODEL);
            }
            $rows[$key] = $row;
        }

        $list = array_values($rows);
        usort($list, static function (array $a, array $b): int {
            $cmp = strcasecmp($a['model_name'], $b['model_name']);
            if ($cmp === 0) {
                $cmp = strcmp($a['condition'], $b['condition']);
            }
            if ($cmp === 0) {
                $cmp = strcmp($a['sim_type'], $b['sim_type']);
            }
            if ($cmp === 0) {
                $cmp = strcasecmp($a['color'], $b['color']);
            }

            return $cmp;
        });

        return ['shops' => $shops, 'rows' => $list, 'salesFrom' => $salesFrom];
    }
}
