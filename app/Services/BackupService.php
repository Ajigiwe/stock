<?php

namespace App\Services;

use App\Models\User;
use App\Support\DataCache;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ports of the settings backup download route (GET /settings/backup) and
 * restoreBackup() / restore_backup().
 *
 * export() builds the very document the Next.js route streamed back —
 * `app`/`version`/`exported_at` plus the six business tables — so a file made
 * here can be fed straight back into restore(). Login credentials (email,
 * password, remember token) are deliberately left out: Supabase never exported
 * auth.users either. restore() therefore takes them from the accounts that
 * already exist on this server, which is the merged-schema equivalent of the
 * original's `if exists (select 1 from auth.users where id = ...)` guard: a
 * profile is restored only for an account that can still log in here, and the
 * password a user chose after the backup was taken keeps working.
 *
 * `available` is never written anywhere else in the app; restore is the one
 * exception, because the original re-computed it with triggers re-enabled (see
 * migration 0000_00_00_000002, @mrjeff_no_stock_effects).
 */
class BackupService
{
    /** The six tables the original route selected, in the same order. */
    private const TABLES = [
        'shops',
        'users',
        'phone_models',
        'transactions',
        'transaction_items',
        'stock_adjustments',
    ];

    /** The keys restoreBackup() requires before it touches the database. */
    private const RESTORE_KEYS = [
        'shops',
        'users',
        'phone_models',
        'transactions',
        'transaction_items',
        'stock_adjustments',
    ];

    /**
     * FK-safe delete order (children before parents): the same sequence
     * restore_backup() ran, with stock_count_items made explicit instead of
     * relying on the CASCADE from stock_counts.
     */
    private const DELETE_ORDER = [
        'stock_logs',
        'login_logs',
        'stock_requests',
        'swapped_phones',
        'transaction_events',
        'stock_count_items',
        'stock_counts',
        'daily_closes',
        'transaction_items',
        'transactions',
        'stock_adjustments',
        'phone_models',
        'users',
        'shops',
    ];

    /**
     * Full JSON backup of every business table (owner only).
     *
     * @return array{ok: bool, error?: string, backup?: array<string, mixed>}
     */
    public function export(User $actor): array
    {
        $me = $this->fresh($actor);
        if (! ($me instanceof User)) {
            return $me;
        }
        if ($me->role !== User::ROLE_OWNER) {
            return ['ok' => false, 'error' => 'Forbidden'];
        }

        try {
            // One transaction = one consistent snapshot; the six sequential
            // reads of the original could otherwise straddle a write.
            $backup = DB::transaction(function (): array {
                $payload = [
                    'app' => 'mr-jeff-stock',
                    'version' => 1,
                    'exported_at' => gmdate('Y-m-d\TH:i:s.v\Z'),
                ];

                foreach (self::TABLES as $table) {
                    $rows = [];
                    foreach (DB::table($table)->get() as $row) {
                        $row = (array) $row;
                        unset($row['password'], $row['remember_token']);
                        $rows[] = $row;
                    }
                    $payload[$table] = $rows;
                }

                return $payload;
            });
        } catch (QueryException|\PDOException $e) {
            return $this->dbError($e);
        }

        return ['ok' => true, 'backup' => $backup];
    }

    /**
     * Replace every business table with the contents of a backup file.
     *
     * The raw JSON string belongs to the controller (it enforces the 20 MB cap
     * and reports "Backup file is missing or too large." / "File is not valid
     * JSON."); by the time the array reaches us the original pre-flight checks
     * are re-run here in the same order, because a mangled file must never
     * reach a function whose job is to empty the database.
     *
     * @return array{ok: bool, error?: string, transactions?: int, warnings?: array<int, string>}
     */
    public function restore(array $data, User $actor): array
    {
        $me = $this->fresh($actor);
        if (! ($me instanceof User)) {
            return $me;
        }
        if ($me->role !== User::ROLE_OWNER) {
            return ['ok' => false, 'error' => 'Only the owner can restore backups.'];
        }

        // A JSON array (or any list) is not an object, exactly like the
        // `Array.isArray(parsed) || parsed == null` guard. An empty document
        // cannot be told apart from `{}` once decoded, so it falls through to
        // the key check below, which reports the same missing-key message the
        // original produced for `{}`.
        if ($data !== [] && array_is_list($data)) {
            return ['ok' => false, 'error' => 'Not a Mr Jeff Stock backup file.'];
        }

        foreach (self::RESTORE_KEYS as $key) {
            if (! isset($data[$key]) || ! is_array($data[$key])) {
                return ['ok' => false, 'error' => 'Not a Mr Jeff Stock backup file (missing "'.$key.'").'];
            }
        }

        $hasOwner = false;
        foreach ($data['users'] as $user) {
            if (is_array($user) && ($user['role'] ?? null) === 'owner') {
                $hasOwner = true;
                break;
            }
        }
        if (! $hasOwner) {
            return ['ok' => false, 'error' => 'Backup file contains no owner account.'];
        }

        try {
            $summary = DB::transaction(fn (): array => $this->load($data, $me));
        } catch (QueryException|\PDOException $e) {
            return $this->dbError($e);
        } finally {
            // Session variables are not transactional, so the flag has to be
            // dropped on every path — including a rollback.
            try {
                DB::unprepared('SET @mrjeff_no_stock_effects = 0');
            } catch (\Throwable) {
                // Nothing useful left to do if the connection is already gone.
            }
        }

        DataCache::flush();

        $result = ['ok' => true, 'transactions' => $summary['transactions']];
        if ($summary['warnings'] !== []) {
            $result['warnings'] = $summary['warnings'];
        }

        return $result;
    }

