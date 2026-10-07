<?php

namespace App\Services;

use App\Models\User;
use App\Support\DataCache;
use App\Support\Input;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ports of approveStockRequest(), rejectStockRequest() and
 * approveAllStockRequests() — including the approve_stock_request /
 * approve_all_stock_requests RPC bodies from supabase/schema.sql.
 *
 * Applying a request is the same code on every path: lock the pending row,
 * apply it (create the model / move stock through stock_adjustments), then
 * mark it approved.
 */
class StockRequestService
{
    public function approve(string $id, User $actor): array
    {
        $me = $this->fresh($actor);
        if (! ($me instanceof User)) {
            return $me;
        }
        if (! Input::isUuid($id)) {
            return ['ok' => false, 'error' => 'Invalid request.'];
        }

        // Capability check before touching anything: owners decide
        // everywhere; a permitted attendant only inside their own shop.
        $shopId = DB::table('stock_requests')->where('id', $id)->value('shop_id');
        $denied = $this->denyDecide($me, is_string($shopId) ? $shopId : null);
        if ($denied !== null) {
            return ['ok' => false, 'error' => $denied];
        }

        try {
            $result = DB::transaction(fn (): array => $this->apply($id, $me));
        } catch (QueryException|\PDOException $e) {
            return $this->dbError($e);
        }

        if (! $result['ok']) {
            return $result;
        }

        DataCache::flush();

        return $result;
    }

    public function reject(string $id, User $actor): array
    {
        $me = $this->fresh($actor);
        if (! ($me instanceof User)) {
            return $me;
        }
        if (! Input::isUuid($id)) {
            return ['ok' => false, 'error' => 'Invalid request.'];
        }

        $shopId = DB::table('stock_requests')->where('id', $id)->value('shop_id');
        $denied = $this->denyDecide($me, is_string($shopId) ? $shopId : null, 'reject');
        if ($denied !== null) {
            return ['ok' => false, 'error' => $denied];
        }

        try {
            $affected = DB::table('stock_requests')
                ->where('id', $id)
                ->where('status', 'pending')
                ->update([
                    'status' => 'rejected',
                    'decided_at' => gmdate('Y-m-d H:i:s'),
                    'decided_by' => $me->id,
                    'error_note' => null,
                ]);
        } catch (QueryException|\PDOException $e) {
            return $this->dbError($e);
        }

        if ($affected === 0) {
            return ['ok' => false, 'error' => 'Pending stock request not found'];
        }

        DataCache::flush();

        return ['ok' => true];
    }

    /**
     * Port of approveAllStockRequests(): every pending request is applied in
     * its own transaction, so one failure leaves that row pending (with the
     * reason in error_note) while the rest still go through.
     *
     * @return array{ok: bool, error?: string, warnings?: array<int, string>}
     */
    public function approveAll(User $actor): array
    {
        $me = $this->fresh($actor);
        if (! ($me instanceof User)) {
            return $me;
        }
        if (! $me->isAdmin() && ! $me->perm_approve_requests) {
            return ['ok' => false, 'error' => 'Only the owner can approve stock changes.'];
        }

        try {
            $query = DB::table('stock_requests')
                ->where('status', 'pending');
            if (! $me->isAdmin()) {
                // A permitted attendant only ever sees their own shop.
                $query->where('shop_id', $me->shop_id);
            }
            $pending = $query->orderBy('created_at')->get();
        } catch (QueryException|\PDOException $e) {
            return $this->dbError($e);
        }

        $warnings = [];

        foreach ($pending as $request) {
            $error = null;

            try {
                $result = DB::transaction(fn (): array => $this->apply($request->id, $me));
                if (! $result['ok']) {
                    $error = $result['error'] ?? '';
                }
            } catch (QueryException|\PDOException $e) {
                $info = $e->errorInfo ?? null;
                $error = is_array($info) && is_string($info[2] ?? null) && $info[2] !== ''
                    ? $info[2]
                    : Str::before($e->getMessage(), ' (Connection: ');
            }

            if ($error === null) {
                continue;
            }

            $label = $request->type === 'create_model' && $request->model_name !== null
                ? $request->model_name
                : 'Stock adjustment';

            try {
                DB::table('stock_requests')->where('id', $request->id)->update([
                    'error_note' => mb_substr($error, 0, 300),
                ]);
            } catch (QueryException|\PDOException) {
                // The note is bookkeeping: losing it must not stop the batch.
            }

            $warnings[] = 'Could not apply "'.$label.'": '.$error;
        }

        DataCache::flush();

        return $warnings === [] ? ['ok' => true] : ['ok' => true, 'warnings' => $warnings];
    }

