<?php

namespace App\Http\Controllers;

use App\Models\DailyClose;
use App\Models\Shop;
use App\Services\Queries\QuerySupport;
use App\Services\Queries\ShopQueries;
use App\Services\ReconciliationService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ShopController extends Controller
{
    /**
     * GET /shops/{shop} — port of src/app/(app)/shops/[id]/page.tsx.
     * `?date=` selects the business day; an unparsable date falls back to today.
     */
    public function show(Request $request, Shop $shop): View
    {
        $date = $request->query('date');
        $date = is_string($date) && QuerySupport::isDate($date) ? $date : null;

        return view('shops.show', ShopQueries::show($shop->id, $date, $request->user()));
    }

    /** POST /shops/{shop}/close — submitDailyClose() */
    public function submitClose(Request $request, Shop $shop, ReconciliationService $service): RedirectResponse|JsonResponse
    {
        $input = $request->except(['_token', '_method']);
        $input['shopId'] = $shop->id;

        return $this->respond($request, $service->submitClose($input, $request->user()), 'Daily counts submitted.');
    }

    /** POST /shops/{shop}/close/{close}/lock — lockDailyClose() */
    public function lockClose(Request $request, DailyClose $close, ReconciliationService $service): RedirectResponse|JsonResponse
    {
        return $this->respond(
            $request,
            $service->lockClose($close->id, $request->user()),
            'Daily close locked.'
        );
    }

    /** POST /shops/{shop}/counts — submitStockCount() */
    public function submitCount(Request $request, Shop $shop, ReconciliationService $service): RedirectResponse|JsonResponse
    {
        $input = $request->except(['_token', '_method']);
        $input['shopId'] = $shop->id;

        return $this->respond($request, $service->submitCount($input, $request->user()), 'Physical stock count submitted for review.');
    }
}
