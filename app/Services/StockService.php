<?php

namespace App\Services;

use App\Models\User;
use App\Support\DataCache;
use App\Support\Input;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ports of createModel(), updateModel(), adjustStock(), bulkAdjustStock() and
 * bulkCreateModels(), including the adjust_stock / bulk_adjust_stock RPC bodies
 * (owner-direct paths) and the stock_requests rows attendants file instead.
 */
class StockService
{
    /** @var list<string> */
    private const CONDITIONS = ['new', 'used'];

    /**
     * SIM variants a model can carry ('' = unspecified, for stock recorded
     * before variants existed). Views render SIM_TYPES as the dropdown and
     * simLabel() for badges; the keys are what the database stores.
     *
     * @var array<string, string>
     */
    public const SIM_TYPES = [
        'esim' => 'eSIM',
        'esim_locked' => 'eSIM Locked',
        'esim_unlocked' => 'eSIM Unlocked',
        'physical_sim' => 'Physical SIM',
        'physical_sim_locked' => 'Physical SIM Locked',
        'physical_sim_unlocked' => 'Physical SIM Unlocked',
    ];

    public const MAX_BULK_ROWS = 500;

    /**
     * Normalise a SIM input (`Physical SIM`, `physical-sim` and `physical_sim`
     * all land on the key); unknown values come back as '' so callers can
     * tell "blank" from "invalid" by comparing against the raw input.
     */
    public static function simType(mixed $raw): string
    {
        if (! is_string($raw)) {
            return '';
        }

        $value = str_replace([' ', '-'], '_', strtolower(trim($raw)));

        return isset(self::SIM_TYPES[$value]) ? $value : '';
    }

    /** Display label for a stored sim_type ('' renders nothing). */
    public static function simLabel(string $sim): string
    {
        return self::SIM_TYPES[$sim] ?? '';
    }

    /**
     * Gadget categories ('' normalises to 'phone', which is also what every
     * pre-category row backfills to). Views render CATEGORIES as the dropdown
     * and catLabel() for badges.
     *
     * @var array<string, string>
     */
    public const CATEGORIES = [
        'phone' => 'Phone',
        'tablet' => 'Tablet',
        'laptop' => 'Laptop',
        'audio' => 'Audio',
        'wearable' => 'Wearable',
        'accessory' => 'Accessory',
    ];

    /** Normalise a category input: blank means 'phone', anything unknown comes back as ''. */
    public static function category(mixed $raw): string
    {
        if (! is_string($raw) || trim($raw) === '') {
            return 'phone';
        }

        $value = str_replace([' ', '-'], '_', strtolower(trim($raw)));

        return isset(self::CATEGORIES[$value]) ? $value : '';
    }

    /** Display label for a stored category. */
    public static function catLabel(string $category): string
    {
        return self::CATEGORIES[$category] ?? self::CATEGORIES['phone'];
    }

    /**
     * Trimmed color name, at most 64 chars.
     *
     * @return array{0: bool, 1: string}
     */
    public static function color(mixed $raw): array
    {
        $value = Input::trimmed($raw);
        if (strlen($value) > 64) {
            return [false, 'Color is too long.'];
        }

        return [true, $value];
    }

