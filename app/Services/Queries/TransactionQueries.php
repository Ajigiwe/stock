<?php

namespace App\Services\Queries;

use App\Models\User;
use App\Support\DataCache;
use Illuminate\Support\Facades\DB;

/**
 * Port of getTransaction()/getTransactionEvents()/getSwappedPhones() as the
 * receipt page (src/app/(app)/transactions/[id]/page.tsx) combined them.
 *
 * Visibility mirrors the original RLS-scoped reads: an owner sees any
 * transaction, an attendant only one from `$actor->shop_id`. A missing id — or
 * a foreign one, which RLS simply made invisible — comes back as an empty
 * payload so the caller renders the original's "Transaction not found" screen
 * instead of leaking that another shop's transaction exists.
 *
 * Payload keys are the page's locals: `tx`, `shop`, `swaps`, `events`
 * (`transaction` is an alias for `tx`), plus `receipt_no` and `is_owner`.
 */
final class TransactionQueries
{
    private function __construct()
    {
    }

    /**
     * Port of the /transactions/new page's getShops()+getStock() pair.
     *
     * Payload mirrors the props TransactionForm rendered: `shops`, `stock`,
     * `defaultShopId`, `isOwner`. Attendants only ever see their own shop's
     * rows (RLS-scoped reads); the owner sees every shop and the form filters
     * the flat `stock` list client-side by `shop_id`, exactly as before.
     *
     * `defaultShopId` is null only when a shopless attendant reaches the POS —
     * the original redirected those users to "/".
     *
     * @return array{shops: array<int, array<string, mixed>>, stock: array<int, array<string, mixed>>, defaultShopId: string|null, isOwner: bool}
     */
    public static function create(?string $shopId, User $actor): array
    {
        $isOwner = $actor->isOwner();
        $own = $actor->shop_id ?? 'none';
        $role = $isOwner ? 'owner' : "attendant:{$own}";
        $requested = $shopId ?? '-';

        return DataCache::remember(
            "pos:{$role}:{$requested}",
            fn (): array => self::posPayload($shopId, $isOwner, $actor->shop_id)
        );
    }

    /** @return array{shops: array<int, array<string, mixed>>, stock: array<int, array<string, mixed>>, defaultShopId: string|null, isOwner: bool} */
    private static function posPayload(?string $shopId, bool $isOwner, ?string $actorShop): array
    {
        $shopsQuery = DB::table('shops')->orderBy('name');
        $stockQuery = DB::table('phone_models')
            ->select(['id', 'shop_id', 'model_name', 'condition', 'sim_type', 'color', 'cost_price', 'sale_price', 'available', 'low_stock_threshold'])
            ->orderBy('model_name')
            ->orderBy('condition');

        if (! $isOwner) {
            // A shopless attendant has nothing to sell.
            if ($actorShop === null) {
                return ['shops' => [], 'stock' => [], 'defaultShopId' => null, 'isOwner' => false];
            }
            $shopsQuery->where('id', $actorShop);
            $stockQuery->where('shop_id', $actorShop);
        }

        $shops = $shopsQuery->get()->map(static fn (object $row): array => [
            'id' => (string) $row->id,
            'name' => (string) $row->name,
            'location' => $row->location === null ? null : (string) $row->location,
            'phone' => $row->phone === null ? null : (string) $row->phone,
        ])->all();

        $stock = $stockQuery->get()->map(static fn (object $row): array => [
            'id' => (string) $row->id,
            'shop_id' => (string) $row->shop_id,
            'model_name' => (string) $row->model_name,
            'condition' => (string) $row->condition,
            'sim_type' => (string) ($row->sim_type ?? ''),
            'color' => (string) ($row->color ?? ''),
            'cost_price' => $row->cost_price === null ? null : (float) $row->cost_price,
            'sale_price' => $row->sale_price === null ? null : (float) $row->sale_price,
            'available' => (int) $row->available,
            'low_stock_threshold' => $row->low_stock_threshold === null ? null : (int) $row->low_stock_threshold,
        ])->all();

        $defaultShopId = $isOwner
            ? ($shopId !== null && collect($shops)->contains('id', $shopId)
                ? $shopId
                : ($shops[0]['id'] ?? null))
            : $actorShop;

        return [
            'shops' => $shops,
            'stock' => $stock,
            'defaultShopId' => $defaultShopId,
            'isOwner' => $isOwner,
        ];
    }

    /** @return array<string, mixed> empty when the transaction is missing or not visible */
    public static function show(string $txId, User $actor): array
    {
        $isOwner = $actor->isOwner();
        $own = $actor->shop_id ?? 'none';
        $role = $isOwner ? 'owner' : "attendant:{$own}";

        return DataCache::remember(
            "transaction:{$txId}:{$role}",
            fn (): array => self::load($txId, $isOwner, $actor->shop_id)
        );
    }

    /** @return array<string, mixed> */
    private static function load(string $txId, bool $isOwner, ?string $actorShop): array
    {
        $row = DB::table('transactions')->where('id', $txId)->first();
        if ($row === null) {
            return [];
        }
        if (! $isOwner && ($actorShop === null || $row->shop_id !== $actorShop)) {
            return [];
        }

        $tx = QuerySupport::hydrateTransactions([$row])[0];

        $shopRow = DB::table('shops')->where('id', $row->shop_id)->first();
        $shop = $shopRow === null ? null : [
            'name' => (string) $shopRow->name,
            'location' => $shopRow->location === null ? null : (string) $shopRow->location,
            'phone' => $shopRow->phone === null ? null : (string) $shopRow->phone,
        ];

        return [
            'tx' => $tx,
            'transaction' => $tx,
            'shop' => $shop,
            'swaps' => QuerySupport::swappedPhones(['transactionId' => $txId]),
            'events' => $isOwner ? self::events($txId) : [],
            'receipt_no' => strtoupper(substr($txId, -8)),
            'is_owner' => $isOwner,
        ];
    }

    /** Port of getTransactionEvents(): audit trail, oldest first, actor named. */
    private static function events(string $txId): array
    {
        $events = DB::table('transaction_events')
            ->where('transaction_id', $txId)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
        if ($events->isEmpty()) {
            return [];
        }

        $actorIds = $events->pluck('actor_id')->unique()->values()->all();
        $actorNames = DB::table('users')->whereIn('id', $actorIds)->pluck('name', 'id')->all();

        return $events->map(function (object $row) use ($actorNames): array {
            return [
                'id' => (string) $row->id,
                'transaction_id' => (string) $row->transaction_id,
                'actor_id' => (string) $row->actor_id,
                'action' => (string) $row->action,
                'details' => $row->details === null ? null : json_decode($row->details, true),
                'created_at' => (string) $row->created_at,
                'actor_name' => $actorNames[$row->actor_id] ?? null,
            ];
        })->all();
    }
}