    /**
     * The restore body — runs inside the caller's transaction.
     *
     * @return array{transactions: int, warnings: array<int, string>}
     */
    private function load(array $data, User $me): array
    {
        DB::unprepared('SET @mrjeff_no_stock_effects = 1');

        // Snapshot the login side before the delete: credentials are not part
        // of the file, so they are carried over per account id.
        $accounts = [];
        foreach (DB::table('users')->get() as $account) {
            $accounts[$account->id] = $account;
        }

        foreach (self::DELETE_ORDER as $table) {
            DB::table($table)->delete();
        }

        foreach ($data['shops'] as $raw) {
            $raw = is_array($raw) ? $raw : [];
            DB::table('shops')->insert($this->omitNull([
                'id' => $this->uuid($raw['id'] ?? null),
                'name' => $this->text($raw['name'] ?? null),
                'location' => $this->text($raw['location'] ?? null),
                'phone' => $this->text($raw['phone'] ?? null),
                'created_at' => $this->timestamp($raw['created_at'] ?? null),
            ], 'created_at'));
        }

        $warnings = [];
        $profiles = [];
        $missing = 0;

        foreach ($data['users'] as $raw) {
            $raw = is_array($raw) ? $raw : [];
            $id = $this->uuid($raw['id'] ?? null);
            $account = $id === null ? null : ($accounts[$id] ?? null);

            // No login for this id on this server -> no profile row, which is
            // what `if exists (select 1 from auth.users where id = ...)` did.
            if ($account === null) {
                $missing++;

                continue;
            }

            // `on conflict (id) do nothing`: a repeated id in the file is not
            // an error, but every other failure (missing shop, bad value) must
            // still stop the restore.
            if (! DB::table('users')->where('id', $id)->exists()) {
                DB::table('users')->insert($this->omitNull([
                    'id' => $id,
                    'name' => $this->text($raw['name'] ?? null) ?? '',
                    'email' => $account->email,
                    'password' => $account->password,
                    'remember_token' => $account->remember_token,
                    'role' => $this->enum($raw['role'] ?? null, User::ROLE_ATTENDANT),
                    'shop_id' => $this->uuid($raw['shop_id'] ?? null),
                    'active' => $this->bool($raw['active'] ?? null, true) ? 1 : 0,
                    'created_at' => $this->timestamp($raw['created_at'] ?? null),
                ], 'created_at'));
            }

            $profiles[$id] = true;
        }

        if ($missing > 0) {
            $warnings[] = "Skipped {$missing} account(s) that do not exist on this server.";
        }

        // Always re-assert the caller as the owner, exactly like the
        // `on conflict (id) do update set role = 'owner', shop_id = null,
        // active = true` upsert — the file's own name wins if it has one.
        $own = $accounts[$me->id] ?? null;
        if (DB::table('users')->where('id', $me->id)->exists()) {
            DB::table('users')->where('id', $me->id)->update([
                'role' => User::ROLE_OWNER,
                'shop_id' => null,
                'active' => 1,
            ]);
        } else {
            DB::table('users')->insert($this->omitNull([
                'id' => $me->id,
                'name' => $me->name,
                'email' => $own->email ?? $me->email,
                'password' => $own->password ?? $me->password,
                'remember_token' => $own->remember_token ?? null,
                'role' => User::ROLE_OWNER,
                'shop_id' => null,
                'active' => 1,
                'created_at' => $own->created_at ?? null,
            ], 'created_at'));
        }
        $profiles[$me->id] = true;

        foreach ($data['phone_models'] as $raw) {
            $raw = is_array($raw) ? $raw : [];
            DB::table('phone_models')->insert($this->omitNull([
                'id' => $this->uuid($raw['id'] ?? null),
                'shop_id' => $this->uuid($raw['shop_id'] ?? null),
                'model_name' => $this->text($raw['model_name'] ?? null),
                'condition' => $this->enum($raw['condition'] ?? null, 'new'),
                'cost_price' => $this->money($raw['cost_price'] ?? null),
                'sale_price' => $this->money($raw['sale_price'] ?? null),
                'opening_stock' => $this->intClamped($raw['opening_stock'] ?? null, 0, 0),
                'bought_in' => $this->intClamped($raw['bought_in'] ?? null, 0, 0),
                'available' => $this->intClamped($raw['available'] ?? null, 0, 0),
                'low_stock_threshold' => $this->intClamped($raw['low_stock_threshold'] ?? null, 0, 5),
                'created_at' => $this->timestamp($raw['created_at'] ?? null),
            ], 'created_at'));
        }

        foreach ($data['transactions'] as $raw) {
            $raw = is_array($raw) ? $raw : [];
            $staff = $this->uuid($raw['staff_id'] ?? null);
            if ($staff === null || ! isset($profiles[$staff])) {
                $staff = $me->id;
            }

            DB::table('transactions')->insert($this->omitNull([
                'id' => $this->uuid($raw['id'] ?? null),
                'shop_id' => $this->uuid($raw['shop_id'] ?? null),
                'staff_id' => $staff,
                'customer_name' => $this->text($raw['customer_name'] ?? null),
                'customer_phone' => $this->text($raw['customer_phone'] ?? null),
                'type' => $this->enum($raw['type'] ?? null, 'sale'),
                'payment_method' => $this->enum($raw['payment_method'] ?? null, 'cash'),
                'amount' => $this->moneyClamped($raw['amount'] ?? null),
                'date' => $this->timestamp($raw['date'] ?? null),
                'created_at' => $this->timestamp($raw['created_at'] ?? null),
                'status' => 'completed',
            ], 'date', 'created_at'));
        }

        foreach ($data['transaction_items'] as $raw) {
            $raw = is_array($raw) ? $raw : [];
            DB::table('transaction_items')->insert([
                'id' => $this->uuid($raw['id'] ?? null),
                'transaction_id' => $this->uuid($raw['transaction_id'] ?? null),
                'phone_model_id' => $this->uuid($raw['phone_model_id'] ?? null),
                'direction' => $this->text($raw['direction'] ?? null),
                'qty' => $this->intClamped($raw['qty'] ?? null, 1, 1),
            ]);
        }

        foreach ($data['stock_adjustments'] as $raw) {
            $raw = is_array($raw) ? $raw : [];
            $delta = $this->intOrNull($raw['delta'] ?? null);

            // `if coalesce(delta, 0) <> 0` — a zero or absent delta is not a
            // row at all (the table's CHECK forbids it).
            if ($delta === null || $delta === 0) {
                continue;
            }

            $staff = $this->uuid($raw['staff_id'] ?? null);
            if ($staff === null || ! isset($profiles[$staff])) {
                $staff = $me->id;
            }

            DB::table('stock_adjustments')->insert($this->omitNull([
                'id' => $this->uuid($raw['id'] ?? null),
                'shop_id' => $this->uuid($raw['shop_id'] ?? null),
                'phone_model_id' => $this->uuid($raw['phone_model_id'] ?? null),
                'staff_id' => $staff,
                'type' => $this->enum($raw['type'] ?? null, 'restock'),
                'delta' => $delta,
                'reason' => $this->text($raw['reason'] ?? null),
                'date' => $this->timestamp($raw['date'] ?? null),
            ], 'date'));
        }

        // Same invariant the original recomputed once its triggers were back
        // on: available = opening + bought_in + in - out - negative adjustments.
        DB::unprepared(self::RECONCILE_SQL);

        return [
            'transactions' => DB::table('transactions')->count(),
            'warnings' => $warnings,
        ];
    }