    /**
     * Apply one pending request inside the caller's transaction.
     *
     * @return array{ok: bool, error?: string}
     */
    private function apply(string $requestId, User $me): array
    {
        $request = DB::table('stock_requests')
            ->where('id', $requestId)
            ->where('status', 'pending')
            ->lockForUpdate()
            ->first();

        if ($request === null) {
            return ['ok' => false, 'error' => 'Pending stock request not found'];
        }

        if ($request->type === 'create_model') {
            $duplicate = DB::table('phone_models')
                ->where('shop_id', $request->shop_id)
                ->where('model_name', $request->model_name)
                ->where('condition', $request->condition)
                ->where('sim_type', $request->sim_type ?? '')
                ->where('color', $request->color ?? '')
                ->where('category', $request->category ?? 'phone')
                ->exists();
            if ($duplicate) {
                return ['ok' => false, 'error' => 'A model with this name and condition already exists'];
            }

            DB::table('phone_models')->insert([
                'id' => (string) Str::uuid(),
                'shop_id' => $request->shop_id,
                'model_name' => $request->model_name,
                'condition' => $request->condition,
                'sim_type' => $request->sim_type ?? '',
                'color' => $request->color ?? '',
                'category' => $request->category ?? 'phone',
                'cost_price' => $request->cost_price,
                'sale_price' => $request->sale_price,
                'opening_stock' => $request->opening_stock ?? 0,
                'bought_in' => 0,
                'low_stock_threshold' => $request->low_stock_threshold ?? 5,
            ]);
        } elseif ($request->type === 'adjust_stock') {
            // Re-check shop ownership at approval time: the model could have
            // been reassigned between request and approval.
            $belongs = DB::table('phone_models')
                ->where('id', $request->phone_model_id)
                ->where('shop_id', $request->shop_id)
                ->exists();
            if (! $belongs) {
                return ['ok' => false, 'error' => 'Phone model does not belong to this shop'];
            }

            DB::table('stock_adjustments')->insert([
                'id' => (string) Str::uuid(),
                'shop_id' => $request->shop_id,
                'phone_model_id' => $request->phone_model_id,
                'staff_id' => $request->staff_id,
                'type' => $request->delta > 0 ? 'restock' : 'correction',
                'delta' => (int) $request->delta,
                'reason' => $request->reason,
            ]);
        }

        DB::table('stock_requests')->where('id', $requestId)->update([
            'status' => 'approved',
            'decided_at' => gmdate('Y-m-d H:i:s'),
            'decided_by' => $me->id,
            'error_note' => null,
        ]);

        return ['ok' => true];
    }

    /**
     * Owners decide everywhere; a permitted attendant only inside their own
     * shop. Returns the refusal message, or null when the decision may go
     * ahead.
     */
    private function denyDecide(User $me, ?string $shopId, string $verb = 'approve'): ?string
    {
        if ($me->isAdmin()) {
            return null;
        }
        if (! $me->perm_approve_requests) {
            return 'Only the owner can '.$verb.' stock changes.';
        }
        if ($me->shop_id === null || $shopId === null || $shopId !== $me->shop_id) {
            return 'Pending stock request not found';
        }

        return null;
    }

    /**
     * Re-read the caller's row so role checks fail closed on fresh data
     * instead of trusting the passed-in model.
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
