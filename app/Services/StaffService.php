<?php

namespace App\Services;

use App\Models\User;
use App\Support\DataCache;
use App\Support\Input;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Str;

/**
 * Ports of createStaff(), deactivateStaff(), reactivateStaff() and
 * resetStaffPassword() (owner only).
 *
 * Supabase had auth.users (email/password) and public.users (profile) as
 * separate rows; Laravel has one table, so each action writes that one row.
 * The `active` flag is also the ban: login and the `active` middleware both
 * read it, which is what the Supabase ban_duration call achieved.
 */
class StaffService
{
    /**
     * @param  array<string, mixed>  $input
     * @return array{ok: bool, error?: string}
     */
    public function create(array $input, User $actor): array
    {
        $me = $this->fresh($actor);
        if (! ($me instanceof User)) {
            return $me;
        }
        if ($me->role !== User::ROLE_OWNER) {
            return ['ok' => false, 'error' => 'Only the owner can add staff.'];
        }

        $name = Input::trimmed($input['name'] ?? null);
        $email = Input::trimmed($input['email'] ?? null);
        $phone = Input::phone($input['phone'] ?? null);
        $password = (string) ($input['password'] ?? '');

        if ($name === '' || strlen($password) < 8) {
            return ['ok' => false, 'error' => 'Name required; password at least 8 characters.'];
        }

        // Email is optional, but when given it must be usable as a login.
        if ($email !== '' && ! Input::email($email)) {
            return ['ok' => false, 'error' => 'That email address is not valid.'];
        }
        if ($email !== '' && DB::table('users')->where('email', $email)->exists()) {
            return ['ok' => false, 'error' => 'That email address is already in use.'];
        }
        if (($input['phone'] ?? null) !== null && trim((string) $input['phone']) !== '' && $phone === '') {
            return ['ok' => false, 'error' => 'That phone number is not valid.'];
        }
        if ($phone !== '' && DB::table('users')->where('phone', $phone)->exists()) {
            return ['ok' => false, 'error' => 'That phone number is already in use.'];
        }

        try {
            DB::table('users')->insert([
                'id' => (string) Str::uuid(),
                'name' => $name,
                'email' => $email === '' ? null : $email,
                'phone' => $phone === '' ? null : $phone,
                'password' => Hash::make($password),
                'role' => User::ROLE_ATTENDANT,
                'shop_id' => $input['shopId'] ?? null,
            ]);
        } catch (QueryException|\PDOException $e) {
            return $this->dbError($e);
        }

        DataCache::flush();

        return ['ok' => true];
    }

    /**
     * Owner-granted capabilities for one attendant (approve requests, direct
     * stock adjust, reconciliation). Unchecked boxes post nothing, so absence
     * means off. Owners always hold every capability — there is nothing to
     * store on them.
     *
     * @param  array<string, mixed>  $input
     * @return array{ok: bool, error?: string}
     */
    public function setPermissions(string $id, array $input, User $actor): array
    {
        $me = $this->fresh($actor);
        if (! ($me instanceof User)) {
            return $me;
        }
        if ($me->role !== User::ROLE_OWNER) {
            return ['ok' => false, 'error' => 'Only the owner can change permissions.'];
        }
        if (! Input::isUuid($id)) {
            return ['ok' => false, 'error' => 'Staff member not found.'];
        }

        $target = User::query()->whereKey($id)->first();
        if ($target === null) {
            return ['ok' => false, 'error' => 'Staff member not found.'];
        }
        if ($target->role === User::ROLE_OWNER) {
            return ['ok' => false, 'error' => 'You cannot change an owner\'s permissions.'];
        }

        $target->forceFill([
            'perm_approve_requests' => ! empty($input['perm_approve_requests']),
            'perm_adjust_stock' => ! empty($input['perm_adjust_stock']),
            'perm_reconcile' => ! empty($input['perm_reconcile']),
        ])->save();

        DataCache::flush();

        return ['ok' => true];
    }

    /**
     * @return array{ok: bool, error?: string}
     */
    public function deactivate(string $id, User $actor): array
    {
        $me = $this->fresh($actor);
        if (! ($me instanceof User)) {
            return $me;
        }
        if ($me->role !== User::ROLE_OWNER) {
            return ['ok' => false, 'error' => 'Only the owner can deactivate staff.'];
        }
        if ($id === $me->id) {
            return ['ok' => false, 'error' => 'You cannot deactivate yourself.'];
        }
        if (! Input::isUuid($id)) {
            return ['ok' => false, 'error' => 'Invalid staff account.'];
        }

        if (! $this->attendantExists($id)) {
            return ['ok' => false, 'error' => 'Invalid staff account.'];
        }

        try {
            DB::table('users')->where('id', $id)->update([
                'active' => false,
                'deactivated_at' => gmdate('Y-m-d H:i:s'),
                'deactivated_by' => $me->id,
            ]);
        } catch (QueryException|\PDOException $e) {
            return $this->dbError($e);
        }

        DataCache::flush();

        return ['ok' => true];
    }

    /**
     * @return array{ok: bool, error?: string}
     */
    public function reactivate(string $id, User $actor): array
    {
        $me = $this->fresh($actor);
        if (! ($me instanceof User)) {
            return $me;
        }
        if ($me->role !== User::ROLE_OWNER) {
            return ['ok' => false, 'error' => 'Only the owner can reactivate staff.'];
        }
        if (! Input::isUuid($id)) {
            return ['ok' => false, 'error' => 'Invalid staff account.'];
        }

        if (! $this->attendantExists($id)) {
            return ['ok' => false, 'error' => 'Invalid staff account.'];
        }

        try {
            DB::table('users')->where('id', $id)->update([
                'active' => true,
                'deactivated_at' => null,
                'deactivated_by' => null,
            ]);
        } catch (QueryException|\PDOException $e) {
            return $this->dbError($e);
        }

        DataCache::flush();

        return ['ok' => true];
    }

    /**
     * Port of resetStaffPassword() (owner only).
     *
     * The contract signature carries no password argument, so the new password
     * comes from the current request's `password` field — or from an optional
     * third argument when the caller passes it directly.
     *
     * @return array{ok: bool, error?: string}
     */
    public function resetPassword(string $id, User $actor): array
    {
        $me = $this->fresh($actor);
        if (! ($me instanceof User)) {
            return $me;
        }
        if ($me->role !== User::ROLE_OWNER) {
            return ['ok' => false, 'error' => 'Only the owner can reset staff passwords.'];
        }
        if ($id === $me->id) {
            return ['ok' => false, 'error' => 'You cannot reset your own password here.'];
        }

        $passed = func_get_args()[2] ?? null;
        $password = is_string($passed) ? $passed : (string) Request::input('password');

        if (strlen($password) < 8) {
            return ['ok' => false, 'error' => 'Password must be at least 8 characters.'];
        }

        if (! $this->attendantExists($id)) {
            return ['ok' => false, 'error' => 'Invalid staff account.'];
        }

        try {
            DB::table('users')->where('id', $id)->update([
                'password' => Hash::make($password),
            ]);
        } catch (QueryException|\PDOException $e) {
            return $this->dbError($e);
        }

        DataCache::flush();

        return ['ok' => true];
    }

    /**
     * The original targeted attendant rows only (`where role = 'attendant'`)
     * and failed on an unknown id through the auth admin call.
     */
    private function attendantExists(string $id): bool
    {
        return DB::table('users')->where('id', $id)->where('role', User::ROLE_ATTENDANT)->exists();
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