    /**
     * Port of createModel(): the owner creates the model immediately, an
     * attendant files a create_model request for the owner to approve.
     *
     * `available` is never written — model_stock_normalize derives it from
     * opening_stock + bought_in.
     *
     * @param  array<string, mixed>  $input
     * @return array{ok: bool, error?: string, warnings?: array<int, string>}
     */
    public function create(array $input, User $actor): array
    {
        $me = $this->fresh($actor);
        if (! ($me instanceof User)) {
            return $me;
        }

        $shopId = $me->isAdmin() ? ($input['shopId'] ?? null) : $me->shop_id;
        if (! Input::isUuid($shopId)) {
            return ['ok' => false, 'error' => 'No shop selected.'];
        }

        $condition = $input['condition'] ?? null;
        if (! in_array($condition, self::CONDITIONS, true)) {
            return ['ok' => false, 'error' => 'Choose a valid condition.'];
        }

        $modelName = Input::trimmed($input['modelName'] ?? null);
        if ($modelName === '') {
            return ['ok' => false, 'error' => 'Model name is required.'];
        }
        if (strlen($modelName) > 120) {
            return ['ok' => false, 'error' => 'Model name is too long.'];
        }

        $simRaw = $input['simType'] ?? $input['sim_type'] ?? '';
        $sim = self::simType($simRaw);
        if (Input::trimmed($simRaw) !== '' && $sim === '') {
            return ['ok' => false, 'error' => 'Choose a valid SIM type.'];
        }
        [$ok, $color] = self::color($input['color'] ?? $input['colour'] ?? null);
        if (! $ok) {
            return ['ok' => false, 'error' => $color];
        }
        $categoryRaw = $input['category'] ?? null;
        $category = self::category($categoryRaw);
        if ($category === '') {
            return ['ok' => false, 'error' => 'Choose a valid category.'];
        }

        [$ok, $opening] = Input::count($input['openingStock'] ?? null, 'Opening stock', 0);
        if (! $ok) {
            return ['ok' => false, 'error' => $opening];
        }
        [$ok, $cost] = Input::money($input['costPrice'] ?? null, 'Cost price');
        if (! $ok) {
            return ['ok' => false, 'error' => $cost];
        }
        [$ok, $sale] = Input::money($input['salePrice'] ?? null, 'Sale price');
        if (! $ok) {
            return ['ok' => false, 'error' => $sale];
        }
        [$ok, $threshold] = Input::count($input['lowStockThreshold'] ?? null, 'Low-stock threshold', 5);
        if (! $ok) {
            return ['ok' => false, 'error' => $threshold];
        }

        if ($me->isAdmin()) {
            // Owner adds models immediately.
            try {
                $modelId = DB::transaction(function () use ($shopId, $modelName, $condition, $sim, $color, $category, $cost, $sale, $opening, $threshold): string {
                    $id = (string) Str::uuid();
                    DB::table('phone_models')->insert([
                        'id' => $id,
                        'shop_id' => $shopId,
                        'model_name' => $modelName,
                        'condition' => $condition,
                        'sim_type' => $sim,
                        'color' => $color,
                        'category' => $category,
                        'cost_price' => $cost,
                        'sale_price' => $sale,
                        'opening_stock' => $opening,
                        'bought_in' => 0,
                        'low_stock_threshold' => $threshold,
                    ]);

                    return $id;
                });
            } catch (QueryException|\PDOException $e) {
                return $this->dbError($e);
            }

            AuditLog::stock($me, 'create_model', $modelId, $modelName, $condition, ['opening_stock' => $opening]);
            DataCache::flush();

            return ['ok' => true];
        }

        // Attendant: submit for owner approval.
        $duplicate = DB::table('phone_models')
            ->where('shop_id', $shopId)
            ->where('model_name', $modelName)
            ->where('condition', $condition)
            ->where('sim_type', $sim)
            ->where('color', $color)
            ->where('category', $category)
            ->exists();
        if ($duplicate) {
            return ['ok' => false, 'error' => 'A model with this name and condition already exists in the shop.'];
        }

        try {
            DB::table('stock_requests')->insert([
                'id' => (string) Str::uuid(),
                'shop_id' => $shopId,
                'staff_id' => $me->id,
                'type' => 'create_model',
                'model_name' => $modelName,
                'condition' => $condition,
                'sim_type' => $sim,
                'color' => $color,
                'category' => $category,
                'cost_price' => $cost,
                'sale_price' => $sale,
                'low_stock_threshold' => $threshold,
                'opening_stock' => $opening,
            ]);
        } catch (QueryException|\PDOException $e) {
            return $this->dbError($e);
        }

        DataCache::flush();

        return ['ok' => true];
    }

