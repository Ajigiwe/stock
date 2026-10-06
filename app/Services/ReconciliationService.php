<?php

namespace App\Services;

use App\Models\User;
use App\Support\DataCache;
use App\Support\Input;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Str;

/**
 * Ports of submitDailyClose(), lockDailyClose(), submitStockCount(),
 * approveStockCount() and applyStockCountCorrection() — the RPC bodies from
 * supabase/migrations/0004_fraud_controls_and_reconciliation.sql, including
 * the close-window checks and the idempotent upsert on (shop_id, close_date).
 *
 * Stock counts are evidence only: they never mutate stock until an owner
 * applies the correction, which goes through stock_adjustments (and therefore
 * through the triggers).
 */
class ReconciliationService
{
    /**
     * @param  array<string, mixed>  $input
     * @return array{ok: bool, error?: string}
     */
    public function submitClose(array $input, User $actor): array
    {
        $me = $this->fresh($actor);
        if (! ($me instanceof User)) {
            return $me;
        }

        $shopId = $input['shopId'] ?? null;
        if (! Input::isUuid($shopId)) {
            return ['ok' => false, 'error' => 'Invalid shop.'];
        }

        [$ok, $cash] = Input::money($input['countedCash'] ?? null, 'Cash counted');
        if (! $ok) {
            return ['ok' => false, 'error' => $cash];
        }
        [$ok, $mobile] = Input::money($input['countedMobileMoney'] ?? null, 'Mobile money counted');
        if (! $ok) {
            return ['ok' => false, 'error' => $mobile];
        }
        [$ok, $other] = Input::money($input['countedOther'] ?? null, 'Other counted');
        if (! $ok) {
            return ['ok' => false, 'error' => $other];
        }

        if ($me->role !== User::ROLE_OWNER && $shopId !== $me->shop_id) {
            return ['ok' => false, 'error' => 'Not allowed for this shop'];
        }

        $closeDate = $input['date'] ?? null;
        $notes = Input::trimmed($input['notes'] ?? null);

        try {
            DB::transaction(function () use ($shopId, $closeDate, $cash, $mobile, $other, $notes, $me): array {
                // Expected takings: completed transactions dated that day.
                $expected = DB::table('transactions')
                    ->where('shop_id', $shopId)
                    ->where('status', 'completed')
                    ->whereRaw('`date` >= ?', [$closeDate])
                    ->whereRaw('`date` < DATE_ADD(?, INTERVAL 1 DAY)', [$closeDate])
                    ->selectRaw("COALESCE(SUM(CASE WHEN payment_method = 'cash' THEN amount ELSE 0 END), 0) AS cash")
                    ->selectRaw("COALESCE(SUM(CASE WHEN payment_method = 'mobile_money' THEN amount ELSE 0 END), 0) AS mobile")
                    ->selectRaw("COALESCE(SUM(CASE WHEN payment_method NOT IN ('cash','mobile_money') THEN amount ELSE 0 END), 0) AS other")
                    ->first();

                $existing = DB::table('daily_closes')
                    ->where('shop_id', $shopId)
                    ->where('close_date', $closeDate)
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null && $existing->status === 'locked') {
                    return ['ok' => false, 'error' => 'This daily close is already locked'];
                }

                $values = [
                    'expected_cash' => $expected->cash ?? 0,
                    'expected_mobile_money' => $expected->mobile ?? 0,
                    'expected_other' => $expected->other ?? 0,
                    'counted_cash' => $cash ?? 0,
                    'counted_mobile_money' => $mobile ?? 0,
                    'counted_other' => $other ?? 0,
                    'notes' => $notes,
                    'submitted_by' => $me->id,
                    'submitted_at' => gmdate('Y-m-d H:i:s'),
                ];

                if ($existing === null) {
                    // Idempotent on (shop_id, close_date): a second submission
                    // for the same day updates the open row.
                    DB::table('daily_closes')->insert($values + [
                        'id' => (string) Str::uuid(),
                        'shop_id' => $shopId,
                        'close_date' => $closeDate,
                        'status' => 'open',
                    ]);
                } else {
                    DB::table('daily_closes')->where('id', $existing->id)->update($values);
                }

                return ['ok' => true];
            });
        } catch (QueryException|\PDOException $e) {
            return $this->dbError($e);
        }

        DataCache::flush();

