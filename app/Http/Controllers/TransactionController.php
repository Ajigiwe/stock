<?php

namespace App\Http\Controllers;

use App\Models\SwappedPhone;
use App\Models\Transaction;
use App\Services\Queries\TransactionQueries;
use App\Services\StockService;
use App\Services\TransactionService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TransactionController extends Controller
{
    /**
     * GET /transactions/new — the POS page (port of transactions/new/page.tsx).
     * A shopless attendant has nowhere to record against: the original sent
     * them to "/", so we send them to the dashboard.
     */
    public function create(Request $request): View|RedirectResponse
    {
        $shop = $request->query('shop');
        $payload = TransactionQueries::create(
            is_string($shop) && $shop !== '' ? $shop : null,
            $request->user()
        );

        if ($payload['defaultShopId'] === null) {
            return redirect()->route('dashboard');
        }

        // The transaction type is picked in the sidebar (?type=sale|swap|repair)
        // rather than on the page. A failed submit redisplays without the query
        // string, so fall back to the just-posted type before defaulting.
        $queryType = $request->query('type');
        $oldType = $request->old('type');
        $payload['type'] = is_string($queryType) && in_array($queryType, ['sale', 'swap', 'repair'], true)
            ? $queryType
            : (is_string($oldType) && in_array($oldType, ['sale', 'swap', 'repair'], true) ? $oldType : 'sale');
        $payload['simLabels'] = StockService::SIM_TYPES;
        $payload['catLabels'] = StockService::CATEGORIES;

        return view('transactions.new', $payload);
    }

    /** POST /transactions — recordTransaction() */
    public function store(Request $request, TransactionService $service): RedirectResponse|JsonResponse
    {
        $input = $request->except(['_token', '_method']);
        $result = $service->record($input, $request->user());

        // transaction-form.tsx showed a warning instead of the success toast
        // when one came back — mirror that by flashing no success text.
        $clean = empty($result['warning']) && empty($result['warnings']);
        $message = $result['ok'] && $clean ? 'Transaction recorded.' : '';

        // Stay on the POS page with a fresh ticket: the success flash is the
        // toast (partials/flash floats top-center and auto-dismisses), and a
        // clean reload resets the Alpine form for the next customer. The
        // original pushed to the shop page instead. Attendants never post a
        // shopId (their own is forced), so fall back to it.
        $shopId = is_string($input['shopId'] ?? null) && $input['shopId'] !== ''
            ? $input['shopId']
            : $request->user()->shop_id;
        $type = is_string($input['type'] ?? null) && $input['type'] !== ''
            ? $input['type']
            : null;
        $redirectTo = route('transactions.create', array_filter([
            'shop' => $shopId,
            'type' => $type,
        ]));

        return $this->respond($request, $result, $message, $redirectTo);
    }

    /**
     * GET /transactions/{transaction} — receipt page.
     * A transaction from another shop is simply invisible (RLS parity), which
     * answers 404 exactly like a missing id.
     */
    public function show(Request $request, Transaction $transaction): View|JsonResponse
    {
        $payload = TransactionQueries::show($transaction->id, $request->user());

        if ($payload === []) {
            return response()->view('transactions.show', [], 404);
        }

        return view('transactions.show', $payload);
    }

    /** POST /transactions/{transaction}/review — reviewTransaction() */
    public function review(Request $request, Transaction $transaction, TransactionService $service): RedirectResponse|JsonResponse
    {
        $decision = $request->input('decision');
        $reason = $request->input('reason');
        $reason = is_string($reason) && $reason !== '' ? $reason : null;

        $result = $service->review($transaction->id, is_string($decision) ? $decision : '', $reason, $request->user());

        $message = $decision === 'approve'
            ? 'Discount approved.'
            : 'Transaction rejected and stock restored.';

        return $this->respond($request, $result, $message);
    }

    /** POST /transactions/{transaction}/void — voidTransaction() */
    public function void(Request $request, Transaction $transaction, TransactionService $service): RedirectResponse|JsonResponse
    {
        $reason = $request->input('reason');
        $reason = is_string($reason) ? $reason : '';

        $result = $service->void($transaction->id, $reason, $request->user());

        return $this->respond($request, $result, 'Transaction voided and audit history preserved.');
    }

    /** POST /swapped-phones/{phone}/status — setSwappedPhoneStatus() */
    public function swappedStatus(Request $request, SwappedPhone $phone, TransactionService $service): RedirectResponse|JsonResponse
    {
        $status = $request->input('status');
        $result = $service->setSwappedStatus($phone->id, is_string($status) ? $status : '', $request->user());

        return $this->respond($request, $result, 'Trade-in updated.');
    }
}
