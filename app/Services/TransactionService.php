<?php

namespace App\Services;

use App\Models\User;
use App\Support\DataCache;
use App\Support\Input;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ports of recordTransaction(), reviewTransaction(), voidTransaction() and
 * updateSwappedPhoneStatus() — including the bodies of the record_transaction /
 * review_transaction / void_transaction / update_swapped_phone_status RPCs
 * (supabase/migrations/0004_fraud_controls_and_reconciliation.sql), which were
 * the authoritative business rules.
 */
class TransactionService
{
    /** @var list<string> */
    private const TX_TYPES = ['sale', 'swap', 'repair'];

    /** @var list<string> */
    private const PAYMENT_METHODS = ['cash', 'mobile_money', 'card', 'bank_transfer', 'other'];

    /** @var list<string> */
    private const SWAP_STATUSES = ['in_stock', 'sold', 'returned'];

    /**
     * Port of recordTransaction() + the record_transaction RPC. Validation
     * runs in the same order as the original so the user sees the same words.
     *
     * @param  array<string, mixed>  $input
     * @return array{ok: bool, error?: string, id?: string}
     */
    public function record(array $input, User $actor): array
    {
        $me = $this->fresh($actor);
        if (! ($me instanceof User)) {
            return $me;
        }

        $type = $input['type'] ?? null;
        if (! in_array($type, self::TX_TYPES, true)) {
            return ['ok' => false, 'error' => 'Choose a valid transaction type.'];
        }

        $paymentMethod = $input['paymentMethod'] ?? null;
        if (! in_array($paymentMethod, self::PAYMENT_METHODS, true)) {
            return ['ok' => false, 'error' => 'Choose a valid payment method.'];
        }

        [$ok, $amount] = Input::money($input['amount'] ?? null, 'Amount');
        if (! $ok) {
            return ['ok' => false, 'error' => $amount];
        }
        $amount ??= 0;

        $customerName = Input::trimmed($input['customerName'] ?? null);
        $customerPhone = Input::trimmed($input['customerPhone'] ?? null);
        if ($customerName === '' || $customerPhone === '') {
            return ['ok' => false, 'error' => 'Customer name and phone are required.'];
        }

        [$ok, $date] = Input::txDate($input['date'] ?? null);
        if (! $ok) {
            return ['ok' => false, 'error' => $date];
        }

        $idempotencyKey = $input['idempotencyKey'] ?? null;
        if ($idempotencyKey !== null && ! Input::isUuid($idempotencyKey)) {
            return ['ok' => false, 'error' => 'Invalid request. Please try again.'];
        }
        $idempotencyKey ??= Input::idempotencyKey($input);

        // Merge duplicate lines for the same model: two separate lines used to
        // slip past the sale-price floor, which only looked at the first match.
        $qtyByModel = [];
        foreach (is_array($input['outItems'] ?? null) ? $input['outItems'] : [] as $item) {
            if (! is_array($item) || ! Input::isUuid($item['modelId'] ?? null)) {
                continue;
            }
            [$ok, $qty] = Input::qty($item['qty'] ?? null);
            if (! $ok) {
                return ['ok' => false, 'error' => $qty];
            }
            $modelId = $item['modelId'];
            $qtyByModel[$modelId] = ($qtyByModel[$modelId] ?? 0) + $qty;
        }

        if ($type !== 'repair' && $qtyByModel === []) {
            return ['ok' => false, 'error' => 'Add at least one phone going out.'];
        }

        // Incoming sellable stock is parsed for the same cost/sale errors the
        // original produced, then refused outright by the RPC.
        $inItemCount = 0;
        foreach (is_array($input['inItems'] ?? null) ? $input['inItems'] : [] as $item) {
            if (! is_array($item)) {
                continue;
            }
            [$ok, $qty] = Input::qty($item['qty'] ?? null);
            if (! $ok) {
                continue;
            }
            if (($item['mode'] ?? null) === 'existing') {
                if (! Input::isUuid($item['modelId'] ?? null)) {
                    continue;
                }
                $inItemCount++;

                continue;
            }

            if (Input::trimmed($item['name'] ?? null) === '') {
                continue;
            }
            [$ok, $cost] = Input::money($item['costPrice'] ?? null, 'Cost price');
            if (! $ok) {
                return ['ok' => false, 'error' => $cost];
            }
            [$ok, $sale] = Input::money($item['salePrice'] ?? null, 'Sale price');
            if (! $ok) {
                return ['ok' => false, 'error' => $sale];
            }
            $inItemCount++;
        }

        // Swap trade-ins: the customer's old phone, picked from the iPhone list.
        $swapIn = array_values(array_filter(
            is_array($input['swapIn'] ?? null) ? $input['swapIn'] : [],
            fn ($swap) => is_array($swap) && Input::trimmed($swap['name'] ?? null) !== ''
        ));
        if ($type === 'swap' && $swapIn === []) {
            return ['ok' => false, 'error' => 'Add the old phone the customer is trading in.'];
        }

        // Attendants: force their own shop regardless of what the form sends.
        $shopId = $me->role === User::ROLE_OWNER ? ($input['shopId'] ?? null) : $me->shop_id;
        if (! Input::isUuid($shopId)) {
            return ['ok' => false, 'error' => 'No shop selected.'];
        }

        $discountReason = Input::trimmed($input['discountReason'] ?? null);
        $discountReason = $discountReason === '' ? null : $discountReason;
        $paymentReference = Input::trimmed($input['paymentReference'] ?? null);
        $paymentReference = $paymentReference === '' ? null : $paymentReference;

        // Sales below the combined list price are allowed only with a reason;
        // the database records them as pending_review for owner review.
        if ($type === 'sale' && $qtyByModel !== []) {
            $required = 0.0;
            foreach (DB::table('phone_models')
                ->where('shop_id', $shopId)
                ->whereIn('id', array_keys($qtyByModel))
                ->get(['id', 'sale_price']) as $model) {
                $required += ($model->sale_price === null ? 0.0 : (float) $model->sale_price)
                    * ($qtyByModel[$model->id] ?? 0);
            }

            if ($amount < $required && $discountReason === null) {
                return [
                    'ok' => false,
                    'error' => 'Add a reason for the discount below '.$this->toLocaleString($required).' GHS.',
                ];
            }
        }

        if ($me->role !== User::ROLE_OWNER && $shopId !== $me->shop_id) {
            return ['ok' => false, 'error' => 'Not allowed to record transactions for this shop'];
        }

        if (in_array($paymentMethod, ['mobile_money', 'card', 'bank_transfer'], true) && $paymentReference === null) {
            return ['ok' => false, 'error' => 'A payment reference is required for this payment method'];
        }

        if ($inItemCount > 0) {
            return ['ok' => false, 'error' => 'Sellable incoming stock must be recorded through stock controls'];
        }

        if ($type === 'repair' && $qtyByModel !== []) {
            return ['ok' => false, 'error' => 'Repairs cannot move stock'];
        }

        try {
            $result = DB::transaction(function () use (
                $type,
                $amount,
                $date,
                $customerName,
                $customerPhone,
                $paymentMethod,
                $paymentReference,
                $discountReason,
                $idempotencyKey,
                $shopId,
                $qtyByModel,
                $swapIn,
                $me
            ): array {
                // Idempotency: a retry of the same submission returns the
                // existing transaction instead of deducting stock twice.
                if ($idempotencyKey !== null) {
                    $existing = DB::table('transactions')
                        ->where('idempotency_key', $idempotencyKey)
                        ->where('shop_id', $shopId)
                        ->value('id');

                    if ($existing !== null) {
                        return ['ok' => true, 'id' => $existing];
                    }
                }

                // Lock every outgoing model in id order — deadlock avoidance,
                // same rows the RPC took `for update` — and validate them all
                // before anything is written.
                $ids = array_keys($qtyByModel);
                sort($ids);

                $lockedRows = [];
                if ($ids !== []) {
                    $lockedRows = DB::table('phone_models')
                        ->whereIn('id', $ids)
                        ->where('shop_id', $shopId)
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get()
                        ->keyBy('id');
                }

                $required = 0.0;
                foreach ($ids as $modelId) {
                    $row = $lockedRows->get($modelId);
                    if ($row === null) {
                        return ['ok' => false, 'error' => 'Unknown phone model for this shop'];
                    }
                    if ($type === 'sale' && $row->sale_price === null) {
                        return ['ok' => false, 'error' => 'Every phone in a sale must have a listed price'];
                    }
                    if ($row->available < $qtyByModel[$modelId]) {
                        return [
                            'ok' => false,
                            'error' => 'Insufficient stock: only '.$row->available.' available for this model',
                        ];
                    }
                    $required += ($row->sale_price === null ? 0.0 : (float) $row->sale_price)
                        * $qtyByModel[$modelId];
                }

                $status = 'completed';
                if ($type === 'sale') {
                    if ($amount < $required) {
                        if ($discountReason === null) {
                            return ['ok' => false, 'error' => 'A discount reason is required below the listed price'];
                        }
                        $status = 'pending_review';
                    }
                    if ($required == 0.0 && $ids !== []) {
                        return ['ok' => false, 'error' => 'Listed prices must be set before recording a sale'];
                    }
                }

                $txId = (string) Str::uuid();
                DB::table('transactions')->insert([
                    'id' => $txId,
                    'shop_id' => $shopId,
                    'staff_id' => $me->id,
                    'customer_name' => $customerName,
                    'customer_phone' => $customerPhone,
                    'type' => $type,
                    'payment_method' => $paymentMethod,
                    'amount' => $amount,
                    'date' => $date,
                    'idempotency_key' => $idempotencyKey,
                    'status' => $status,
                    'review_reason' => $status === 'pending_review' ? 'Below listed price' : null,
                    'discount_reason' => $discountReason,
                    'payment_reference' => $paymentReference,
                    'listed_amount' => $required,
                ]);

                $itemRows = [];
                foreach ($ids as $modelId) {
                    $itemRows[] = [
                        'id' => (string) Str::uuid(),
                        'transaction_id' => $txId,
                        'phone_model_id' => $modelId,
                        'direction' => 'out',
                        'qty' => $qtyByModel[$modelId],
                    ];
                }
                DB::table('transaction_items')->insert($itemRows);

                // Swap trade-ins are logged atomically with the transaction.
                if ($type === 'swap' && $swapIn !== []) {
                    $swapRows = [];
                    foreach ($swapIn as $swap) {
                        $name = Input::trimmed($swap['name'] ?? null);
                        if ($name === '') {
                            continue;
                        }
                        $swapRows[] = [
                            'id' => (string) Str::uuid(),
                            'shop_id' => $shopId,
                            'transaction_id' => $txId,
                            'staff_id' => $me->id,
                            'model_name' => $name,
                            'customer_name' => $customerName,
                            'customer_phone' => $customerPhone,
                        ];
                    }
                    DB::table('swapped_phones')->insert($swapRows);
                }

                AuditLog::event($txId, $me, 'created', [
                    'status' => $status,
                    'listed_price' => $required,
                    'amount' => $amount,
                    'discount_reason' => $discountReason,
                    'payment_reference' => $paymentReference,
                ]);

                return ['ok' => true, 'id' => $txId];
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
     * Port of reviewTransaction() + the review_transaction RPC (owner only).
     * Approving completes the sale; rejecting deletes the line items, which
     * reverses the stock movement through the item_stock_change triggers.
     */
    public function review(string $txId, string $decision, ?string $reason, User $actor): array
    {
        $me = $this->fresh($actor);
        if (! ($me instanceof User)) {
            return $me;
        }
        if ($me->role !== User::ROLE_OWNER) {
            return ['ok' => false, 'error' => 'Only the owner can review transactions.'];
        }
        if (! Input::isUuid($txId)) {
            return ['ok' => false, 'error' => 'Invalid transaction.'];
        }
        if (! in_array($decision, ['approve', 'reject'], true)) {
            return ['ok' => false, 'error' => 'Invalid review decision'];
        }

        $cleanReason = Input::trimmed($reason);
        $cleanReason = $cleanReason === '' ? null : $cleanReason;

        try {
            $result = DB::transaction(function () use ($txId, $decision, $cleanReason, $me): array {
                $tx = DB::table('transactions')->where('id', $txId)->lockForUpdate()->first();
                if ($tx === null || $tx->status !== 'pending_review') {
                    return ['ok' => false, 'error' => 'Transaction is not awaiting review'];
                }

                $now = gmdate('Y-m-d H:i:s');

                if ($decision === 'approve') {
                    DB::table('transactions')->where('id', $txId)->update([
                        'status' => 'completed',
                        'reviewed_by' => $me->id,
                        'reviewed_at' => $now,
                        'review_reason' => $cleanReason ?? $tx->review_reason,
                    ]);
                    AuditLog::event($txId, $me, 'approved', ['reason' => $cleanReason]);

                    return ['ok' => true];
                }

                DB::table('transaction_items')->where('transaction_id', $txId)->delete();
                DB::table('swapped_phones')->where('transaction_id', $txId)->update(['status' => 'returned']);
                DB::table('transactions')->where('id', $txId)->update([
                    'status' => 'rejected',
                    'reviewed_by' => $me->id,
                    'reviewed_at' => $now,
                    'review_reason' => $cleanReason ?? $tx->review_reason,
                ]);
                AuditLog::event($txId, $me, 'rejected', ['reason' => $cleanReason]);

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
     * Port of voidTransaction() + the void_transaction RPC (owner only).
     */
    public function void(string $txId, string $reason, User $actor): array
    {
        $me = $this->fresh($actor);
        if (! ($me instanceof User)) {
            return $me;
        }
        if ($me->role !== User::ROLE_OWNER) {
            return ['ok' => false, 'error' => 'Only the owner can void transactions.'];
        }
        if (! Input::isUuid($txId)) {
            return ['ok' => false, 'error' => 'Invalid transaction.'];
        }

        $cleanReason = Input::trimmed($reason);
        if ($cleanReason === '') {
            return ['ok' => false, 'error' => 'A reason is required.'];
        }

        try {
            $result = DB::transaction(function () use ($txId, $cleanReason, $me): array {
                $tx = DB::table('transactions')->where('id', $txId)->lockForUpdate()->first();
                if ($tx === null || in_array($tx->status, ['voided', 'rejected'], true)) {
                    return ['ok' => false, 'error' => 'Transaction is already closed'];
                }

                $now = gmdate('Y-m-d H:i:s');

                DB::table('transaction_items')->where('transaction_id', $txId)->delete();
                DB::table('swapped_phones')->where('transaction_id', $txId)->update(['status' => 'returned']);
                DB::table('transactions')->where('id', $txId)->update([
                    'status' => 'voided',
                    'voided_by' => $me->id,
                    'voided_at' => $now,
                    'void_reason' => $cleanReason,
                    'reviewed_by' => $me->id,
                    'reviewed_at' => $now,
                ]);
                AuditLog::event($txId, $me, 'voided', ['reason' => $cleanReason]);

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
     * Port of updateSwappedPhoneStatus() + its RPC (owner only).
     */
    public function setSwappedStatus(string $id, string $status, User $actor): array
    {
        $me = $this->fresh($actor);
        if (! ($me instanceof User)) {
            return $me;
        }
        if ($me->role !== User::ROLE_OWNER) {
            return ['ok' => false, 'error' => 'Only the owner can update swapped phones.'];
        }
        if (! Input::isUuid($id)) {
            return ['ok' => false, 'error' => 'Invalid trade-in.'];
        }
        if (! in_array($status, self::SWAP_STATUSES, true)) {
            return ['ok' => false, 'error' => 'Invalid status.'];
        }

        try {
            $affected = DB::table('swapped_phones')->where('id', $id)->update(['status' => $status]);
        } catch (QueryException|\PDOException $e) {
            return $this->dbError($e);
        }

        if ($affected === 0) {
            return ['ok' => false, 'error' => 'Trade-in not found'];
        }

        DataCache::flush();

        return ['ok' => true];
    }

    /**
     * number.toLocaleString(): thousands separators, up to three decimals.
     */
    private function toLocaleString(float $n): string
    {
        return rtrim(rtrim(number_format($n, 3, '.', ','), '0'), '.');
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
