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
        return view('settings.index', SettingsQueries::index($request->user()) + [
            'simTypes' => StockService::SIM_TYPES,
            'categories' => StockService::CATEGORIES,
        ]);
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

    /** POST /settings/staff/{user}/permissions — owner-granted staff capabilities */
    public function updatePermissions(Request $request, User $user, StaffService $service): RedirectResponse|JsonResponse
    {
        $result = $service->setPermissions($user->id, $request->except(['_token', '_method']), $request->user());

        return $this->respond($request, $result, "Permissions updated for {$user->name}.");
    }

    /** POST /settings/staff/{user}/shop — move an attendant to another shop */
    public function moveShop(Request $request, User $user, StaffService $service): RedirectResponse|JsonResponse
    {
        $result = $service->moveShop($user->id, $request->except(['_token', '_method']), $request->user());

        $message = $result['ok']
            ? ($result['shopName'] ?? null) !== null
                ? "{$user->name} moved to {$result['shopName']}."
                : "{$user->name} has no shop for now."
            : '';

        return $this->respond($request, $result, $message);
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
     * POST /settings/models/import — bulk product upload from a CSV file.
     *
     * File I/O lives here (like restoreBackup's JSON upload); the parsed rows
     * take the exact shape the bulk-add form posts, so StockService::bulkCreate
     * is the single validation path for both entry points.
     */
    public function importModels(Request $request, StockService $service): RedirectResponse|JsonResponse
    {
        $file = $request->file('csv');
        if ($file === null || ! $file->isValid()) {
            return $this->respond($request, ['ok' => false, 'error' => 'CSV file is missing or too large.']);
        }

        $rows = $this->parseCsv($file->getRealPath());
        if (! is_array($rows)) {
            return $this->respond($request, ['ok' => false, 'error' => $rows]);
        }

        $result = $service->bulkCreate([
            'shopId' => $request->input('shopId'),
            'rows' => $rows,
        ], $request->user());

        $skipped = count($result['warnings'] ?? []);
        $added = max(0, count($rows) - $skipped);
        $message = $result['ok'] ? "{$added} devices imported." : '';

        return $this->respond($request, $result, $message);
    }

    /**
     * GET /settings/models/import/template — header row + one example, so a
     * spreadsheet can be laid out to match parseCsv()'s expectations.
     */
    public function importTemplate(Request $request)
    {
        if (! $request->user()?->isAdmin()) {
            abort(403);
        }

        $csv = "model_name,condition,sim_type,color,category,cost_price,sale_price,opening_stock,low_stock_threshold\n"
            ."iPhone 13 128GB,used,esim,Blue,phone,1500,2000,5,2\n";

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="mr-jeff-stock-import-template.csv"',
        ]);
    }

    /**
     * CSV file → bulk-add rows; returns the rows or a user-facing error.
     *
     * - UTF-8 with or without BOM; `,` or `;` delimited (sniffed on the header).
     * - Header cells are lowercased and space/hyphen-normalised to snake_case;
     *   `name` is accepted as an alias for `model_name`.
     * - Only bulk-add columns matter, but all header columns are carried along —
     *   bulkCreate() reads the keys it knows and ignores the rest.
     * - Blank lines and fully blank rows are dropped silently.
     *
     * @return array<int, array<string, string>>|string
     */
    private function parseCsv(string $path): array|string
    {
        $handle = @fopen($path, 'r');
        if ($handle === false) {
            return 'CSV file is missing or too large.';
        }

        try {
            $first = fgets($handle);
            if ($first === false) {
                return 'No rows to import.';
            }
            if (str_starts_with($first, "\xEF\xBB\xBF")) {
                $first = substr($first, 3);
            }

            $delimiter = str_contains($first, ';') && ! str_contains($first, ',') ? ';' : ',';

            $header = [];
            foreach ((array) str_getcsv(rtrim($first, "\r\n"), $delimiter) as $cell) {
                $name = strtolower(trim((string) $cell));
                $header[] = str_replace([' ', '-'], '_', $name);
            }
            $header = array_map(
                static fn (string $name): string => $name === 'name' ? 'model_name' : $name,
                $header
            );
            if (! in_array('model_name', $header, true)) {
                return 'CSV must include a model_name column.';
            }

            rewind($handle);
            $rows = [];
            $line = 0;
            while (($cells = fgetcsv($handle, null, $delimiter)) !== false) {
                $line++;
                if ($line === 1) {
                    continue; // the header, read above
                }
                if ($cells === [null]) {
                    continue; // blank line
                }

                $row = [];
                foreach ($header as $i => $name) {
                    if ($name === '') {
                        continue;
                    }
                    $row[$name] = trim((string) ($cells[$i] ?? ''));
                }

                // Skip only a row that is blank in every column; a row with a
                // missing optional value still goes to bulkCreate, which
                // reports it (or defaults it) exactly like the form would.
                foreach ($row as $value) {
                    if ($value !== '') {
                        $rows[] = $row;

                        break;
                    }
                }
            }

            return $rows;
        } finally {
            fclose($handle);
        }
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

    /**
     * POST /settings/wipe — empty every business table, keep login accounts.
     * The typed WIPE confirmation is enforced inside BackupService::wipe().
     */
    public function wipe(Request $request, BackupService $service): RedirectResponse|JsonResponse
    {
        $result = $service->wipe($request->except(['_token', '_method']), $request->user());

        $deleted = (int) ($result['deleted'] ?? 0);
        $message = $result['ok']
            ? ($deleted > 0 ? "All data wiped ({$deleted} rows removed)." : 'All data wiped.')
            : '';

        return $this->respond($request, $result, $message);
    }
}
