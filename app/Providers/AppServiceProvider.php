<?php

namespace App\Providers;

use App\Models\Shop;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // The shell needs the caller's role, name and (for owners) the shop
        // list on every page. Sharing it from a composer keeps page
        // controllers focused on their own data and guarantees the role check
        // is applied identically everywhere.
        View::composer('layouts.app', function ($view): void {
            $user = Auth::user();
            // The shell's "owner UI" (devices, logs, settings, shop switcher)
            // is shared by owners and superadmins alike.
            $isOwner = $user !== null && $user->isAdmin();
            $isSuperAdmin = $user !== null && $user->isSuperAdmin();

            $view->with([
                'me' => $user,
                'isOwner' => $isOwner,
                'isSuperAdmin' => $isSuperAdmin,
                'myShops' => $isOwner
                    ? Shop::orderBy('name')->get(['id', 'name', 'location', 'phone'])
                    : collect(),
                'myShopId' => $user?->shop_id,
                'userName' => $user?->name,
            ]);
        });
    }
}