    /**
     * Port of updateModel(). Stock fields (opening_stock / bought_in /
     * available) are intentionally not editable here — they only move through
     * transactions and stock adjustments.
     *
     * @param  array<string, mixed>  $input
     * @return array{ok: bool, error?: string}
     */
    public function update(string $id, array $input, User $actor): array
    {
        $me = $this->fresh($actor);
        if (! ($me instanceof User)) {
            return $me;
        }

        $shopId = $me->isAdmin() ? ($input['shopId'] ?? null) : $me->shop_id;
        if (! Input::isUuid($shopId)) {
            return ['ok' => false, 'error' => 'No shop selected.'];
        }
        if (! Input::isUuid($id)) {
            return ['ok' => false, 'error' => 'Invalid product.'];
        }

        if (! $me->isAdmin()) {
            return ['ok' => false, 'error' => 'Only the owner can edit products.'];
        }

        $condition = $input['condition'] ?? null;
        if (! in_array($condition, self::CONDITIONS, true)) {
            return ['ok' => false, 'error' => 'Choose a valid condition.'];
        }

        $modelName = Input::trimmed($input['modelName'] ?? null);
        if ($modelName === '') {
            return ['ok' => false, 'error' => 'Model name is required.'];
        }
        if (strlen($modelName) > 120) {
            return ['ok' => false, 'error' => 'Model name is too long.'];
        }

        [$ok, $cost] = Input::money($input['costPrice'] ?? null, 'Cost price');
        if (! $ok) {
            return ['ok' => false, 'error' => $cost];
        }
        [$ok, $sale] = Input::money($input['salePrice'] ?? null, 'Sale price');
        if (! $ok) {
            return ['ok' => false, 'error' => $sale];
        }
        [$ok, $threshold] = Input::count($input['lowStockThreshold'] ?? null, 'Low-stock threshold', 5);
        if (! $ok) {
            return ['ok' => false, 'error' => $threshold];
        }

        $simRaw = $input['simType'] ?? $input['sim_type'] ?? '';
        $sim = self::simType($simRaw);
        if (Input::trimmed($simRaw) !== '' && $sim === '') {
            return ['ok' => false, 'error' => 'Choose a valid SIM type.'];
        }
        [$ok, $color] = self::color($input['color'] ?? $input['colour'] ?? null);
        if (! $ok) {
            return ['ok' => false, 'error' => $color];
        }
        $categoryRaw = $input['category'] ?? null;
        $category = self::category($categoryRaw);
        if ($category === '') {
            return ['ok' => false, 'error' => 'Choose a valid category.'];
        }

        $before = DB::table('phone_models')->where('id', $id)->where('shop_id', $shopId)->first();
        if ($before === null) {
            return ['ok' => false, 'error' => 'Product not found in this shop.'];
        }

        try {
            DB::table('phone_models')->where('id', $id)->where('shop_id', $shopId)->update([
                'model_name' => $modelName,
                'condition' => $condition,
                'sim_type' => $sim,
                'color' => $color,
                'category' => $category,
                'cost_price' => $cost,
                'sale_price' => $sale,
                'low_stock_threshold' => $threshold,
            ]);
        } catch (QueryException|\PDOException $e) {
            return $this->dbError($e);
        }

        AuditLog::stock($me, 'update_model', $id, $modelName, $condition, [
            'before' => [
                'model_name' => $before->model_name,
                'condition' => $before->condition,
                'sim_type' => $before->sim_type ?? '',
                'color' => $before->color ?? '',
                'category' => $before->category ?? 'phone',
                'cost_price' => $before->cost_price === null ? null : (float) $before->cost_price,
                'sale_price' => $before->sale_price === null ? null : (float) $before->sale_price,
                'low_stock_threshold' => (int) $before->low_stock_threshold,
            ],
            'after' => [
                'model_name' => $modelName,
                'condition' => $condition,
                'sim_type' => $sim,
                'color' => $color,
                'category' => $category,
                'cost_price' => $cost,
                'sale_price' => $sale,
                'low_stock_threshold' => $threshold,
            ],
        ]);

        DataCache::flush();

        return ['ok' => true];
    }

