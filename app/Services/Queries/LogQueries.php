<?php

namespace App\Services\Queries;

use App\Models\User;
use App\Support\DataCache;
use App\Support\Format;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Port of the logs page (src/app/(app)/logs/page.tsx) via getLoginLogs() and
 * getStockLogs(): sign-ins plus every stock edit, newest first, with the
 * staff/shop names the list renders.
 *
 * Owner-only, exactly like the page (which redirected non-owners) and the RLS
 * policies (`login_logs: owner reads all`, `stock_logs: owner reads all`), so
 * a non-owner read fails closed with 403.
 */
final class LogQueries
{
    private const LOGIN_LIMIT = 100;

    private const STOCK_LIMIT = 200;

    private function __construct()
    {
    }

    /**
     * @return array{login_logs: list<array<string, mixed>>, stock_logs: list<array<string, mixed>>}
     */
    public static function index(User $actor): array
    {
        if (! $actor->isAdmin()) {
            throw new AccessDeniedHttpException;
        }

        $today = Format::today();

        return DataCache::remember("logs:owner:{$today}", fn (): array => self::body());
    }

    /**
     * @return array{login_logs: list<array<string, mixed>>, stock_logs: list<array<string, mixed>>}
     */
    private static function body(): array
    {
        $loginLogs = DB::table('login_logs')
            ->orderBy('created_at', 'desc')
            ->limit(self::LOGIN_LIMIT)
            ->get()
            ->map(fn (object $row): array => [
                'id' => (string) $row->id,
                'user_id' => (string) $row->user_id,
                'email' => $row->email === null ? null : (string) $row->email,
                'name' => $row->name === null ? null : (string) $row->name,
                'ip' => $row->ip === null ? null : (string) $row->ip,
                'user_agent' => $row->user_agent === null ? null : (string) $row->user_agent,
                'device' => $row->device === null ? null : (string) $row->device,
                'created_at' => (string) $row->created_at,
            ])
            ->all();

        $logs = DB::table('stock_logs')
            ->orderBy('created_at', 'desc')
            ->limit(self::STOCK_LIMIT)
            ->get();
        if ($logs->isEmpty()) {
            return ['login_logs' => $loginLogs, 'stock_logs' => []];
        }

        $staffIds = $logs->pluck('staff_id')->unique()->values()->all();
        $shopIds = $logs->pluck('shop_id')->unique()->values()->all();
        $staffNames = DB::table('users')->whereIn('id', $staffIds)->pluck('name', 'id')->all();
        $shopNames = DB::table('shops')->whereIn('id', $shopIds)->pluck('name', 'id')->all();

        $stockLogs = $logs->map(function (object $row) use ($staffNames, $shopNames): array {
            return [
                'id' => (string) $row->id,
                'shop_id' => (string) $row->shop_id,
                'phone_model_id' => $row->phone_model_id === null ? null : (string) $row->phone_model_id,
                'staff_id' => (string) $row->staff_id,
                'action' => (string) $row->action,
                'model_name' => $row->model_name === null ? null : (string) $row->model_name,
                'condition' => $row->condition === null ? null : (string) $row->condition,
                'details' => $row->details === null ? null : json_decode($row->details, true),
                'created_at' => (string) $row->created_at,
                'staff_name' => $staffNames[$row->staff_id] ?? null,
                'shop_name' => $shopNames[$row->shop_id] ?? null,
            ];
        })->all();

        return ['login_logs' => $loginLogs, 'stock_logs' => $stockLogs];
    }
}
