<?php

namespace App\Services\Queries;

use App\Models\User;
use App\Support\DataCache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Port of the /settings page's getCachedShops() + users select.
 *
 * The original redirected non-owners to "/" here (and the RLS policies made
 * the users table unreadable to them anyway), so this fails closed with a 403
 * instead of leaking the staff roster.
 *
 * `staff` deliberately omits `password`/`remember_token` — Supabase's
 * `select("*")` on auth-less profile columns could never include them.
 */
final class SettingsQueries
{
    private function __construct()
    {
    }

    /** @return array{shops: array<int, array<string, mixed>>, staff: array<int, array<string, mixed>>} */
    public static function index(User $actor): array
    {
        if (! $actor->isOwner()) {
            throw new AccessDeniedHttpException;
        }

        return DataCache::remember(
            'settings:owner',
            fn (): array => [
                'shops' => self::shops(),
                'staff' => self::staff(),
            ]
        );
    }

    /** @return array<int, array<string, mixed>> */
    private static function shops(): array
    {
        return DB::table('shops')
            ->orderBy('name')
            ->get()
            ->map(static fn (object $row): array => [
                'id' => (string) $row->id,
                'name' => (string) $row->name,
                'location' => $row->location === null ? null : (string) $row->location,
                'phone' => $row->phone === null ? null : (string) $row->phone,
            ])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private static function staff(): array
    {
        return DB::table('users')
            ->select(['id', 'name', 'email', 'role', 'shop_id', 'active', 'deactivated_at', 'created_at'])
            ->orderBy('name')
            ->get()
            ->map(static fn (object $row): array => [
                'id' => (string) $row->id,
                'name' => (string) $row->name,
                'email' => $row->email === null ? null : (string) $row->email,
                'role' => (string) $row->role,
                'shop_id' => $row->shop_id === null ? null : (string) $row->shop_id,
                'active' => (bool) $row->active,
                'deactivated_at' => $row->deactivated_at === null ? null : (string) $row->deactivated_at,
                'created_at' => $row->created_at === null ? null : (string) $row->created_at,
            ])
            ->all();
    }
}
