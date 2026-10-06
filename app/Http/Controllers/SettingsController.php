<?php

namespace App\Http\Controllers;

use App\Models\Shop;
use App\Models\User;
use App\Services\BackupService;
use App\Services\Queries\SettingsQueries;
use App\Services\ShopService;
use App\Services\StaffService;
use App\Services\StockService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Owner-only settings (port of settings/page.tsx + components/settings/*).
 * Every service re-checks the role against a fresh DB read, and the index
 * query throws 403 for non-owners — RLS parity, fail closed.
 */
class SettingsController extends Controller
{
    /** GET /settings */
    public function index(Request $request): View
    {
        return view('settings.index', SettingsQueries::index($request->user()));
    }

    /** POST /settings/shops — shop-manager.tsx */
    public function createShop(Request $request, ShopService $service): RedirectResponse|JsonResponse
    {
        $input = $request->except(['_token', '_method']);
        $result = $service->create($input, $request->user());

        $name = trim((string) ($input['name'] ?? ''));

        return $this->respond($request, $result, "Shop \"{$name}\" added.");
    }

    /** POST /settings/shops/{shop}/delete — shop-manager.tsx */
    public function deleteShop(Request $request, Shop $shop, ShopService $service): RedirectResponse|JsonResponse
    {
        $result = $service->delete($shop->id, $request->user());

        return $this->respond($request, $result, "Shop \"{$shop->name}\" deleted.");
    }

    /** POST /settings/staff — staff-manager.tsx (create) */
    public function createStaff(Request $request, StaffService $service): RedirectResponse|JsonResponse
    {
        $input = $request->except(['_token', '_method']);
        $result = $service->create($input, $request->user());

        $name = trim((string) ($input['name'] ?? ''));

        return $this->respond($request, $result, "Staff account for {$name} created.");
    }

    /** POST /settings/staff/{user}/deactivate — staff-manager.tsx */
    public function deactivate(Request $request, User $user, StaffService $service): RedirectResponse|JsonResponse
    {
        $result = $service->deactivate($user->id, $request->user());

        return $this->respond($request, $result, "{$user->name} deactivated.");
    }

    /** POST /settings/staff/{user}/reactivate — staff-manager.tsx */
    public function reactivate(Request $request, User $user, StaffService $service): RedirectResponse|JsonResponse
    {
        $result = $service->reactivate($user->id, $request->user());

        return $this->respond($request, $result, "{$user->name} reactivated.");
    }

    /** POST /settings/staff/{user}/reset-password — staff-manager.tsx */
    public function resetPassword(Request $request, User $user, StaffService $service): RedirectResponse|JsonResponse
    {
        $result = $service->resetPassword($user->id, $request->user());

        return $this->respond($request, $result, "Password reset for {$user->name}.");
    }

    /** POST /settings/models/bulk — bulk-add-models.tsx (same RPC as /devices/models/bulk) */
    public function bulkCreateModels(Request $request, StockService $service): RedirectResponse|JsonResponse
    {
        $input = $request->except(['_token', '_method']);

        $result = $service->bulkCreate($input, $request->user());

        $rows = is_array($input['rows'] ?? null) ? $input['rows'] : $input;
        $named = count(array_filter($rows, static fn ($row): bool => is_array($row) && trim((string) ($row['model_name'] ?? '')) !== ''));
        $skipped = count($result['warnings'] ?? []);
        $added = max(0, $named - $skipped);

        $message = $result['ok'] ? "{$added} devices added." : '';

        return $this->respond($request, $result, $message);
    }

    /**
     * GET /settings/backup/download — port of src/app/(app)/settings/backup/route.ts:
     * the same table set, pretty JSON of the raw backup object (no envelope —
     * the original stringified `backup` directly, and restore() reads that
     * shape back), and the original's filename pattern.
     */
    public function downloadBackup(Request $request, BackupService $service)
    {
        $result = $service->export($request->user());

        if (! $result['ok']) {
            // The original answered plain JSON errors for non-owners.
            return response()->json(['error' => $result['error'] ?? 'Forbidden'], 403);
        }

        $json = json_encode($result['backup'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $filename = 'mr-jeff-stock-backup-'.gmdate('Y-m-d').'.json';

        return response($json === false ? '{}' : $json, 200, [
            'Content-Type' => 'application/json',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /** POST /settings/backup/restore — backup-restore.tsx (restoreBackup action) */
    public function restoreBackup(Request $request, BackupService $service): RedirectResponse|JsonResponse
    {
        $file = $request->file('backup');
        if ($file === null || ! $file->isValid()) {
            return $this->respond($request, ['ok' => false, 'error' => 'Backup file is missing or too large.']);
        }

        $raw = file_get_contents($file->getRealPath());
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (! is_array($data)) {
            return $this->respond($request, ['ok' => false, 'error' => 'File is not valid JSON.']);
        }

        $result = $service->restore($data, $request->user());

        // backup-restore.tsx: "Backup restored (N transactions loaded)."
        $fileCount = is_array($data['transactions'] ?? null) ? count($data['transactions']) : 0;
        $loaded = (int) ($result['transactions'] ?? $fileCount);
        $message = $result['ok'] ? "Backup restored ({$loaded} transactions loaded)." : '';

        return $this->respond($request, $result, $message);
    }
}