    /**
     * Recompute `available` from the restored movements — the one place the
     * app writes that column, as restore_backup() did after re-enabling its
     * triggers.
     */
    private const RECONCILE_SQL = <<<'SQL'
        UPDATE phone_models m
           SET m.available = GREATEST(
                   m.opening_stock + m.bought_in
                   + COALESCE((SELECT SUM(i.qty) FROM transaction_items i
                                WHERE i.phone_model_id = m.id AND i.direction = 'in'), 0)
                   - COALESCE((SELECT SUM(i.qty) FROM transaction_items i
                                WHERE i.phone_model_id = m.id AND i.direction = 'out'), 0)
                   - COALESCE((SELECT SUM(-a.delta) FROM stock_adjustments a
                                WHERE a.phone_model_id = m.id AND a.delta < 0), 0),
                   0)
         WHERE m.available <> GREATEST(
                   m.opening_stock + m.bought_in
                   + COALESCE((SELECT SUM(i.qty) FROM transaction_items i
                                WHERE i.phone_model_id = m.id AND i.direction = 'in'), 0)
                   - COALESCE((SELECT SUM(i.qty) FROM transaction_items i
                                WHERE i.phone_model_id = m.id AND i.direction = 'out'), 0)
                   - COALESCE((SELECT SUM(-a.delta) FROM stock_adjustments a
                                WHERE a.phone_model_id = m.id AND a.delta < 0), 0),
                   0)
        SQL;