    /**
     * Port of adjustStock(): the owner adjusts stock immediately (through the
     * stock_adjustments row the trigger applies); an attendant with the
     * direct-adjust grant does the same, anyone else files a request.
     *
     * @param  array<string, mixed>  $input
     * @return array{ok: bool, error?: string}
     */
    public function adjust(array $input, User $actor): array
    {
        $me = $this->fresh($actor);
        if (! ($me instanceof User)) {
            return $me;
        }

        $rawDelta = $input['delta'] ?? null;
        $numeric = is_numeric($rawDelta) ? (float) $rawDelta : null;
        if ($numeric === null || floor($numeric) != $numeric || $numeric === 0.0) {
            return ['ok' => false, 'error' => 'Enter a non-zero whole quantity.'];
        }
        if (abs($numeric) > Input::MAX_QTY) {
            return ['ok' => false, 'error' => 'That quantity is too large.'];
        }
        $delta = (int) $numeric;

        $shopId = $me->isAdmin() ? ($input['shopId'] ?? null) : $me->shop_id;
        if (! Input::isUuid($shopId)) {
            return ['ok' => false, 'error' => 'No shop selected.'];
        }
        if (! Input::isUuid($input['phoneModelId'] ?? null)) {
            return ['ok' => false, 'error' => 'Invalid product.'];
        }
        $phoneModelId = $input['phoneModelId'];

        $reason = Input::trimmed($input['reason'] ?? null);
        $reason = $reason === '' ? null : $reason;

        // The model must belong to this shop on both paths; failing here gives
        // a clearer message than a raised database exception.
        $model = DB::table('phone_models')
            ->where('id', $phoneModelId)
            ->where('shop_id', $shopId)
            ->first(['model_name', 'condition']);
        if ($model === null) {
            return ['ok' => false, 'error' => 'Product not found in this shop.'];
        }

        $type = $delta > 0 ? 'restock' : 'correction';

        // Owners — and attendants holding the direct-adjust grant — move
        // stock immediately; everyone else files a request. Either way the
        // shop stays locked to the caller's own on the attendant path.
        if ($me->isAdmin() || $me->perm_adjust_stock) {
            try {
                DB::table('stock_adjustments')->insert([
                    'id' => (string) Str::uuid(),
                    'shop_id' => $shopId,
                    'phone_model_id' => $phoneModelId,
                    'staff_id' => $me->id,
                    'type' => $type,
                    'delta' => $delta,
                    'reason' => $reason,
                ]);
            } catch (QueryException|\PDOException $e) {
                return $this->dbError($e);
            }

            AuditLog::stock($me, 'adjust_stock', $phoneModelId, $model->model_name, $model->condition, [
                'delta' => $delta,
                'type' => $type,
                'reason' => $reason,
            ]);

            DataCache::flush();

            return ['ok' => true];
        }

        // Attendant: submit for owner approval.
        try {
            DB::table('stock_requests')->insert([
                'id' => (string) Str::uuid(),
                'shop_id' => $shopId,
                'staff_id' => $me->id,
                'type' => 'adjust_stock',
                'phone_model_id' => $phoneModelId,
                'delta' => $delta,
                'reason' => $reason,
            ]);
        } catch (QueryException|\PDOException $e) {
            return $this->dbError($e);
        }

        DataCache::flush();

        return ['ok' => true];
    }