        return ['ok' => true];
    }

    /**
     * @return array{ok: bool, error?: string}
     */
    public function lockClose(string $id, User $actor): array
    {
        $me = $this->fresh($actor);
        if (! ($me instanceof User)) {
            return $me;
        }
        if ($me->role !== User::ROLE_OWNER) {
            return ['ok' => false, 'error' => 'Only the owner can lock a close.'];
        }
        if (! Input::isUuid($id)) {
            return ['ok' => false, 'error' => 'Invalid daily close.'];
        }

        try {
            $affected = DB::table('daily_closes')
                ->where('id', $id)
                ->where('status', 'open')
                ->update([
                    'status' => 'locked',
                    'locked_by' => $me->id,
                    'locked_at' => gmdate('Y-m-d H:i:s'),
                ]);
        } catch (QueryException|\PDOException $e) {
            return $this->dbError($e);
        }

        if ($affected === 0) {
            return ['ok' => false, 'error' => 'Open daily close not found'];
        }

        DataCache::flush();

        return ['ok' => true];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{ok: bool, error?: string}
     */
    public function submitCount(array $input, User $actor): array
    {
        $me = $this->fresh($actor);
        if (! ($me instanceof User)) {
            return $me;
        }

        $shopId = $input['shopId'] ?? null;
        if (! Input::isUuid($shopId)) {
            return ['ok' => false, 'error' => 'Invalid shop.'];
        }

        $items = [];
        foreach (is_array($input['items'] ?? null) ? $input['items'] : [] as $item) {
            if (! is_array($item) || ! Input::isUuid($item['modelId'] ?? null)) {
                continue;
            }
            $counted = $item['countedQty'] ?? null;
            if (! is_numeric($counted) || (float) $counted != floor((float) $counted) || (float) $counted < 0) {
                continue;
            }
            $items[] = ['modelId' => $item['modelId'], 'countedQty' => (int) $counted];
        }

        if ($items === []) {
            return ['ok' => false, 'error' => 'Enter at least one counted quantity.'];
        }

        if ($me->role !== User::ROLE_OWNER && $shopId !== $me->shop_id) {
            return ['ok' => false, 'error' => 'Not allowed for this shop'];
        }

        $countDate = $input['date'] ?? null;
        $notes = Input::trimmed($input['notes'] ?? null);

        try {
            $result = DB::transaction(function () use ($shopId, $countDate, $notes, $items, $me): array {
                $countId = (string) Str::uuid();

                // Validate every model first, then write both rows: a failure
                // mid-way must leave no half-submitted count (the RPC raised,
                // which rolled the whole thing back).
                $itemRows = [];
                foreach ($items as $item) {
                    $model = DB::table('phone_models')
                        ->where('id', $item['modelId'])
                        ->where('shop_id', $shopId)
                        ->first(['id', 'available']);
                    if ($model === null) {
                        return ['ok' => false, 'error' => 'Unknown phone model for this shop'];
                    }

                    $itemRows[] = [
                        'id' => (string) Str::uuid(),
                        'count_id' => $countId,
                        'phone_model_id' => $model->id,
                        'expected_qty' => (int) $model->available,
                        'counted_qty' => $item['countedQty'],
                    ];
                }

                DB::table('stock_counts')->insert([
                    'id' => $countId,
                    'shop_id' => $shopId,
                    'count_date' => $countDate,
                    'submitted_by' => $me->id,
                    'notes' => $notes,
                ]);
                DB::table('stock_count_items')->insert($itemRows);

                return ['ok' => true];
            });
        } catch (QueryException|\PDOException $e) {
            return $this->dbError($e);
        }

        if (! $result['ok']) {
            return $result;
        }

        DataCache::flush();

        return $result;
    }

    /**
     * @return array{ok: bool, error?: string}
     */
    public function approveCount(string $id, User $actor): array
    {
        $me = $this->fresh($actor);
        if (! ($me instanceof User)) {
            return $me;
        }
        if ($me->role !== User::ROLE_OWNER) {
            return ['ok' => false, 'error' => 'Only the owner can approve stock counts.'];
        }
        if (! Input::isUuid($id)) {
            return ['ok' => false, 'error' => 'Invalid stock count.'];
        }

        try {
            $result = DB::transaction(function () use ($id, $me): array {
                $count = DB::table('stock_counts')->where('id', $id)->lockForUpdate()->first();
                if ($count === null || $count->status !== 'submitted') {
                    return ['ok' => false, 'error' => 'Submitted stock count not found'];
                }

                DB::table('stock_counts')->where('id', $id)->update([
                    'status' => 'approved',
                    'approved_by' => $me->id,
                ]);

                return ['ok' => true];
            });
        } catch (QueryException|\PDOException $e) {
            return $this->dbError($e);
        }

        if (! $result['ok']) {
            return $result;
        }

        DataCache::flush();

        return $result;
    }

    /**
     * @return array{ok: bool, error?: string}
     */
    public function applyCount(string $id, User $actor): array
    {
        $me = $this->fresh($actor);
        if (! ($me instanceof User)) {
            return $me;
        }
        if ($me->role !== User::ROLE_OWNER) {
            return ['ok' => false, 'error' => 'Only the owner can apply stock corrections.'];
        }
        if (! Input::isUuid($id)) {
            return ['ok' => false, 'error' => 'Invalid stock count.'];
        }

        // Contract §5 exposes no reason parameter; like StaffService::resetPassword
        // the value is read from the incoming request, with an optional third
        // argument accepted for direct (non-HTTP) callers.
        $reason = Input::trimmed(
            func_num_args() > 2 ? func_get_arg(2) : Request::input('reason')
        );
        if ($reason === null || $reason === '') {
            return ['ok' => false, 'error' => 'A correction reason is required.'];
        }

        try {
            $result = DB::transaction(function () use ($id, $reason, $me): array {
                $count = DB::table('stock_counts')->where('id', $id)->lockForUpdate()->first();
                if ($count === null || $count->status !== 'approved') {
                    return ['ok' => false, 'error' => 'Approve the stock count before applying a correction'];
                }

                foreach (DB::table('stock_count_items')->where('count_id', $id)->get() as $item) {
                    $delta = (int) $item->counted_qty - (int) $item->expected_qty;
                    if ($delta === 0) {
                        continue;
                    }

                    DB::table('stock_adjustments')->insert([
                        'id' => (string) Str::uuid(),
                        'shop_id' => $count->shop_id,
                        'phone_model_id' => $item->phone_model_id,
                        'staff_id' => $me->id,
                        'type' => $delta > 0 ? 'restock' : 'correction',
                        'delta' => $delta,
                        'reason' => $reason,
                    ]);
                }

                DB::table('stock_counts')->where('id', $id)->update(['status' => 'applied']);

                return ['ok' => true];
            });
        } catch (QueryException|\PDOException $e) {
            return $this->dbError($e);
        }

        if (! $result['ok']) {
            return $result;
        }

        DataCache::flush();

        return ['ok' => true];
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
