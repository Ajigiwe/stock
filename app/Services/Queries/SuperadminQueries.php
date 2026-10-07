<?php

namespace App\Services\Queries;

use App\Models\User;
use App\Services\StockService;
use App\Support\Format;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * The superadmin command center: money, stock alerts, the approvals inbox,
 * staff oversight and data health across every shop. Superadmin only —
 * owners keep their own dashboard and settings pages.
 */
final class SuperadminQueries
{
    private function __construct()
    {
    }

    /** @return array<string, mixed> */
    public static function dashboard(User $actor): array
    {
        if ($actor->role !== User::ROLE_SUPERADMIN) {
            throw new AccessDeniedHttpException;
        }

        $today = Format::today();
        $weekStart = QuerySupport::addDays($today, -6);

        $shops = QuerySupport::shops();
        $shopNames = collect($shops)->mapWithKeys(fn (array $s): array => [$s['id'] => $s['name']]);

        return [
            'shops' => $shops,
            'simTypes' => StockService::SIM_TYPES,
            'money' => self::money($today, $weekStart, $shopNames),
            'leaders' => self::leaders(QuerySupport::addDays($today, -29)),
            'activity' => self::activity(),
            'stock' => self::stockAlerts($shopNames),
            'approvals' => self::approvals($shopNames),
            'staff' => self::staff($shopNames),
            'system' => self::system(),
        ];
    }

    /** @return array<string, mixed> */
    private static function money(string $today, string $weekStart, mixed $shopNames): array
    {
        $completed = fn () => DB::table('transactions')->where('status', 'completed');

        $byShopToday = (clone $completed())
            ->where('date', '>=', $today.' 00:00:00')
            ->selectRaw('shop_id, COUNT(*) AS txs, COALESCE(SUM(amount), 0) AS revenue')
            ->groupBy('shop_id')
            ->get()
            ->keyBy('shop_id');

        $byShopWeek = (clone $completed())
            ->where('date', '>=', $weekStart.' 00:00:00')
            ->selectRaw('shop_id, COUNT(*) AS txs, COALESCE(SUM(amount), 0) AS revenue')
            ->groupBy('shop_id')
            ->get()
            ->keyBy('shop_id');

        $methods = (clone $completed())
            ->where('date', '>=', $today.' 00:00:00')
            ->selectRaw('payment_method, COALESCE(SUM(amount), 0) AS revenue')
            ->groupBy('payment_method')
            ->pluck('revenue', 'payment_method')
            ->all();

        $pending = DB::table('transactions')
            ->where('status', 'pending_review')
            ->selectRaw('COUNT(*) AS txs, COALESCE(SUM(amount), 0) AS revenue')
            ->first();

        $perShop = [];
        foreach ($shopNames as $id => $name) {
            $day = $byShopToday->get($id);
            $week = $byShopWeek->get($id);
            $perShop[] = [
                'id' => $id,
                'name' => $name,
                'today_txs' => (int) ($day->txs ?? 0),
                'today_revenue' => (float) ($day->revenue ?? 0),
                'week_txs' => (int) ($week->txs ?? 0),
                'week_revenue' => (float) ($week->revenue ?? 0),
            ];
        }

        return [
            'perShop' => $perShop,
            'today_txs' => array_sum(array_column($perShop, 'today_txs')),
            'today_revenue' => array_sum(array_column($perShop, 'today_revenue')),
            'week_revenue' => array_sum(array_column($perShop, 'week_revenue')),
            'methods' => $methods,
            'pending_txs' => (int) ($pending->txs ?? 0),
            'pending_revenue' => (float) ($pending->revenue ?? 0),
        ];
    }

    /** Staff leaderboard: completed sales per person over the trailing 30 days. */
    private static function leaders(string $since): array
    {
        $rows = DB::table('transactions')
            ->where('status', 'completed')
            ->where('date', '>=', $since.' 00:00:00')
            ->selectRaw('staff_id, COUNT(*) AS sales, COALESCE(SUM(amount), 0) AS revenue')
            ->groupBy('staff_id')
            ->orderByDesc('revenue')
            ->limit(10)
            ->get();

        $names = DB::table('users')->whereIn('id', $rows->pluck('staff_id')->all())->get(['id', 'name', 'role', 'shop_id']);
        $byId = $names->keyBy('id');
        $shops = DB::table('shops')->pluck('name', 'id')->all();

        $board = [];
        foreach ($rows as $row) {
            $user = $byId->get($row->staff_id);
            $board[] = [
                'name' => $user->name ?? '—',
                'role' => $user->role ?? '—',
                'shop_name' => ($user->shop_id ?? null) === null ? null : ($shops[$user->shop_id] ?? null),
                'sales' => (int) $row->sales,
                'revenue' => (float) $row->revenue,
            ];
        }

        return $board;
    }

    /** Latest stock movements + till decisions across every shop. */
    private static function activity(): array
    {
        $logs = DB::table('stock_logs')->orderBy('created_at', 'desc')->limit(10)->get();
        $events = DB::table('transaction_events')->orderBy('created_at', 'desc')->limit(10)->get();

        $userIds = $logs->pluck('staff_id')->merge($events->pluck('actor_id'))->unique()->all();
        $names = DB::table('users')->whereIn('id', $userIds)->pluck('name', 'id')->all();
        $shopNames = DB::table('shops')->pluck('name', 'id')->all();
        $modelNames = DB::table('phone_models')
            ->whereIn('id', $logs->pluck('phone_model_id')->filter()->unique()->all())
            ->pluck('model_name', 'id')
            ->all();
        $txShops = DB::table('transactions')
            ->whereIn('id', $events->pluck('transaction_id')->unique()->all())
            ->pluck('shop_id', 'id')
            ->all();

        $moves = [];
        foreach ($logs as $log) {
            $moves[] = [
                'at' => (string) $log->created_at,
                'text' => ($names[$log->staff_id] ?? 'Someone').' · '.str_replace('_', ' ', (string) $log->action)
                    .($log->phone_model_id !== null ? ' · '.($modelNames[$log->phone_model_id] ?? 'a model') : ''),
                'shop_name' => $shopNames[$log->shop_id] ?? null,
            ];
        }

        $decisions = [];
        foreach ($events as $event) {
            $decisions[] = [
                'at' => (string) $event->created_at,
                'text' => ($names[$event->actor_id] ?? 'Someone').' · '.(string) $event->action.' a transaction',
                'shop_name' => $shopNames[$txShops[$event->transaction_id] ?? ''] ?? null,
            ];
        }

        return ['moves' => $moves, 'decisions' => $decisions];
    }

