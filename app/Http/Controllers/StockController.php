<?php

namespace App\Http\Controllers;

use App\Models\PhoneModel;
use App\Models\Shop;
use App\Services\StockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Stock table mutations (port of createModel/updateModel/adjustStock/
 * bulkAdjustStock from src/lib/actions.ts).
 *
 * Route-bound ids always win over anything the form posted: the shop and model
 * come from the URL, and for attendants the service forces their own shop too.
 */
class StockController extends Controller
{
    /** POST /shops/{shop}/models — add-model-form.tsx */
    public function create(Request $request, Shop $shop, StockService $service): RedirectResponse|JsonResponse
    {
        $input = $request->except(['_token', '_method']);
        $input['shopId'] = $shop->id;

        // add-model-form.tsx: owners hear "<name> added to stock.", attendants
        // hear that the request is waiting for approval.
        $message = $request->user()->isOwner()
            ? trim((string) ($input['modelName'] ?? '')).' added to stock.'
            : 'Request sent — awaiting owner approval.';

        return $this->respond($request, $service->create($input, $request->user()), $message);
    }

    /** POST /shops/{shop}/models/{model} — product-edit-modal.tsx (details) */
    public function update(Request $request, Shop $shop, PhoneModel $model, StockService $service): RedirectResponse|JsonResponse
    {
        $input = $request->except(['_token', '_method']);
        $input['shopId'] = $shop->id;

        return $this->respond($request, $service->update($model->id, $input, $request->user()), 'Product details saved.');
    }

    /** POST /shops/{shop}/models/{model}/adjust — product-edit-modal.tsx (adjust) */
    public function adjust(Request $request, Shop $shop, PhoneModel $model, StockService $service): RedirectResponse|JsonResponse
    {
        $input = $request->except(['_token', '_method']);
        $input['shopId'] = $shop->id;
        // The form posts delta + reason only; the model comes from the URL
        // (§5b), and the service expects it under its TS input key.
        $input['phoneModelId'] = $model->id;

        $message = $request->user()->isOwner()
            ? 'Stock updated.'
            : 'Stock change sent — awaiting owner approval.';

        return $this->respond($request, $service->adjust($input, $request->user()), $message);
    }

    /** POST /shops/{shop}/models/bulk — bulk-stock-modal.tsx */
    public function bulkAdjust(Request $request, Shop $shop, StockService $service): RedirectResponse|JsonResponse
    {
        $input = $request->except(['_token', '_method']);
        $input['shopId'] = $shop->id;

        // The original echoed the RPC's changes count back; the port has no
        // count in the result, so the submitted item count stands in for it.
        $items = array_filter((array) ($input['items'] ?? []), 'is_array');
        $changes = count($items);

        $message = $request->user()->isOwner()
            ? "{$changes} stock change(s) applied."
            : "{$changes} change(s) sent — awaiting owner approval.";

        return $this->respond($request, $service->bulkAdjust($input, $request->user()), $message);
    }
}