    /**
     * Port of transferStock(): move units of one model from one shop to
     * another. Owners and superadmins only — attendants keep filing
     * requests. The destination row is created (opening 0, prices copied)
     * when the variant does not exist there yet; both legs go through
     * stock_adjustments so the triggers move `available` on each side.
     *
     * @param  array<string, mixed>  $input
     * @return array{ok: bool, error?: string, moved?: int, shopName?: string}
     */
    public function transfer(array $input, User $actor): array
    {
        $me = $this->fresh($actor);
        if (! ($me instanceof User)) {
            return $me;
        }
        if (! $me->isAdmin()) {
            return ['ok' => false, 'error' => 'Only the owner can move stock between shops.'];
        }

        $rawQty = $input['qty'] ?? null;
        $numeric = is_numeric($rawQty) ? (float) $rawQty : null;
        if ($numeric === null || floor($numeric) != $numeric || $numeric < 1) {
            return ['ok' => false, 'error' => 'Enter a quantity of at least 1.'];
        }
        if ($numeric > Input::MAX_QTY) {
            return ['ok' => false, 'error' => 'That quantity is too large.'];
        }
        $qty = (int) $numeric;

        $fromShopId = $input['shopId'] ?? null;
        $toShopId = $input['toShopId'] ?? null;
        if (! Input::isUuid($fromShopId) || ! Input::isUuid($toShopId)) {
            return ['ok' => false, 'error' => 'Select a valid shop.'];
        }
        if ($fromShopId === $toShopId) {
            return ['ok' => false, 'error' => 'Choose a different shop to move stock to.'];
        }
        if (! Input::isUuid($input['phoneModelId'] ?? null)) {
            return ['ok' => false, 'error' => 'Invalid product.'];
        }

        $destShop = DB::table('shops')->where('id', $toShopId)->first(['id', 'name']);
        if ($destShop === null) {
            return ['ok' => false, 'error' => 'Select a valid shop.'];
        }

        $reason = Input::trimmed($input['reason'] ?? null);
        $reason = $reason === '' ? null : $reason;

        try {
            $moved = DB::transaction(function () use ($fromShopId, $toShopId, $destShop, $input, $qty, $reason, $me): array {
                $source = DB::table('phone_models')
                    ->where('id', $input['phoneModelId'])
                    ->where('shop_id', $fromShopId)
                    ->lockForUpdate()
                    ->first();
                if ($source === null) {
                    return ['ok' => false, 'error' => 'Product not found in this shop.'];
                }
                if ((int) $source->available < $qty) {
                    return ['ok' => false, 'error' => 'Only '.(int) $source->available.' available to move.'];
                }

                $dest = DB::table('phone_models')
                    ->where('shop_id', $toShopId)
                    ->where('model_name', $source->model_name)
                    ->where('condition', $source->condition)
                    ->where('sim_type', $source->sim_type ?? '')
                    ->where('color', $source->color ?? '')
                    ->where('category', $source->category ?? 'phone')
                    ->lockForUpdate()
                    ->first();
                if ($dest === null) {
                    $destId = (string) Str::uuid();
                    DB::table('phone_models')->insert([
                        'id' => $destId,
                        'shop_id' => $toShopId,
                        'model_name' => $source->model_name,
                        'condition' => $source->condition,
                        'sim_type' => $source->sim_type ?? '',
                        'color' => $source->color ?? '',
                        'category' => $source->category ?? 'phone',
                        'cost_price' => $source->cost_price,
                        'sale_price' => $source->sale_price,
                        'opening_stock' => 0,
                        'bought_in' => 0,
                        'low_stock_threshold' => $source->low_stock_threshold ?? 5,
                    ]);
                    $dest = (object) ['id' => $destId];
                }

                DB::table('stock_adjustments')->insert([
                    [
                        'id' => (string) Str::uuid(),
                        'shop_id' => $fromShopId,
                        'phone_model_id' => $source->id,
                        'staff_id' => $me->id,
                        'type' => 'correction',
                        'delta' => -$qty,
                        'reason' => $reason ?? 'Transfer to '.$destShop->name,
                    ],
                    [
                        'id' => (string) Str::uuid(),
                        'shop_id' => $toShopId,
                        'phone_model_id' => $dest->id,
                        'staff_id' => $me->id,
                        'type' => 'restock',
                        'delta' => $qty,
                        'reason' => $reason ?? 'Transfer from '.(DB::table('shops')->where('id', $fromShopId)->value('name') ?? 'another shop'),
                    ],
                ]);

                AuditLog::stock($me, 'adjust_stock', $source->id, $source->model_name, $source->condition, [
                    'delta' => -$qty,
                    'type' => 'correction',
                    'reason' => $reason,
                    'transfer_to' => $toShopId,
                ]);
                AuditLog::stock($me, 'adjust_stock', $dest->id, $source->model_name, $source->condition, [
                    'delta' => $qty,
                    'type' => 'restock',
                    'reason' => $reason,
                    'transfer_from' => $fromShopId,
                ]);

                return ['ok' => true, 'moved' => $qty, 'shopName' => $destShop->name];
            });
        } catch (QueryException|\PDOException $e) {
            return $this->dbError($e);
        }

        if (! ($moved['ok'] ?? false)) {
            return $moved;
        }

        DataCache::flush();

        return $moved;
    }