    /** JSON scalars as text; objects/arrays have no place in a column. */
    private function text(mixed $v): ?string
    {
        if (is_string($v)) {
            return $v;
        }
        if (is_int($v) || is_float($v)) {
            return (string) $v;
        }

        return null;
    }

    /** `nullif(v_rec ->> 'x', '')::uuid` — absent and blank both mean NULL. */
    private function uuid(mixed $v): ?string
    {
        $s = $this->text($v);

        return $s === null || $s === '' ? null : $s;
    }

    /** `coalesce((v_rec ->> 'x')::enum, $fallback)` without the cast error. */
    private function enum(mixed $v, string $fallback): string
    {
        $s = $this->text($v);

        return $s === null || $s === '' ? $fallback : $s;
    }

    /** `coalesce((v_rec ->> 'x')::boolean, $fallback)`. */
    private function bool(mixed $v, bool $fallback): bool
    {
        if (is_bool($v)) {
            return $v;
        }

        $s = strtolower(trim((string) ($this->text($v) ?? '')));
        if ($s === '') {
            return $fallback;
        }
        if (in_array($s, ['1', 't', 'true', 'y', 'yes', 'on'], true)) {
            return true;
        }
        if (in_array($s, ['0', 'f', 'false', 'n', 'no', 'off'], true)) {
            return false;
        }

        return $fallback;
    }

    /**
     * `greatest(coalesce(x, $fallback), $min)`; anything that is not an
     * integer is passed straight through so MariaDB rejects it the way
     * Postgres rejected `x::int`.
     */
    private function intClamped(mixed $v, int $min, int $fallback): int|string
    {
        if ($v === null || $v === '') {
            return $fallback;
        }

        $i = filter_var($v, FILTER_VALIDATE_INT);

        return $i === false ? (is_scalar($v) ? (string) $v : 'invalid') : max($i, $min);
    }

    /** Delta column: NULL and 0 are filtered out by the caller, text is not. */
    private function intOrNull(mixed $v): int|string|null
    {
        if ($v === null || $v === '') {
            return null;
        }

        $i = filter_var($v, FILTER_VALIDATE_INT);

        return $i === false ? (is_scalar($v) ? (string) $v : null) : $i;
    }

    /** `greatest(coalesce(amount, 0), 0)` for the NOT NULL money columns. */
    private function moneyClamped(mixed $v): float|string
    {
        if ($v === null || $v === '') {
            return 0.0;
        }
        if (! is_numeric($v)) {
            return is_scalar($v) ? (string) $v : 'invalid';
        }

        return max(round((float) $v, 2), 0.0);
    }

    /** Nullable money: the column's own CHECK decides what is acceptable. */
    private function money(mixed $v): float|string|null
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (! is_numeric($v)) {
            return is_scalar($v) ? (string) $v : 'invalid';
        }

        return round((float) $v, 2);
    }

    /**
     * ISO-8601 from the file -> UTC 'Y-m-d H:i:s'. Absent means "let the
     * column default", i.e. `coalesce(x, now())`; unparseable values are
     * passed through untouched so the database reports them.
     */
    private function timestamp(mixed $v): ?string
    {
        $s = $this->text($v);
        if ($s === null || $s === '') {
            return null;
        }

        $ts = strtotime($s);

        return $ts === false ? $s : gmdate('Y-m-d H:i:s', $ts);
    }

    /**
     * Drop the columns whose value is NULL only when they carry a default —
     * inserting an explicit NULL into a NOT NULL column with a DEFAULT would
     * fail where the original simply omitted it.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function omitNull(array $values, string ...$columns): array
    {
        foreach ($columns as $column) {
            if (($values[$column] ?? null) === null) {
                unset($values[$column]);
            }
        }

        return $values;
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
