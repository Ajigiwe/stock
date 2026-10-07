<?php

namespace App\Http\Controllers;

use App\Models\StockRequest;
use App\Services\StockRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StockRequestController extends Controller
{
    /** POST /requests/{stockRequest}/approve — stock-requests-panel.tsx */
    public function approve(Request $request, StockRequest $stockRequest, StockRequestService $service): RedirectResponse|JsonResponse
    {
        return $this->respond($request, $service->approve($stockRequest->id, $request->user()), 'Change approved.');
    }

    /** POST /requests/{stockRequest}/reject — stock-requests-panel.tsx */
    public function reject(Request $request, StockRequest $stockRequest, StockRequestService $service): RedirectResponse|JsonResponse
    {
        return $this->respond($request, $service->reject($stockRequest->id, $request->user()), 'Change rejected.');
    }

    /** POST /requests/approve-all — stock-requests-panel.tsx */
    public function approveAll(Request $request, StockRequestService $service): RedirectResponse|JsonResponse
    {
        // The original reported how many requests were approved; the service
        // keeps that count internal, so count the queue it is about to drain —
        // scoped to the holder's shop exactly like the service scopes itself.
        $pendingQuery = DB::table('stock_requests')->where('status', 'pending');
        if (! $request->user()->isOwner()) {
            $pendingQuery->where('shop_id', $request->user()->shop_id);
        }
        $pending = (int) $pendingQuery->count();

        $result = $service->approveAll($request->user());

        return $this->respond($request, $result, "{$pending} stock change(s) approved.");
    }
}