    /**
     * Port of bulkAdjustStock(): set target quantities for many models. The
     * owner path locks every model first (one atomic batch, like the
     * bulk_adjust_stock RPC); attendants file one request per changed model.
     *
     * @param  array<string, mixed>  $input
     * @return array{ok: bool, error?: string}
     */
    public function bulkAdjust(array $input, User $actor): array
    {
        $me = $this->fresh($actor);
        if (! ($me instanceof User)) {
            return $me;
        }

        $shopId = $me->isAdmin() ? ($input['shopId'] ?? null) : $me->shop_id;
        if (! Input::isUuid($shopId)) {
            return ['ok' => false, 'error' => 'No shop selected.'];
        }

        // Last target wins for a repeated model, so the request is unambiguous.
        $targets = [];
        foreach (is_array($input['items'] ?? null) ? $input['items'] : [] as $item) {
            if (! is_array($item) || ! Input::isUuid($item['modelId'] ?? null)) {
                continue;
            }
            [$ok, $target] = Input::count($item['targetQty'] ?? null, 'Quantity', 0);
            if (! $ok) {
                return ['ok' => false, 'error' => $target];
            }
            $targets[$item['modelId']] = $target;
        }

        if ($targets === []) {
            return ['ok' => false, 'error' => 'Nothing to change.'];
        }

        $reason = Input::trimmed($input['reason'] ?? null);
        $reason = $reason === '' ? null : $reason;

        $models = DB::table('phone_models')
            ->whereIn('id', array_keys($targets))
            ->where('shop_id', $shopId)
            ->get(['id', 'available', 'model_name', 'condition'])
            ->keyBy('id');
        if ($models->isEmpty()) {
            return ['ok' => false, 'error' => 'No matching products in this shop.'];
        }

        // Only models whose target differs from what we last read; the lock
        // re-reads `available`, so a sale landing in between still produces
        // the right absolute quantity.
        $items = [];
        foreach ($targets as $modelId => $target) {
            $model = $models->get($modelId);
            if ($model === null || (int) $model->available === $target) {
                continue;
            }
            $items[$modelId] = $target;
        }

        if ($items === []) {
            return ['ok' => true];
        }

        // Same direct path as adjust(): owners plus granted attendants.
        if ($me->isAdmin() || $me->perm_adjust_stock) {
            try {
                $result = DB::transaction(function () use ($shopId, $items, $me, $reason): array {
                    $ids = array_keys($items);
                    sort($ids);

                    $rows = DB::table('phone_models')
                        ->whereIn('id', $ids)
                        ->where('shop_id', $shopId)
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get()
                        ->keyBy('id');

                    foreach ($ids as $modelId) {
                        $row = $rows->get($modelId);
                        if ($row === null) {
                            return ['ok' => false, 'error' => 'Phone model does not belong to this shop'];
                        }

                        $delta = $items[$modelId] - (int) $row->available;
                        if ($delta === 0) {
                            continue;
                        }

                        DB::table('stock_adjustments')->insert([
                            'id' => (string) Str::uuid(),
                            'shop_id' => $shopId,
                            'phone_model_id' => $modelId,
                            'staff_id' => $me->id,
                            'type' => $delta > 0 ? 'restock' : 'correction',
                            'delta' => $delta,
                            'reason' => $reason,
                        ]);
                    }

                    return ['ok' => true];
                });
            } catch (QueryException|\PDOException $e) {
                return $this->dbError($e);
            }

            if (! $result['ok']) {
                return $result;
            }

            foreach ($items as $modelId => $target) {
                $model = $models->get($modelId);
                AuditLog::stock($me, 'adjust_stock', $modelId, $model?->model_name, $model?->condition, [
                    'target_qty' => $target,
                    'previous_available' => $model === null ? null : (int) $model->available,
                    'reason' => $reason,
                ]);
            }

            DataCache::flush();

            return ['ok' => true];
        }

        // Attendant: submit each change for owner approval.
        $rows = [];
        foreach ($items as $modelId => $target) {
            $model = $models->get($modelId);
            if ($model === null) {
                continue;
            }
            $delta = $target - (int) $model->available;
            if ($delta === 0) {
                continue;
            }
            if ((int) $model->available + $delta < 0) {
                return [
                    'ok' => false,
                    'error' => 'Cannot reduce '.$model->model_name.' below 0 (only '.$model->available.' available).',
                ];
            }
            $rows[] = [
                'id' => (string) Str::uuid(),
                'shop_id' => $shopId,
                'staff_id' => $me->id,
                'type' => 'adjust_stock',
                'phone_model_id' => $modelId,
                'delta' => $delta,
                'reason' => $reason,
            ];
        }

        if ($rows === []) {
            return ['ok' => true];
        }

        try {
            DB::table('stock_requests')->insert($rows);
        } catch (QueryException|\PDOException $e) {
            return $this->dbError($e);
        }

        DataCache::flush();

        return ['ok' => true];
    }

