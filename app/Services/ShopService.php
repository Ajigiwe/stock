<?php

namespace App\Services;

use App\Models\User;
use App\Support\DataCache;
use App\Support\Input;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Ports of createShop() and deleteShop() (owner only). */
class ShopService
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
        if (! $me->isAdmin()) {
            return ['ok' => false, 'error' => 'Only the owner can add shops.'];
        }

        $name = Input::trimmed($input['name'] ?? null);
        if ($name === '') {
            return ['ok' => false, 'error' => 'Shop name is required.'];
        }

        $location = Input::trimmed($input['location'] ?? null);
        $phone = Input::trimmed($input['phone'] ?? null);

        try {
            DB::table('shops')->insert([
                'id' => (string) Str::uuid(),
                'name' => $name,
                'location' => $location === '' ? null : $location,
                'phone' => $phone === '' ? null : $phone,
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
    public function delete(string $id, User $actor): array
    {
        $me = $this->fresh($actor);
        if (! ($me instanceof User)) {
            return $me;
        }
        if (! $me->isAdmin()) {
            return ['ok' => false, 'error' => 'Only the owner can remove shops.'];
        }

        try {
            DB::table('shops')->where('id', $id)->delete();
        } catch (QueryException|\PDOException $e) {
            return $this->dbError($e);
        }

        DataCache::flush();

        return ['ok' => true];
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
