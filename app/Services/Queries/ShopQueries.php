<?php

namespace App\Services\Queries;

use App\Models\User;
use App\Support\DataCache;
use App\Support\Format;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Port of the shop page read in src/app/(app)/shops/[id]/page.tsx: the day's
 * summary, stock table, transactions, adjustments, pending requests, trade-ins,
 * daily close, physical counts and the end-of-day reconciliation for one shop.
 *
 * Fail-closed scoping: a non-owner may only read their own shop — a foreign or
 * unknown shop id throws 403 instead of leaking another shop's data, which is
 * what the page's `ownShop !== id → redirect` guard did. The data itself comes
 * from the original's service-role reads (`getCached*`), keyed to this shop.
 */
final class ShopQueries
{
    private function __construct()
    {
    }

    /** @return array<string, mixed> */
    public static function show(string $shopId, ?string $date, User $actor): array
    {
        $isOwner = $actor->isAdmin();
        if (! $isOwner && ($actor->shop_id === null || $actor->shop_id !== $shopId)) {
            throw new AccessDeniedHttpException;
        }

        // Same acceptance rule as the page: YYYY-MM-DD, otherwise today.
        $day = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date) === 1 ? (string) $date : Format::today();

        $body = DataCache::remember("shop:{$shopId}:{$day}", fn (): array => self::body($shopId, $day));
        if ($body === null) {
            throw new AccessDeniedHttpException;
        }

        $today = Format::today();
        $label = '';
        if ($day === $today) {
            $label = 'Today';
        } else {
            $ts = strtotime($day.' 00:00:00 UTC');
            $label = $ts === false ? $day : date('D M d Y', $ts);
        }

        return $body + [
            'date' => $day,
            'isToday' => $day === $today,
            'dateLabel' => $label,
            'isOwner' => $isOwner,
            'canEditStock' => $isOwner || (bool) $actor->perm_adjust_stock,
            'canApproveRequests' => $isOwner || (bool) $actor->perm_approve_requests,
            'canReconcile' => $isOwner || (bool) $actor->perm_reconcile,
        ];
    }

    /**
     * The cached read body — identical for an owner and for the attendant who
     * owns the shop, so the key carries no role.
     *
     * @return array<string, mixed>|null null when the shop row does not exist
     */
    private static function body(string $shopId, string $day): ?array
    {
        $summary = QuerySupport::shopSummary($shopId, $day, $day);
        if ($summary === null) {
            return null;
        }

        return [
            'summary' => $summary,
            'stock' => QuerySupport::stock($shopId),
            'transactions' => QuerySupport::transactions(['shopId' => $shopId, 'from' => $day, 'to' => $day]),
            'adjustments' => QuerySupport::adjustments($shopId, 200),
            'pendingRequests' => QuerySupport::stockRequests($shopId, 'pending', 100),
            'swappedPhones' => QuerySupport::swappedPhones(['shopId' => $shopId]),
            'dailyClose' => QuerySupport::dailyClose($shopId, $day),
            'latestStockCount' => QuerySupport::latestStockCount($shopId),
            'stockRecon' => QuerySupport::stockRecon($shopId, $day),
            'stockCountForDate' => QuerySupport::stockCountForDate($shopId, $day),
        ];
    }
}
