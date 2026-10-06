<?php

namespace App\Http\Controllers;

use App\Models\StockCount;
use App\Services\Queries\ReportQueries;
use App\Services\ReconciliationService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    /** GET /reports — port of reports/page.tsx (filters come from the query string). */
    public function index(Request $request): View
    {
        return view('reports.index', ReportQueries::index($request->user()));
    }

    /**
     * GET /reports/export — CSV download, port of src/app/reports/export/route.ts
     * byte-for-byte: same column order, the same formula-injection `esc()`, a
     * UTF-8 BOM, "\n" line endings and the same filename pattern.
     */
    public function export(Request $request)
    {
        $export = ReportQueries::export($request->user());

        // A leading =, +, - or @ makes spreadsheet apps treat the cell as a
        // formula; quote and prefix those, double embedded quotes.
        $esc = static function (mixed $value): string {
            $s = $value === null ? '' : (string) $value;
            if (preg_match('/^[=+\-@\t\r]/', $s) === 1) {
                $s = "'".$s;
            }

            return preg_match('/[",\n]/', $s) === 1 ? '"'.str_replace('"', '""', $s).'"' : $s;
        };

        $lines = [implode(',', array_map($esc, $export['headers']))];
        foreach ($export['rows'] as $row) {
            $lines[] = implode(',', array_map($esc, $row));
        }

        $date = static function (mixed $value): string {
            $value = is_string($value) ? $value : '';

            return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 && strtotime($value) !== false
                ? $value
                : 'all';
        };
        $filename = 'report-'.$date($request->query('from')).'-'.$date($request->query('to')).'.csv';

        // "\uFEFF" + rows joined with "\n" — exactly what the original emitted.
        return response("\u{FEFF}".implode("\n", $lines), 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /** POST /reports/counts/{count}/approve — stock-count-panel.tsx */
    public function approveCount(Request $request, StockCount $count, ReconciliationService $service): RedirectResponse|JsonResponse
    {
        return $this->respond($request, $service->approveCount($count->id, $request->user()), 'Stock count approved.');
    }

    /** POST /reports/counts/{count}/apply — stock-count-panel.tsx */
    public function applyCount(Request $request, StockCount $count, ReconciliationService $service): RedirectResponse|JsonResponse
    {
        return $this->respond($request, $service->applyCount($count->id, $request->user()), 'Stock correction applied and logged.');
    }
}