    /** @return array<string, mixed> */
    private static function stockAlerts(mixed $shopNames): array
    {
        $rows = DB::table('phone_models')
            ->whereColumn('available', '<=', 'low_stock_threshold')
            ->orderBy('available')
            ->limit(25)
            ->get(['id', 'shop_id', 'model_name', 'condition', 'sim_type', 'color', 'available', 'low_stock_threshold']);

        $low = [];
        foreach ($rows as $row) {
            $low[] = [
                'id' => (string) $row->id,
                'shop_id' => (string) $row->shop_id,
                'shop_name' => $shopNames[$row->shop_id] ?? '—',
                'model_name' => (string) $row->model_name,
                'condition' => (string) $row->condition,
                'sim_type' => (string) ($row->sim_type ?? ''),
                'color' => (string) ($row->color ?? ''),
                'available' => (int) $row->available,
                'low_stock_threshold' => (int) $row->low_stock_threshold,
            ];
        }

        return [
            'low' => $low,
            'low_count' => DB::table('phone_models')->whereColumn('available', '<=', 'low_stock_threshold')->count(),
            'out_count' => DB::table('phone_models')->where('available', 0)->count(),
            'model_count' => DB::table('phone_models')->count(),
        ];
    }

    /** @return array<string, mixed> */
    private static function approvals(mixed $shopNames): array
    {
        $requests = QuerySupport::stockRequests(null, 'pending', 10);

        $counts = DB::table('stock_counts')
            ->where('status', 'submitted')
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get(['id', 'shop_id', 'count_date', 'submitted_by', 'created_at']);
        $names = DB::table('users')->whereIn('id', $counts->pluck('submitted_by')->unique()->all())->pluck('name', 'id')->all();
        $submitted = [];
        foreach ($counts as $count) {
            $submitted[] = [
                'id' => (string) $count->id,
                'shop_id' => (string) $count->shop_id,
                'shop_name' => $shopNames[$count->shop_id] ?? '—',
                'count_date' => (string) $count->count_date,
                'submitted_by' => $names[$count->submitted_by] ?? '—',
            ];
        }

        $reviews = DB::table('transactions')
            ->where('status', 'pending_review')
            ->orderBy('date', 'desc')
            ->limit(5)
            ->get(['id', 'shop_id', 'amount', 'customer_name', 'date']);
        $reviewRows = [];
        foreach ($reviews as $tx) {
            $reviewRows[] = [
                'id' => (string) $tx->id,
                'shop_id' => (string) $tx->shop_id,
                'shop_name' => $shopNames[$tx->shop_id] ?? '—',
                'amount' => (float) $tx->amount,
                'customer_name' => $tx->customer_name,
            ];
        }

        return [
            'requests' => $requests,
            'request_count' => DB::table('stock_requests')->where('status', 'pending')->count(),
            'submitted_counts' => $submitted,
            'submitted_count' => DB::table('stock_counts')->where('status', 'submitted')->count(),
            'reviews' => $reviewRows,
        ];
    }

    /** @return array<string, mixed> */
    private static function staff(mixed $shopNames): array
    {
        $users = DB::table('users')->orderBy('role')->orderBy('name')->get();
        $lastLogin = DB::table('login_logs')
            ->selectRaw('user_id, MAX(created_at) AS at')
            ->groupBy('user_id')
            ->pluck('at', 'user_id')
            ->all();

        $rows = [];
        foreach ($users as $user) {
            $rows[] = [
                'id' => (string) $user->id,
                'name' => (string) $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'role' => (string) $user->role,
                'shop_id' => $user->shop_id,
                'shop_name' => $user->shop_id === null ? null : ($shopNames[$user->shop_id] ?? '—'),
                'active' => (bool) $user->active,
                'perm_approve_requests' => (bool) $user->perm_approve_requests,
                'perm_adjust_stock' => (bool) $user->perm_adjust_stock,
                'perm_reconcile' => (bool) $user->perm_reconcile,
                'last_login' => $lastLogin[$user->id] ?? null,
            ];
        }

        return [
            'rows' => $rows,
            'owners' => count(array_filter($rows, static fn (array $r): bool => $r['role'] === 'owner')),
            'attendants' => count(array_filter($rows, static fn (array $r): bool => $r['role'] === 'attendant')),
            'deactivated' => count(array_filter($rows, static fn (array $r): bool => ! $r['active'])),
        ];
    }

    /** @return array<string, mixed> */
    private static function system(): array
    {
        $tables = [
            'shops', 'users', 'phone_models', 'transactions', 'transaction_items',
            'stock_adjustments', 'stock_requests', 'stock_logs', 'transaction_events',
            'login_logs', 'stock_counts', 'stock_count_items', 'daily_closes', 'swapped_phones',
        ];
        $counts = [];
        foreach ($tables as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return ['tables' => $counts];
    }
}
