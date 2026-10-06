<?php

namespace App\Http\Controllers;

use App\Services\Queries\DeviceQueries;
use App\Services\StockService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DeviceController extends Controller
{
    /**
     * GET /devices — owner's cross-shop catalogue matrix
     * (port of devices/page.tsx + devices-table.tsx). The query throws 403 for
     * non-owners, matching the original's redirect/RLS behaviour.
     */
    public function index(Request $request): View
    {
        return view('devices.index', DeviceQueries::index($request->user()));
    }

    /** POST /devices/models/bulk — bulkCreateModels() (owner) */
    public function bulkCreate(Request $request, StockService $service): RedirectResponse|JsonResponse
    {
        $input = $request->except(['_token', '_method']);

        $result = $service->bulkCreate($input, $request->user());

        // bulk-add-models.tsx: "{added} devices added." — added = named rows
        // minus the ones the service reported as skipped.
        $rows = is_array($input['rows'] ?? null) ? $input['rows'] : $input;
        $named = count(array_filter($rows, static fn ($row): bool => is_array($row) && trim((string) ($row['model_name'] ?? '')) !== ''));
        $skipped = count($result['warnings'] ?? []);
        $added = max(0, $named - $skipped);

        $message = $result['ok'] ? "{$added} devices added." : '';

        return $this->respond($request, $result, $message);
    }
}
