<?php

namespace App\Http\Controllers;

use App\Services\Queries\DashboardQueries;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /**
     * GET / — port of src/app/(app)/page.tsx.
     *
     * `?period=` and `?shop=` arrive as query strings; unknown values fall back
     * to the same defaults the original page used instead of erroring.
     */
    public function index(Request $request): View
    {
        $period = $request->query('period');
        $period = is_string($period) && in_array($period, ['today', '7d', '30d'], true) ? $period : null;

        $shop = $request->query('shop');
        $shop = is_string($shop) && $shop !== '' ? $shop : null;

        return view('dashboard', DashboardQueries::index($period, $shop, $request->user()));
    }
}
