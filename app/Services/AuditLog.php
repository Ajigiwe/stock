<?php

namespace App\Services;

use App\Models\StockLog;
use App\Models\TransactionEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Audit rows the original wrote:
 *   - stock_logs via logStockEdit() in src/lib/actions.ts
 *   - transaction_events via the record/review/void RPCs in migration 0004
 */
class AuditLog
{
    /**
     * Append a stock_logs row for an owner stock edit.
     *
     * Best-effort, exactly like logStockEdit(): a logging failure must never
     * fail the stock change itself. The shop comes from the model row (the
     * callers always pass one), falling back to the actor's own shop.
     *
     * @param  array<string, mixed>  $details
     */
    public static function stock(User $actor, string $action, ?string $modelId, ?string $modelName, ?string $condition, array $details): void
    {
        try {
            $shopId = $modelId !== null
                ? DB::table('phone_models')->where('id', $modelId)->value('shop_id')
                : null;
            $shopId ??= $actor->shop_id;

            if ($shopId === null) {
                return;
            }

            StockLog::create([
                'id' => (string) Str::uuid(),
                'shop_id' => $shopId,
                'phone_model_id' => $modelId,
                'staff_id' => $actor->id,
                'action' => $action,
                'model_name' => $modelName,
                'condition' => $condition,
                'details' => $details === [] ? null : $details,
            ]);
        } catch (\Throwable) {
            // Logging must never fail the stock change itself.
        }
    }

    /**
     * Append a transaction_events row (created / approved / rejected / voided).
     *
     * Unlike stock_logs this runs inside the caller's transaction: a failed
     * event insert aborted the whole RPC in Postgres, so this may throw.
     *
     * @param  array<string, mixed>  $details
     */
    public static function event(string $txId, User $actor, string $action, array $details): void
    {
        TransactionEvent::create([
            'id' => (string) Str::uuid(),
            'transaction_id' => $txId,
            'actor_id' => $actor->id,
            'action' => $action,
            'details' => $details,
        ]);
    }
}