    /**
     * Port of bulkCreateModels() (owner-only settings import). Rows that are
     * already in the shop, duplicated in the file, or fail validation are
     * skipped, reported back through `warnings`.
     *
     * @param  array<int|string, mixed>  $rows
     * @return array{ok: bool, error?: string, warnings?: array<int, string>}
     */
    public function bulkCreate(array $rows, User $actor): array
    {
        $me = $this->fresh($actor);
        if (! ($me instanceof User)) {
            return $me;
        }
        if (! $me->isAdmin()) {
            return ['ok' => false, 'error' => 'Only the owner can bulk add devices.'];
        }

        // The original took (shopId, rows); this signature only carries $rows,
        // so the shop travels with them — either as ['shopId' => …, 'rows' => […]]
        // or as a shopId on each row.
        $shopId = $rows['shopId'] ?? null;
        $list = is_array($rows['rows'] ?? null) ? $rows['rows'] : $rows;
        unset($list['shopId'], $list['rows']);

        if ($shopId === null || $shopId === '') {
            foreach ($list as $row) {
                if (is_array($row) && ($row['shopId'] ?? null) !== null && $row['shopId'] !== '') {
                    $shopId = $row['shopId'];

                    break;
                }
            }
        }

        if ($shopId === null || $shopId === '') {
            return ['ok' => false, 'error' => 'Select a shop.'];
        }
        if (! Input::isUuid($shopId)) {
            return ['ok' => false, 'error' => 'Invalid shop.'];
        }
        if ($list === []) {
            return ['ok' => false, 'error' => 'No rows to import.'];
        }
        if (count($list) > self::MAX_BULK_ROWS) {
            return ['ok' => false, 'error' => 'Import at most '.self::MAX_BULK_ROWS.' rows at a time.'];
        }

        // Skip models that already exist for this shop (same name + condition + variant + category).
        $existingKeys = [];
        foreach (DB::table('phone_models')->where('shop_id', $shopId)->get(['model_name', 'condition', 'sim_type', 'color', 'category']) as $model) {
            $existingKeys[$model->model_name.'|'.$model->condition.'|'.($model->sim_type ?? '').'|'.($model->color ?? '').'|'.($model->category ?? 'phone')] = true;
        }

        $toInsert = [];
        $seen = [];
        $skipped = [];

        foreach ($list as $row) {
            if (! is_array($row)) {
                $skipped[] = ['name' => '(empty)', 'reason' => 'Missing model name'];

                continue;
            }

            $name = Input::trimmed($row['model_name'] ?? null);
            $condition = ($row['condition'] ?? null) === 'used' ? 'used' : 'new';
            if ($name === '') {
                $skipped[] = ['name' => '(empty)', 'reason' => 'Missing model name'];

                continue;
            }

            $simRaw = $row['sim_type'] ?? $row['simType'] ?? $row['sim'] ?? '';
            $sim = self::simType($simRaw);
            if (Input::trimmed($simRaw) !== '' && $sim === '') {
                $skipped[] = ['name' => $name, 'reason' => 'Invalid SIM type'];

                continue;
            }
            [$ok, $color] = self::color($row['color'] ?? $row['colour'] ?? null);
            if (! $ok) {
                $skipped[] = ['name' => $name, 'reason' => $color];

                continue;
            }
            $categoryRaw = $row['category'] ?? null;
            $category = self::category($categoryRaw);
            if ($category === '') {
                $skipped[] = ['name' => $name, 'reason' => 'Invalid category'];

                continue;
            }

            $key = $name.'|'.$condition.'|'.$sim.'|'.$color.'|'.$category;
            if (isset($existingKeys[$key])) {
                $skipped[] = ['name' => $name, 'reason' => 'Already exists in this shop'];

                continue;
            }
            if (isset($seen[$key])) {
                $skipped[] = ['name' => $name, 'reason' => 'Duplicate within the import file'];

                continue;
            }
            $seen[$key] = true;

            [$ok, $opening] = Input::count($row['opening_stock'] ?? '0', 'Opening stock', 0);
            if (! $ok) {
                $skipped[] = ['name' => $name, 'reason' => $opening];

                continue;
            }
            [$ok, $cost] = Input::money($row['cost_price'] ?? null, 'Cost price');
            if (! $ok) {
                $skipped[] = ['name' => $name, 'reason' => $cost];

                continue;
            }
            [$ok, $sale] = Input::money($row['sale_price'] ?? null, 'Sale price');
            if (! $ok) {
                $skipped[] = ['name' => $name, 'reason' => $sale];

                continue;
            }

            // JS `r.low_stock_threshold || "5"`: any falsy value falls back.
            $thresholdRaw = $row['low_stock_threshold'] ?? null;
            if ($thresholdRaw === null || $thresholdRaw === '' || $thresholdRaw === false
                || $thresholdRaw === 0 || $thresholdRaw === 0.0) {
                $thresholdRaw = '5';
            }
            [$ok, $threshold] = Input::count($thresholdRaw, 'Low-stock threshold', 5);
            if (! $ok) {
                $skipped[] = ['name' => $name, 'reason' => $threshold];

                continue;
            }

            $toInsert[] = [
                'id' => (string) Str::uuid(),
                'shop_id' => $shopId,
                'model_name' => $name,
                'condition' => $condition,
                'sim_type' => $sim,
                'color' => $color,
                'category' => $category,
                'cost_price' => $cost,
                'sale_price' => $sale,
                'opening_stock' => $opening,
                'bought_in' => 0,
                'low_stock_threshold' => $threshold,
            ];
        }

        if ($toInsert !== []) {
            try {
                DB::table('phone_models')->insert($toInsert);
            } catch (QueryException|\PDOException $e) {
                return $this->dbError($e);
            }
        }

        DataCache::flush();

        $warnings = [];
        foreach ($skipped as $skip) {
            $warnings[] = 'Skipped "'.$skip['name'].'": '.$skip['reason'];
        }

        return $warnings === [] ? ['ok' => true] : ['ok' => true, 'warnings' => $warnings];
    }

    /**
     * Re-read the caller's row so role and shop checks fail closed on fresh
     * data instead of trusting the passed-in model.
     *
     * @return User|array{ok: bool, error: string}
     */
    private function fresh(User $actor): User|array
    {
        $me = User::query()->whereKey($actor->getKey())->first();

        if ($me === null) {
            return ['ok' => false, 'error' => 'Not authenticated'];
        }

        if (! $me->active) {
            return ['ok' => false, 'error' => 'This account is deactivated'];
        }

        return $me;
    }

    /**
     * Turn a trigger/CHECK/unique failure into the same clean result shape
     * instead of a 500: the MariaDB SIGNAL text arrives in errorInfo[2].
     *
     * @return array{ok: bool, error: string}
     */
    private function dbError(\Throwable $e): array
    {
        $info = $e->errorInfo ?? null;

        if (is_array($info) && is_string($info[2] ?? null) && $info[2] !== '') {
            $message = $info[2];
        } else {
            $message = Str::before($e->getMessage(), ' (Connection: ');
        }

        return ['ok' => false, 'error' => $message];
    }
}
