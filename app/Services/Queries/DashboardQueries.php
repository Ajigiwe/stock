<?php

namespace App\Services\Queries;

use App\Models\User;
use App\Support\DataCache;
use App\Support\Format;

/**
 * Port of getDashboardData() (src/lib/data.ts) — the unified owner/attendant
 * dashboard read: period buckets, per-shop summaries, daily series, recent
 * transactions, pending requests and the owner's review queue.
 *
 * Visibility mirrors the original exactly: the owner sees every shop (or the
 * requested shop when the filter matches a real one) and the shop list;
 * an attendant is pinned to `$actor->shop_id`, never sees the shop list, and
 * an attendant without a shop gets an empty payload — same as the original
 * branch that skipped every read when `profile.shop_id` was missing.
 */
final class DashboardQueries
{
    private function __construct()
    {
    }

    /** @return array<string, mixed> shaped like the DashboardData type */
    public static function index(?string $period, ?string $shopId, User $actor): array
    {
        $period = in_array($period, ['7d', '30d'], true) ? $period : 'today';
        $today = Format::today();
        $from = match ($period) {
            '7d' => QuerySupport::addDays($today, -6),
            '30d' => QuerySupport::addDays($today, -29),
            default => $today,
        };

        if ($actor->isAdmin()) {
            $scope = ($shopId === null || $shopId === '') ? '-' : $shopId;

            return DataCache::remember(
                "dashboard:owner:{$scope}:{$period}:{$today}",
                fn (): array => self::ownerBody($period, $from, $today, $shopId)
            );
        }

        $own = $actor->shop_id ?? 'none';

        return DataCache::remember(
            "dashboard:attendant:{$own}:{$period}:{$today}",
            fn (): array => self::attendantBody($period, $from, $today, $actor->shop_id)
        );
    }

    /**
     * Owner path — port of the `role === "owner"` branch: service-role reads
     * over every (or the filtered) shop, plus the shop list.
     *
     * @return array<string, mixed>
     */
    private static function ownerBody(string $period, string $from, string $to, ?string $shopId): array
    {
        $shops = QuerySupport::shops();

        $effective = null;
        if ($shopId !== null && $shopId !== '') {
            foreach ($shops as $shop) {
                if ($shop['id'] === $shopId) {
                    $effective = $shopId;
                    break;
                }
            }
        }

        $summaries = [];
        foreach ($shops as $candidate) {
            if ($effective !== null && $candidate['id'] !== $effective) {
                continue;
            }
            $summary = QuerySupport::shopSummary($candidate['id'], $from, $to);
            if ($summary !== null) {
                $summaries[] = $summary;
            }
        }

        $shop = null;
        if ($effective !== null) {
            foreach ($shops as $candidate) {
                if ($candidate['id'] === $effective) {
                    $shop = $candidate;
                    break;
                }
            }
        }

        $scoped = $effective === null ? [] : ['shopId' => $effective];

        return [
            'role' => 'owner',
            'scope' => $effective === null ? 'all' : 'shop',
            'period' => $period,
            'shop' => $shop,
            'shops' => $shops,
            'summaries' => $summaries,
            'series' => QuerySupport::buildSeries(
                QuerySupport::transactions($scoped + ['from' => $from, 'to' => $to]),
                $from,
                $to
            ),
            'recent' => QuerySupport::transactions($scoped + ['limit' => 10]),
            'pending' => QuerySupport::stockRequests($effective, 'pending', 100),
            'reviewTransactions' => QuerySupport::transactions($scoped + [
                'status' => 'pending_review',
                'limit' => 100,
            ]),
            'totals' => QuerySupport::aggregateTotals($summaries),
        ];
    }

    /**
     * Attendant path — port of the fallback branch, which used the RLS-scoped
     * reads (`getShopSummary`, `getTransactions`, `getStockRequests`) against
     * the attendant's own shop and returned no shop list at all.
     *
     * @return array<string, mixed>
     */
    private static function attendantBody(string $period, string $from, string $to, ?string $shopId): array
    {
        $summaries = [];
        $shop = null;
        $recent = [];
        $pending = [];
        $review = [];
        $series = [];

        if ($shopId !== null && $shopId !== '') {
            $summary = QuerySupport::shopSummary($shopId, $from, $to);
            if ($summary !== null) {
                $summaries[] = $summary;
                $shop = $summary['shop'];
                $recent = QuerySupport::transactions(['shopId' => $shopId, 'limit' => 10]);
                $pending = QuerySupport::stockRequests($shopId, 'pending', 100);
                $review = QuerySupport::transactions([
                    'shopId' => $shopId,
                    'status' => 'pending_review',
                    'limit' => 100,
                ]);
                $series = QuerySupport::buildSeries(
                    QuerySupport::transactions(['shopId' => $shopId, 'from' => $from, 'to' => $to]),
                    $from,
                    $to
                );
            }
        }

        return [
            'role' => 'attendant',
            'scope' => 'shop',
            'period' => $period,
            'shop' => $shop,
            'shops' => [],
            'summaries' => $summaries,
            'series' => $series,
            'recent' => $recent,
            'pending' => $pending,
            'reviewTransactions' => $review,
            'totals' => QuerySupport::aggregateTotals($summaries),
        ];
    }
}
