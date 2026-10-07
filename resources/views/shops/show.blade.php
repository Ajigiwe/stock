@extends('layouts.app')

@php
    $shopId = $summary['shop']['id'];
    $shopName = $summary['shop']['name'];
    $shopLocation = $summary['shop']['location'];
    $todayIso = \App\Support\Format::today();
    $prevDate = \App\Services\Queries\QuerySupport::addDays($date, -1);
    $nextDate = \App\Services\Queries\QuerySupport::addDays($date, 1);

    $closingTitle = $isToday ? 'Today\'s closing' : 'Closing';
    $closingSubtitle = $dateLabel.' — units out, split by sale vs swap';
    $txTitle = $isToday ? 'Today\'s transactions' : 'Transactions';
    $txSubtitle = count($transactions).' recorded on '.$dateLabel;
    $requestsTitle = $canApproveRequests ? 'Pending stock changes' : 'Your pending changes';
    $requestsSubtitle = count($pendingRequests) === 0
        ? 'Nothing awaiting action'
        : ($canApproveRequests ? 'Awaiting your approval' : 'Awaiting owner approval');
    $swappedSubtitle = count($swappedPhones) === 0
        ? 'Trade-ins taken during swaps'
        : count($swappedPhones).' trade-in'.(count($swappedPhones) === 1 ? '' : 's').' received';

    $lowStockLine = collect($summary['low_stock'])
        ->map(fn (array $m): string => $m['model_name'].' ('.$m['available'].')')
        ->implode(', ');

    $shareLines = [
        '*'.$shopName.'* — '.$dateLabel,
        'Sales: '.(int) $summary['total_sales'].' · Swaps: '.(int) $summary['total_swaps'].' · Repairs: '.(int) $summary['total_repairs'],
        'Revenue: '.\App\Support\Format::money($summary['revenue']),
    ];
    if (count($summary['rows']) > 0) {
        $shareLines[] = 'Units out:';
        foreach ($summary['rows'] as $shareRow) {
            $shareLines[] = '• '.$shareRow['model_name'].' ('.$shareRow['condition'].'): '.($shareRow['sold'] + $shareRow['swapped_out']);
        }
    }
    $shareText = implode("\n", $shareLines);
    $shareUrl = 'https://wa.me/?text='.rawurlencode($shareText);

    // Stock rows behind the client-side filters (search / condition / low stock).
    $stockRows = [];
    $stockUnits = 0;
    $stockCost = 0.0;
    $stockRetail = 0.0;
    foreach ($stock as $model) {
        $stockRows[] = [
            'id' => $model['id'],
            'name' => $model['model_name'],
            'cond' => $model['condition'],
            'avail' => $model['available'],
            'thr' => $model['low_stock_threshold'],
            'cost' => $model['cost_price'],
            'sale' => $model['sale_price'],
            'opening' => $model['opening_stock'],
            'bought' => $model['bought_in'],
        ];
        $stockUnits += $model['available'];
        if ($model['cost_price'] !== null) {
            $stockCost += $model['cost_price'] * $model['available'];
        }
        if ($model['sale_price'] !== null) {
            $stockRetail += $model['sale_price'] * $model['available'];
        }
    }
    $stockIds = array_column($stockRows, 'id');
    $stockAvails = array_column($stockRows, 'avail');
    $targetsJson = $stockIds === [] ? [] : array_combine($stockIds, $stockAvails);

    // Recent adjustments grouped per model for the product modal.
    $adjustmentsByModel = [];
    foreach ($adjustments as $adjustment) {
        $adjustmentsByModel[$adjustment['phone_model_id']][] = $adjustment;
    }

    // End-of-day recon rows, merged with the physical count taken for this day.
    $countedByModel = [];
    if ($stockCountForDate !== null) {
        foreach ($stockCountForDate['items'] as $countItem) {
            $countedByModel[$countItem['phone_model_id']] = $countItem['counted_qty'];
        }
    }
    $reconRows = [];
    foreach ($stockRecon['rows'] as $reconRow) {
        $counted = $countedByModel[$reconRow['phone_model_id']] ?? null;
        $variance = $counted === null ? null : $counted - $reconRow['closing'];
        $touched = $reconRow['sold'] > 0
            || $reconRow['pending'] > 0
            || $reconRow['trade_in'] > 0
            || $reconRow['restocked'] > 0
            || $reconRow['removed'] > 0
            || $counted !== null;
        $reconRows[] = $reconRow + ['counted' => $counted, 'variance' => $variance, 'touched' => $touched];
    }
    usort($reconRows, static function (array $a, array $b): int {
        $cmp = (int) $b['touched'] - (int) $a['touched'];
        if ($cmp !== 0) {
            return $cmp;
        }
        $cmp = strcasecmp($a['model_name'], $b['model_name']);

        return $cmp !== 0 ? $cmp : strcmp($a['condition'], $b['condition']);
    });
    $touchedRows = array_values(array_filter($reconRows, fn (array $r): bool => $r['touched']));
    $untouchedRows = array_values(array_filter($reconRows, fn (array $r): bool => ! $r['touched']));

    // Daily close form state.
    $isLocked = $dailyClose !== null && $dailyClose['status'] === 'locked';
    $isOpen = $dailyClose !== null && $dailyClose['status'] === 'open';
    $expectedCash = (float) ($dailyClose['expected_cash'] ?? 0);
    $expectedMobile = (float) ($dailyClose['expected_mobile_money'] ?? 0);
    $expectedOther = (float) ($dailyClose['expected_other'] ?? 0);
    $cashInitial = $dailyClose !== null && $dailyClose['counted_cash'] !== null
        ? (string) $dailyClose['counted_cash'] : '';
    $mobileInitial = $dailyClose !== null && $dailyClose['counted_mobile_money'] !== null
        ? (string) $dailyClose['counted_mobile_money'] : '';
    $otherInitial = $dailyClose === null || $dailyClose['counted_other'] === null
        ? '0' : (string) $dailyClose['counted_other'];

    // Latest physical count extras.
    $latestVarianceItems = $latestStockCount === null ? [] : array_values(array_filter(
        $latestStockCount['items'],
        fn (array $i): bool => $i['expected_qty'] !== $i['counted_qty']
    ));
    $latestStatusClass = $latestStockCount !== null && $latestStockCount['status'] === 'applied'
        ? 'badge-ok' : 'badge-brand';

    // Transaction headline labels: −/− model names, like the original list.
    $txLabels = [];
    foreach ($transactions as $tx) {
        $txLabels[$tx['id']] = collect($tx['items'])
            ->map(fn (array $i): string => ($i['direction'] === 'out' ? '−' : '+').$i['model_name'])
            ->implode(', ');
    }
@endphp

@section('title', $shopName.' — Mr Jeff Stock')
@section('mobileTitle', $shopName)

@section('content')
    <div class="space-y-6">
        {{-- Header + day picker --}}
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-ink">{{ $shopName }}</h1>
                <p class="text-sm text-mute">{{ $shopLocation ?? '—' }} · {{ $dateLabel }}</p>
            </div>
            <div class="flex flex-wrap items-end gap-2">
                <form method="GET" action="{{ route('shop.show', ['shop' => $shopId]) }}" class="flex items-end gap-2">
                    <div>
                        <span class="mb-1 block text-xs font-medium text-mute">Day</span>
                        <div class="flex items-center gap-1">
                            <a href="{{ route('shop.show', ['shop' => $shopId, 'date' => $prevDate]) }}"
                               aria-label="Previous day"
                               class="inline-flex h-10 w-9 items-center justify-center rounded-lg border border-line bg-white text-mute hover:bg-paper">←</a>
                            <input type="date" name="date" value="{{ $date }}" max="{{ $todayIso }}"
                                   class="h-10 rounded-lg border border-line bg-white px-2 text-sm text-ink focus:border-mute focus:outline-none">
                            <a href="{{ route('shop.show', ['shop' => $shopId, 'date' => $nextDate]) }}"
                               aria-label="Next day"
                               class="inline-flex h-10 w-9 items-center justify-center rounded-lg border border-line bg-white text-mute hover:bg-paper">→</a>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary h-10 px-4 text-sm">Show</button>
                </form>
                <a href="{{ route('transactions.create', ['shop' => $shopId]) }}"
                   class="inline-flex h-10 items-center rounded-lg bg-brand px-4 text-sm font-medium text-white transition hover:bg-brand-deep">
                    Record transaction
                </a>
            </div>
        </div>

        @if ($lowStockLine !== '')
            <div class="rounded-lg border border-brand bg-brand-tint px-4 py-3 text-sm text-brand">
                <span class="font-semibold">Low stock:</span> {{ $lowStockLine }}
            </div>
        @endif

        {{-- Day summary --}}
        <x-shop-card :title="$closingTitle" :subtitle="$closingSubtitle">
            <x-slot name="actions">
                <div x-data="{ text: {{ \Illuminate\Support\Js::from($shareText) }}, wa: {{ \Illuminate\Support\Js::from($shareUrl) }}, share() { if (navigator.share) { navigator.share({ text: this.text }).catch(() => window.open(this.wa, '_blank', 'noopener')); } else { window.open(this.wa, '_blank', 'noopener'); } } }">
                    <button type="button" @click="share()" class="btn btn-secondary btn-sm">Share summary</button>
                </div>
            </x-slot>

            <div class="mb-3 flex flex-wrap gap-3 text-sm text-mute">
                <span>Sales: <b>{{ $summary['total_sales'] }}</b></span>
                <span>Swaps: <b>{{ $summary['total_swaps'] }}</b></span>
                <span>Repairs: <b>{{ $summary['total_repairs'] }}</b></span>
                <span>Revenue: <b>{{ \App\Support\Format::money($summary['revenue']) }}</b></span>
            </div>

            @if (count($summary['rows']) === 0)
                <div class="empty-state">No units out today yet.</div>
            @else
                <div class="hidden overflow-x-auto sm:block">
                    <table class="table-base">
                        <thead>
                            <tr>
                                <th>Model</th>
                                <th>Condition</th>
                                <th class="text-right">Sold</th>
                                <th class="text-right">Swapped out</th>
                                <th class="text-right">Total out</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($summary['rows'] as $summaryRow)
                                <tr>
                                    <td class="font-medium text-ink">{{ $summaryRow['model_name'] }}</td>
                                    <td>
                                        <span class="{{ $summaryRow['condition'] === 'new' ? 'badge-brand' : 'badge-muted' }}">{{ $summaryRow['condition'] }}</span>
                                    </td>
                                    <td class="text-right">{{ $summaryRow['sold'] }}</td>
                                    <td class="text-right">{{ $summaryRow['swapped_out'] }}</td>
                                    <td class="text-right font-semibold">{{ $summaryRow['sold'] + $summaryRow['swapped_out'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <ul class="space-y-2 sm:hidden">
                    @foreach ($summary['rows'] as $summaryRow)
                        <li class="flex items-center justify-between gap-3 rounded-lg border border-line bg-paper px-3 py-2">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="truncate text-sm font-medium text-ink">{{ $summaryRow['model_name'] }}</span>
                                    <span class="{{ $summaryRow['condition'] === 'new' ? 'badge-brand' : 'badge-muted' }}">{{ $summaryRow['condition'] }}</span>
                                </div>
                                <div class="mt-0.5 text-xs text-mute">
                                    Sold {{ $summaryRow['sold'] }} · Swapped out {{ $summaryRow['swapped_out'] }}
                                </div>
                            </div>
                            <div class="shrink-0 text-sm font-semibold text-ink">{{ $summaryRow['sold'] + $summaryRow['swapped_out'] }}</div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-shop-card>

        {{-- End-of-day stock reconciliation --}}
        <x-shop-card title="End-of-day stock reconciliation" :subtitle="'Morning stock → bought today → left · '.$dateLabel">
            <div class="space-y-4">
                <div class="grid grid-cols-3 gap-3">
                    <div class="rounded-xl border border-line bg-paper p-3">
                        <div class="text-xs font-medium text-mute">In the morning</div>
                        <div class="mt-1 text-2xl font-bold text-ink">{{ $stockRecon['totalOpening'] }}</div>
                    </div>
                    <div class="rounded-xl border border-brand bg-brand-tint p-3">
                        <div class="text-xs font-medium text-mute">Bought today</div>
                        <div class="mt-1 text-2xl font-bold text-brand">{{ $stockRecon['totalSold'] }}</div>
                    </div>
                    <div class="rounded-xl border border-line bg-paper p-3">
                        <div class="text-xs font-medium text-mute">Left now</div>
                        <div class="mt-1 text-2xl font-bold text-ink">{{ $stockRecon['totalClosing'] }}</div>
                    </div>
                </div>

                @if (count($reconRows) === 0)
                    <div class="empty-state">No stock models for this shop.</div>
                @else
                    <div class="hidden overflow-x-auto md:block">
                        <table class="table-base">
                            <thead>
                                <tr>
                                    <th>Model</th>
                                    <th class="text-right">Morning</th>
                                    <th class="text-right">Bought</th>
                                    <th class="text-right">Left now</th>
                                    <th class="text-right">Counted</th>
                                    <th class="text-right">Δ</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($touchedRows as $reconRow)
                                    @php
                                        $reconNotes = [];
                                        if ($reconRow['trade_in']) {
                                            $reconNotes[] = '+'.$reconRow['trade_in'].' trade-in';
                                        }
                                        if ($reconRow['restocked']) {
                                            $reconNotes[] = '+'.$reconRow['restocked'].' restocked';
                                        }
                                        if ($reconRow['removed']) {
                                            $reconNotes[] = '−'.$reconRow['removed'].' removed';
                                        }
                                    @endphp
                                    <tr>
                                        <td>
                                            <div class="flex flex-wrap items-center gap-2">
                                                <span class="font-medium text-ink">{{ $reconRow['model_name'] }}</span>
                                                <span class="{{ $reconRow['condition'] === 'new' ? 'badge-brand' : 'badge-muted' }}">{{ $reconRow['condition'] }}</span>
                                            </div>
                                            @if (count($reconNotes) > 0)
                                                <div class="mt-0.5 text-xs text-mute">{{ implode(' · ', $reconNotes) }}</div>
                                            @endif
                                        </td>
                                        <td class="text-right">{{ $reconRow['opening'] }}</td>
                                        <td class="text-right">
                                            <span class="font-semibold">{{ $reconRow['sold'] }}</span>
                                            @if ($reconRow['pending'] > 0)
                                                <span class="ml-1 text-xs font-medium text-warnstock">+{{ $reconRow['pending'] }} review</span>
                                            @endif
                                        </td>
                                        <td class="text-right font-semibold text-ink">{{ $reconRow['closing'] }}</td>
                                        <td class="text-right">
                                            @if ($reconRow['counted'] === null)
                                                <span class="text-mute">—</span>
                                            @else
                                                {{ $reconRow['counted'] }}
                                            @endif
                                        </td>
                                        <td class="text-right">
                                            @if ($reconRow['counted'] === null)
                                                <span class="text-mute">—</span>
                                            @elseif ($reconRow['variance'] === 0)
                                                <span class="badge-ok">match</span>
                                            @else
                                                <span class="badge-danger">{{ $reconRow['variance'] > 0 ? '+'.$reconRow['variance'] : $reconRow['variance'] }}</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach

                                @if (count($untouchedRows) > 0)
                                    <tr>
                                        <td colspan="6" class="pt-3 text-xs font-medium uppercase tracking-wide text-mute">
                                            No movement — {{ count($untouchedRows) }} model{{ count($untouchedRows) === 1 ? '' : 's' }} unchanged
                                        </td>
                                    </tr>
                                @endif

                                @foreach ($untouchedRows as $reconRow)
                                    <tr class="opacity-60">
                                        <td>
                                            <div class="flex flex-wrap items-center gap-2">
                                                <span class="font-medium text-ink">{{ $reconRow['model_name'] }}</span>
                                                <span class="{{ $reconRow['condition'] === 'new' ? 'badge-brand' : 'badge-muted' }}">{{ $reconRow['condition'] }}</span>
                                            </div>
                                        </td>
                                        <td class="text-right">{{ $reconRow['opening'] }}</td>
                                        <td class="text-right">{{ $reconRow['sold'] }}</td>
                                        <td class="text-right">{{ $reconRow['closing'] }}</td>
                                        <td class="text-right">
                                            @if ($reconRow['counted'] === null)
                                                <span class="text-mute">—</span>
                                            @else
                                                {{ $reconRow['counted'] }}
                                            @endif
                                        </td>
                                        <td class="text-right">
                                            @if ($reconRow['counted'] === null)
                                                <span class="text-mute">—</span>
                                            @elseif ($reconRow['variance'] === 0)
                                                <span class="badge-ok">match</span>
                                            @else
                                                <span class="badge-danger">{{ $reconRow['variance'] > 0 ? '+'.$reconRow['variance'] : $reconRow['variance'] }}</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <ul class="space-y-2 md:hidden">
                        @foreach ($touchedRows as $reconRow)
                            <li><x-shop-recon-card :recon-row="$reconRow" /></li>
                        @endforeach
                        @if (count($untouchedRows) > 0)
                            <li class="px-1 pt-2 text-xs font-medium uppercase tracking-wide text-mute">
                                No movement — {{ count($untouchedRows) }} model{{ count($untouchedRows) === 1 ? '' : 's' }} unchanged
                            </li>
                        @endif
                        @foreach ($untouchedRows as $reconRow)
                            <li class="opacity-60"><x-shop-recon-card :recon-row="$reconRow" /></li>
                        @endforeach
                    </ul>
                @endif

                @if ($stockCountForDate !== null)
                    <p class="text-xs text-mute">
                        The physical count submitted for this day is included — any row that doesn't show
                        <span class="badge-ok">match</span> means what is on the shelf differs from the ledger, so it needs a review.
                    </p>
                @else
                    <p class="text-xs text-mute">
                        No physical count was recorded for this day yet — submit one in the card below to compare shelf stock against this ledger.
                    </p>
                @endif
            </div>
        </x-shop-card>

        {{-- Daily close --}}
        <x-shop-card title="Daily close" :subtitle="$dateLabel.' · cash and mobile-money reconciliation'">
            <div class="space-y-3"
                 x-data="{
                     cash: {{ \Illuminate\Support\Js::from($cashInitial) }},
                     mobile: {{ \Illuminate\Support\Js::from($mobileInitial) }},
                     other: {{ \Illuminate\Support\Js::from($otherInitial) }},
                     expCash: {{ \Illuminate\Support\Js::from($expectedCash) }},
                     expMobile: {{ \Illuminate\Support\Js::from($expectedMobile) }},
                     expOther: {{ \Illuminate\Support\Js::from($expectedOther) }},
                     fmtCash: {{ \Illuminate\Support\Js::from(\App\Support\Format::money($expectedCash)) }},
                     fmtMobile: {{ \Illuminate\Support\Js::from(\App\Support\Format::money($expectedMobile)) }},
                     fmtOther: {{ \Illuminate\Support\Js::from(\App\Support\Format::money($expectedOther)) }},
                     money(n) { return 'GHS ' + Number(n ?? 0).toLocaleString('en-GH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) },
                     diff(val, exp) { return val === '' ? null : Number(val) - exp },
                     strong(v) { return v !== null && Math.abs(v) > 0.009 },
                     line(fmt, val, exp) {
                         const d = this.diff(val, exp);
                         if (d === null) return 'Expected ' + fmt;
                         return 'Expected ' + fmt + ' · ' + (d >= 0 ? '+' : '') + this.money(d);
                     },
                     get cashLine() { return this.line(this.fmtCash, this.cash, this.expCash) },
                     get mobileLine() { return this.line(this.fmtMobile, this.mobile, this.expMobile) },
                     get otherLine() { return this.line(this.fmtOther, this.other, this.expOther) },
                     get hasVariance() {
                         return this.strong(this.diff(this.cash, this.expCash))
                             || this.strong(this.diff(this.mobile, this.expMobile))
                             || this.strong(this.diff(this.other, this.expOther));
                     }
                 }">
                @if ($dailyClose !== null)
                    <div class="flex flex-wrap items-center gap-2 text-xs">
                        <span class="{{ $isLocked ? 'badge-ok' : 'badge-brand' }}">{{ $isLocked ? 'Locked' : 'Open' }}</span>
                        <span x-cloak x-show="hasVariance" class="badge-danger">Variance needs review</span>
                    </div>
                @endif

                <form method="POST" action="{{ route('close.submit', ['shop' => $shopId]) }}">
                    @csrf
                    <input type="hidden" name="date" value="{{ $date }}">
                    <div class="grid gap-3 sm:grid-cols-3">
                        <div>
                            <label class="label" for="counted-cash">Cash counted</label>
                            <input id="counted-cash" type="number" min="0" step="0.01" name="countedCash"
                                   class="input" x-model="cash" value="{{ old('countedCash', $cashInitial) }}"
                                   @if ($isLocked) disabled @endif>
                            <div class="mt-1 text-xs"
                                 :class="strong(diff(cash, expCash)) ? 'font-semibold text-lowstock' : 'text-mute'"
                                 x-text="cashLine">Expected {{ \App\Support\Format::money($expectedCash) }}</div>
                        </div>
                        <div>
                            <label class="label" for="counted-mobile">Mobile money counted</label>
                            <input id="counted-mobile" type="number" min="0" step="0.01" name="countedMobileMoney"
                                   class="input" x-model="mobile" value="{{ old('countedMobileMoney', $mobileInitial) }}"
                                   @if ($isLocked) disabled @endif>
                            <div class="mt-1 text-xs"
                                 :class="strong(diff(mobile, expMobile)) ? 'font-semibold text-lowstock' : 'text-mute'"
                                 x-text="mobileLine">Expected {{ \App\Support\Format::money($expectedMobile) }}</div>
                        </div>
                        <div>
                            <label class="label" for="counted-other">Other counted</label>
                            <input id="counted-other" type="number" min="0" step="0.01" name="countedOther"
                                   class="input" x-model="other" value="{{ old('countedOther', $otherInitial) }}"
                                   @if ($isLocked) disabled @endif>
                            <div class="mt-1 text-xs"
                                 :class="strong(diff(other, expOther)) ? 'font-semibold text-lowstock' : 'text-mute'"
                                 x-text="otherLine">Expected {{ \App\Support\Format::money($expectedOther) }}</div>
                        </div>
                    </div>

                    <div class="mt-3">
                        <label class="label" for="close-notes">Notes</label>
                        <input id="close-notes" type="text" name="notes" class="input"
                               value="{{ old('notes', $dailyClose['notes'] ?? '') }}"
                               placeholder="Explain any variance or handover"
                               @if ($isLocked) disabled @endif>
                    </div>

                    <div class="mt-3 flex flex-wrap gap-2">
                        @if (! $isLocked)
                            <button type="submit" class="btn btn-primary">Submit counts</button>
                        @endif
                        @if ($canReconcile && $isOpen)
                            <button type="submit" form="lock-close" class="btn btn-secondary">Lock close</button>
                        @endif
                    </div>
                </form>

                @if ($canReconcile && $isOpen)
                    <form id="lock-close" method="POST" class="hidden"
                          action="{{ route('close.lock', ['shop' => $shopId, 'close' => $dailyClose['id']]) }}">
                        @csrf
                    </form>
                @endif

                @if ($dailyClose !== null)
                    <p class="text-xs text-mute">Expected totals are based only on completed transactions. Pending reviews and queued repairs are excluded.</p>
                @endif
            </div>
        </x-shop-card>

        {{-- Physical stock count --}}
        <x-shop-card title="Physical stock count" subtitle="Evidence first — corrections require approval">
            <div class="space-y-4">
                <p class="text-xs text-mute">Count what is physically present. Submitting evidence never changes inventory; any variance must be reviewed before corrections apply.</p>

                <form method="POST" action="{{ route('counts.submit', ['shop' => $shopId]) }}" class="space-y-4">
                    @csrf
                    <input type="hidden" name="date" value="{{ $date }}">
                    <div class="space-y-2">
                        @foreach ($stock as $index => $model)
                            <div class="grid grid-cols-[minmax(0,1fr)_5rem] items-center gap-3 rounded-lg border border-line bg-paper px-3 py-2">
                                <div class="min-w-0">
                                    <div class="truncate text-sm font-medium text-ink">{{ $model['model_name'] }}</div>
                                    <div class="text-xs text-mute">Expected {{ $model['available'] }}</div>
                                </div>
                                <input type="hidden" name="items[{{ $index }}][modelId]" value="{{ $model['id'] }}">
                                <input type="number" min="0" name="items[{{ $index }}][countedQty]"
                                       value="{{ old('items.'.$index.'.countedQty', $model['available']) }}"
                                       aria-label="Counted {{ $model['model_name'] }}"
                                       class="input h-11 text-center">
                            </div>
                        @endforeach
                    </div>

                    <div>
                        <label class="label" for="count-notes">Notes</label>
                        <input id="count-notes" type="text" name="notes" class="input"
                               value="{{ old('notes') }}" placeholder="Explain missing or extra stock">
                    </div>

                    <button type="submit" class="btn btn-primary" @if (count($stock) === 0) disabled @endif>Submit physical count</button>
                </form>

                @if ($latestStockCount !== null)
                    <div class="rounded-xl border border-line bg-paper p-3">
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-sm font-semibold text-ink">Latest count</span>
                            <span class="{{ $latestStatusClass }}">{{ $latestStockCount['status'] }}</span>
                        </div>
                        <div class="mt-2 space-y-1 text-xs text-mute">
                            @if (count($latestVarianceItems) === 0)
                                <span>No variance recorded.</span>
                            @else
                                @foreach ($latestVarianceItems as $item)
                                    <div class="flex justify-between gap-2">
                                        <span>{{ $item['model_name'] }}</span>
                                        <span class="font-semibold text-lowstock">Expected {{ $item['expected_qty'] }} · Counted {{ $item['counted_qty'] }}</span>
                                    </div>
                                @endforeach
                            @endif
                        </div>

                        @if ($canReconcile && $latestStockCount['status'] === 'submitted')
                            <div class="mt-3 flex gap-2">
                                <form method="POST" action="{{ route('counts.approve', ['count' => $latestStockCount['id']]) }}">
                                    @csrf
                                    <button type="submit" class="btn btn-secondary">Approve count</button>
                                </form>
                            </div>
                        @endif

                        @if ($canReconcile && $latestStockCount['status'] === 'approved' && count($latestVarianceItems) > 0)
                            <form method="POST" action="{{ route('counts.apply', ['count' => $latestStockCount['id']]) }}" class="mt-3 space-y-2">
                                @csrf
                                <input type="text" name="reason" class="input"
                                       value="{{ old('reason') }}"
                                       placeholder="Reason for applying variance correction">
                                <button type="submit" class="btn btn-primary">Apply variance correction</button>
                            </form>
                        @endif
                    </div>
                @endif
            </div>
        </x-shop-card>

        {{-- Pending stock changes --}}
        <x-shop-card :title="$requestsTitle" :subtitle="$requestsSubtitle">
            <div class="space-y-2">
                @if ($canApproveRequests && count($pendingRequests) > 0)
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-xs text-mute">{{ count($pendingRequests) }} pending change{{ count($pendingRequests) === 1 ? '' : 's' }}</span>
                        <form method="POST" action="{{ route('requests.approve-all') }}">
                            @csrf
                            <input type="hidden" name="shopId" value="{{ $shopId }}">
                            <button type="submit" class="btn btn-primary btn-sm">Approve all</button>
                        </form>
                    </div>
                @endif

                @if (count($pendingRequests) === 0)
                    <div class="empty-state">No pending stock changes.</div>
                @else
                    @foreach ($pendingRequests as $stockRequest)
                        @php
                            $isCreate = $stockRequest['type'] === 'create_model';
                            $requestDelta = (int) ($stockRequest['delta'] ?? 0);
                            $requestTitle = $isCreate
                                ? 'New model: '.($stockRequest['model_name_display'] ?? '—')
                                : ($requestDelta > 0 ? 'Restock ' : 'Correction ')
                                    .($requestDelta > 0 ? '+' : '').$requestDelta
                                    .' · '.($stockRequest['model_name_display'] ?? '—');
                        @endphp
                        <div class="rounded-lg border border-line bg-paper p-3">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2 text-sm">
                                        <span class="font-medium text-ink">{{ $requestTitle }}</span>
                                        @if ($isCreate && $stockRequest['condition'] !== null)
                                            <span class="{{ $stockRequest['condition'] === 'new' ? 'badge-brand' : 'badge-muted' }}">{{ $stockRequest['condition'] }}</span>
                                        @endif
                                        <span class="badge-brand">pending</span>
                                    </div>
                                    <div class="mt-1 text-xs text-mute">
                                        {{-- spaces before every @directive: Blade's regex needs a
                                             non-word char before "@", so "@endif@if" would never compile --}}
                                        {{ $stockRequest['staff_name'] ?? 'Staff' }}@if ($stockRequest['opening_stock'] !== null) · opening {{ $stockRequest['opening_stock'] }} @endif @if ($stockRequest['cost_price'] !== null) · cost {{ $stockRequest['cost_price'] }} GHS @endif @if ($stockRequest['sale_price'] !== null) · sale {{ $stockRequest['sale_price'] }} GHS @endif @if ($stockRequest['reason']) · "{{ $stockRequest['reason'] }}" @endif · {{ \App\Support\Format::dateTime($stockRequest['created_at']) }}
                                    </div>
                                    @if ($stockRequest['error_note'] !== null)
                                        <div class="mt-1 text-xs text-lowstock">Failed to apply: {{ $stockRequest['error_note'] }}</div>
                                    @endif
                                </div>

                                @if ($canApproveRequests)
                                    <div class="flex shrink-0 gap-2">
                                        <form method="POST" action="{{ route('requests.approve', ['stockRequest' => $stockRequest['id']]) }}">
                                            @csrf
                                            <button type="submit" class="btn btn-primary btn-sm">Approve</button>
                                        </form>
                                        <form method="POST" action="{{ route('requests.reject', ['stockRequest' => $stockRequest['id']]) }}">
                                            @csrf
                                            <button type="submit" class="btn btn-danger btn-sm">Reject</button>
                                        </form>
                                    </div>
                                @else
                                    <span class="badge-brand shrink-0">awaiting approval</span>
                                @endif
                            </div>
                        </div>
                    @endforeach
                @endif
            </div>
        </x-shop-card>

        {{-- Stock --}}
        <x-shop-card title="Stock" subtitle="Live balance per model">
            <x-slot name="actions">
                <div x-data="{
                    all: {{ \Illuminate\Support\Js::from($stockRows) }},
                    targets: {{ \Illuminate\Support\Js::from($targetsJson) }},
                    reason: {{ \Illuminate\Support\Js::from((string) old('reason', '')) }},
                    error: '',
                    open: false,
                    verb: {{ \Illuminate\Support\Js::from($canEditStock ? 'Apply' : 'Request') }},
                    model(id) { return this.all.find((m) => m.id === id) },
                    deltaFor(id) {
                        const m = this.model(id);
                        const t = Math.floor(Number(this.targets[id]));
                        if (!Number.isFinite(t) || t < 0) return 0;
                        return t - m.avail;
                    },
                    hintFor(id) {
                        const t = Math.floor(Number(this.targets[id]));
                        const d = this.deltaFor(id);
                        return '→ ' + t + ' (' + (d > 0 ? '+' : '') + d + ')';
                    },
                    get changes() {
                        return this.all.filter((m) => {
                            const t = Math.floor(Number(this.targets[m.id]));
                            return Number.isFinite(t) && t >= 0 && t - m.avail !== 0;
                        }).length;
                    },
                    get label() {
                        return this.verb + ' ' + this.changes + ' change' + (this.changes === 1 ? '' : 's');
                    },
                    guard(e) {
                        if (this.changes > 0) return;
                        e.preventDefault();
                        this.error = 'No changes — adjust some quantities first.';
                    }
                }">
                    <button type="button" @click="open = true" class="btn btn-secondary btn-sm">Bulk stock</button>

                    <x-shop-modal size="xl" title="Bulk stock edit"
                                  :subtitle="$canEditStock ? 'Set target quantities — applied immediately' : 'Set target quantities — sent to the owner for approval'"
                                  show="open" onClose="open = false">
                        <div class="mb-2 flex items-center justify-between text-xs text-mute">
                            <span>{{ count($stock) }} model{{ count($stock) === 1 ? '' : 's' }}</span>
                            <span :class="changes ? 'font-semibold text-ink/80' : ''"
                                  x-text="changes + ' change' + (changes === 1 ? '' : 's')">0 changes</span>
                        </div>

                        @if (count($stock) === 0)
                            <p class="py-4 text-sm text-mute">No models in this shop yet. Add one first.</p>
                        @else
                            <form method="POST" action="{{ route('models.bulk', ['shop' => $shopId]) }}"
                                  @submit="guard($event)">
                                @csrf
                                <div class="mb-2 grid grid-cols-[minmax(0,1fr)_120px] items-center gap-3 px-3 text-xs font-medium uppercase tracking-wide text-mute">
                                    <span>Model</span>
                                    <span class="text-right">Target</span>
                                </div>
                                <div class="space-y-1.5">
                                    @foreach ($stock as $index => $model)
                                        @php
                                            $modelLow = $model['available'] <= $model['low_stock_threshold'];
                                        @endphp
                                        <div class="grid grid-cols-[minmax(0,1fr)_120px] items-center gap-3 rounded-lg border px-3 py-2"
                                             :class="deltaFor('{{ $model['id'] }}') !== 0 ? 'border-instock bg-instock-tint/60' : 'border-line bg-paper'">
                                            <div class="min-w-0">
                                                <div class="truncate text-sm font-medium text-ink">{{ $model['model_name'] }}</div>
                                                <div class="mt-0.5 flex flex-wrap items-center gap-x-2 text-xs text-mute">
                                                    <span class="{{ $model['condition'] === 'new' ? 'badge-brand' : 'badge-muted' }}">{{ $model['condition'] }}</span>
                                                    <span class="{{ $modelLow ? 'text-lowstock' : '' }}">now {{ $model['available'] }}</span>
                                                    <span x-cloak x-show="deltaFor('{{ $model['id'] }}') !== 0"
                                                          :class="deltaFor('{{ $model['id'] }}') > 0 ? 'text-instock' : 'text-lowstock'"
                                                          x-text="hintFor('{{ $model['id'] }}')"></span>
                                                </div>
                                            </div>
                                            <div class="flex items-center justify-end gap-1.5">
                                                <span class="text-xs text-mute">→</span>
                                                <input type="hidden" name="items[{{ $index }}][modelId]" value="{{ $model['id'] }}">
                                                <input type="number" min="0" class="input h-9 w-full text-right"
                                                       name="items[{{ $index }}][targetQty]"
                                                       x-model="targets['{{ $model['id'] }}']"
                                                       value="{{ $model['available'] }}">
                                            </div>
                                        </div>
                                    @endforeach
                                </div>

                                <div class="mt-4">
                                    <label class="label">Reason (optional)</label>
                                    <input type="text" name="reason" class="input" x-model="reason"
                                           value="{{ old('reason', '') }}" placeholder="e.g. new batch received">
                                </div>

                                <div class="mt-3 flex items-center gap-2">
                                    <button type="submit" class="btn btn-primary flex-1" x-text="label">{{ $canEditStock ? 'Apply' : 'Request' }} 0 changes</button>
                                    <button type="button" class="btn btn-secondary" @click="open = false">Cancel</button>
                                </div>
                            </form>
                        @endif

                        <div class="mt-3">
                            <div x-cloak x-show="error !== ''" x-text="error"
                                 class="rounded-lg border border-lowstock bg-lowstock-tint px-3 py-2 text-sm text-lowstock"></div>
                        </div>
                    </x-shop-modal>
                </div>

                @php
                    $addSubtitle = $canEditStock
                        ? 'Add a new phone model to this shop'
                        : 'Sent to the owner for approval';
                @endphp
                <div x-data="{ open: false }">
                    <button type="button" @click="open = true" class="btn btn-secondary btn-sm">+ Add model</button>

                    <x-shop-modal title="Add model" :subtitle="$addSubtitle" show="open" onClose="open = false">
                        @if (! $canEditStock)
                            <p class="mb-3 rounded-lg border border-brand bg-brand-tint px-3 py-2 text-xs text-brand">
                                New models are sent to the owner for approval before they appear in stock.
                            </p>
                        @endif
                        <form method="POST" action="{{ route('models.store', ['shop' => $shopId]) }}" class="grid gap-3 sm:grid-cols-2">
                            @csrf
                            <div class="sm:col-span-2">
                                <label class="label">Model name</label>
                                <input type="text" name="modelName" class="input"
                                       value="{{ old('modelName') }}" placeholder='e.g. "iPhone 13 128GB"'>
                            </div>
                            <div>
                                <label class="label">Condition</label>
                                <select name="condition" class="input">
                                    <option value="new" @if (old('condition', 'new') === 'new') selected @endif>New</option>
                                    <option value="used" @if (old('condition') === 'used') selected @endif>Used</option>
                                </select>
                            </div>
                            <div>
                                <label class="label">Low-stock threshold</label>
                                <input type="number" name="lowStockThreshold" class="input"
                                       value="{{ old('lowStockThreshold', '5') }}">
                            </div>
                            <div>
                                <label class="label">Cost price (GHS)</label>
                                <input type="number" name="costPrice" class="input"
                                       value="{{ old('costPrice') }}" placeholder="optional">
                            </div>
                            <div>
                                <label class="label">Sale price (GHS)</label>
                                <input type="number" name="salePrice" class="input"
                                       value="{{ old('salePrice') }}" placeholder="optional">
                            </div>
                            <div>
                                <label class="label">Opening stock</label>
                                <input type="number" name="openingStock" class="input"
                                       value="{{ old('openingStock', '0') }}">
                            </div>

                            <div class="mt-3 flex gap-2 sm:col-span-2">
                                <button type="submit" class="btn btn-primary flex-1">
                                    {{ $canEditStock ? 'Save model' : 'Request approval' }}
                                </button>
                                <button type="button" class="btn btn-secondary" @click="open = false">Cancel</button>
                            </div>
                        </form>
                    </x-shop-modal>
                </div>
            </x-slot>

            <div class="space-y-3"
                 x-data="{
                     all: {{ \Illuminate\Support\Js::from($stockRows) }},
                     q: '',
                     cond: 'all',
                     low: false,
                     visible: 50,
                     money(n) { return 'GHS ' + Number(n ?? 0).toLocaleString('en-GH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) },
                     get kept() {
                         const term = this.q.trim().toLowerCase();
                         return this.all.filter((m) => {
                             if (this.cond !== 'all' && m.cond !== this.cond) return false;
                             if (this.low && !(m.avail <= m.thr)) return false;
                             if (term && !m.name.toLowerCase().includes(term)) return false;
                             return true;
                         });
                     },
                     get units() { return this.kept.reduce((sum, m) => sum + m.avail, 0) },
                     get cost() { return this.kept.reduce((sum, m) => sum + (m.cost != null ? m.cost * m.avail : 0), 0) },
                     get retail() { return this.kept.reduce((sum, m) => sum + (m.sale != null ? m.sale * m.avail : 0), 0) },
                     shown(id) {
                         const i = this.kept.findIndex((m) => m.id === id);
                         return i !== -1 && i < this.visible;
                     },
                     setCond(c) { this.cond = c; this.visible = 50 },
                     toggleLow() { this.low = !this.low; this.visible = 50 },
                     resetPage() { this.visible = 50 },
                     more() { this.visible = this.visible + 50 }
                 }">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <div class="relative sm:max-w-xs sm:flex-1">
                        <svg aria-hidden="true" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-mute"
                             viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                            <circle cx="11" cy="11" r="7" />
                            <path d="M21 21l-4.3-4.3" />
                        </svg>
                        <input type="text" class="input pl-9" placeholder="Search models…" x-model="q" @input="resetPage()">
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <div class="inline-flex rounded-lg border border-line p-0.5">
                            @foreach (['all', 'new', 'used'] as $condOption)
                                <button type="button" @click="setCond('{{ $condOption }}')"
                                        :class="cond === '{{ $condOption }}' ? 'bg-ink text-white' : 'text-mute hover:text-ink'"
                                        class="h-11 rounded-md px-3 text-xs font-medium capitalize transition-colors">
                                    {{ $condOption }}
                                </button>
                            @endforeach
                        </div>
                        <button type="button" @click="toggleLow()" :aria-pressed="low"
                                :class="low ? 'border-brand bg-brand-tint text-brand' : 'border-line text-mute hover:text-ink'"
                                class="h-11 rounded-lg border px-3 text-xs font-medium transition-colors">
                            Low stock
                        </button>
                    </div>
                </div>

                <div class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-mute">
                    <span><span x-text="kept.length">{{ count($stock) }}</span> of {{ count($stock) }} models</span>
                    <span><span x-text="units">{{ $stockUnits }}</span> units</span>
                    @if ($canEditStock && $stockCost > 0)
                        <span x-show="cost > 0">
                            Stock value (cost): <b class="text-ink/80" x-text="money(cost)">{{ \App\Support\Format::money($stockCost) }}</b>
                        </span>
                    @endif
                    @if ($canEditStock && $stockRetail > 0)
                        <span x-show="retail > 0">
                            Retail value: <b class="text-ink/80" x-text="money(retail)">{{ \App\Support\Format::money($stockRetail) }}</b>
                        </span>
                    @endif
                </div>

                @if (count($stock) === 0)
                    <div class="empty-state">No models yet. Add the first one above.</div>
                @else
                    <div class="empty-state" x-cloak x-show="kept.length === 0">No models match your search.</div>

                    <div x-show="kept.length > 0" class="space-y-3">
                        <div class="hidden overflow-x-auto sm:block">
                            <table class="table-base">
                                <thead>
                                    <tr>
                                        <th>Model</th>
                                        <th>Condition</th>
                                        <th class="text-right">Cost</th>
                                        <th class="text-right">Sale</th>
                                        <th class="text-right">Opening</th>
                                        <th class="text-right">Bought</th>
                                        <th class="text-right">Available</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($stock as $model)
                                        @php
                                            $low = $model['available'] <= $model['low_stock_threshold'];
                                            $rowAdjustments = $adjustmentsByModel[$model['id']] ?? [];
                                        @endphp
                                        <tr x-show="shown({{ \Illuminate\Support\Js::from($model['id']) }})">
                                            <td class="font-medium text-ink">
                                                {{ $model['model_name'] }}
                                                @if ($low)
                                                    <span class="ml-2"><span class="badge-brand">low</span></span>
                                                @endif
                                            </td>
                                            <td>
                                                <span class="{{ $model['condition'] === 'new' ? 'badge-brand' : 'badge-muted' }}">{{ $model['condition'] }}</span>
                                            </td>
                                            <td class="text-right text-mute">{{ $model['cost_price'] !== null ? \App\Support\Format::money($model['cost_price']) : '—' }}</td>
                                            <td class="text-right text-mute">{{ $model['sale_price'] !== null ? \App\Support\Format::money($model['sale_price']) : '—' }}</td>
                                            <td class="text-right">{{ $model['opening_stock'] }}</td>
                                            <td class="text-right">{{ $model['bought_in'] }}</td>
                                            <td class="text-right font-bold {{ $low ? 'text-lowstock' : 'text-ink' }}">{{ $model['available'] }}</td>
                                            <td>
                                                <x-shop-product-modal :model="$model" :shop="$shopId"
                                                                       :can-edit="$canEditStock"
                                                                       :adjustments="$rowAdjustments" />
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <ul class="space-y-2 sm:hidden">
                            @foreach ($stock as $model)
                                @php
                                    $low = $model['available'] <= $model['low_stock_threshold'];
                                    $rowAdjustments = $adjustmentsByModel[$model['id']] ?? [];
                                @endphp
                                <li x-show="shown({{ \Illuminate\Support\Js::from($model['id']) }})"
                                    class="rounded-lg border border-line bg-paper p-3">
                                    <div class="flex items-start justify-between gap-2">
                                        <div class="min-w-0">
                                            <div class="flex flex-wrap items-center gap-2">
                                                <span class="truncate text-sm font-medium text-ink">{{ $model['model_name'] }}</span>
                                                @if ($low)
                                                    <span class="badge-brand">low</span>
                                                @endif
                                            </div>
                                            <div class="mt-1 flex flex-wrap items-center gap-2 text-xs text-mute">
                                                <span class="{{ $model['condition'] === 'new' ? 'badge-brand' : 'badge-muted' }}">{{ $model['condition'] }}</span>
                                                <span>Cost {{ $model['cost_price'] !== null ? \App\Support\Format::money($model['cost_price']) : '—' }}</span>
                                                <span>Sale {{ $model['sale_price'] !== null ? \App\Support\Format::money($model['sale_price']) : '—' }}</span>
                                            </div>
                                        </div>
                                        <x-shop-product-modal :model="$model" :shop="$shopId"
                                                               :can-edit="$canEditStock"
                                                               :adjustments="$rowAdjustments" />
                                    </div>
                                    <div class="mt-2 grid grid-cols-3 gap-2 text-center">
                                        <div class="rounded-lg bg-white px-2 py-1.5">
                                            <div class="text-xs font-medium uppercase tracking-wide text-mute">Opening</div>
                                            <div class="text-sm font-semibold text-ink">{{ $model['opening_stock'] }}</div>
                                        </div>
                                        <div class="rounded-lg bg-white px-2 py-1.5">
                                            <div class="text-xs font-medium uppercase tracking-wide text-mute">Bought</div>
                                            <div class="text-sm font-semibold text-ink">{{ $model['bought_in'] }}</div>
                                        </div>
                                        <div class="rounded-lg bg-white px-2 py-1.5 {{ $low ? 'ring-1 ring-lowstock' : '' }}">
                                            <div class="text-xs font-medium uppercase tracking-wide text-mute">Available</div>
                                            <div class="text-sm font-bold {{ $low ? 'text-lowstock' : 'text-ink' }}">{{ $model['available'] }}</div>
                                        </div>
                                    </div>
                                </li>
                            @endforeach
                        </ul>

                        <button type="button" x-cloak x-show="kept.length > visible" @click="more()"
                                class="inline-flex h-11 w-full items-center justify-center rounded-lg border border-line bg-white text-sm font-medium text-ink transition-colors hover:bg-paper">
                            Load more (<span x-text="kept.length - visible">0</span> remaining)
                        </button>
                    </div>
                @endif
            </div>
        </x-shop-card>

        {{-- Swapped phones --}}
        <x-shop-card title="Swapped phones" :subtitle="$swappedSubtitle">
            @if (count($swappedPhones) === 0)
                <div class="empty-state">No trade-in phones yet. They appear here after a swap.</div>
            @else
                <ul class="space-y-2">
                    @foreach ($swappedPhones as $phone)
                        @php
                            $statusLabels = [
                                'in_stock' => ['label' => 'In stock', 'class' => 'badge-brand'],
                                'sold' => ['label' => 'Sold', 'class' => 'badge-ok'],
                                'returned' => ['label' => 'Returned', 'class' => 'badge-muted'],
                            ];
                            $statusMeta = $statusLabels[$phone['status']] ?? $statusLabels['in_stock'];
                        @endphp
                        <li class="flex items-start justify-between gap-3 rounded-lg border border-line bg-paper p-3">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="truncate text-sm font-medium text-ink">{{ $phone['model_name'] }}</span>
                                    <span class="{{ $phone['condition'] === 'new' ? 'badge-brand' : 'badge-muted' }}">{{ $phone['condition'] }}</span>
                                    <span class="{{ $statusMeta['class'] }}">{{ $statusMeta['label'] }}</span>
                                </div>
                                <div class="mt-0.5 text-xs text-mute">
                                    @if ($phone['customer_name'] !== null){{ $phone['customer_name'] }} · @endif{{ \App\Support\Format::dateTime($phone['created_at']) }}
                                </div>
                            </div>

                            @if ($isOwner)
                                <form method="POST" action="{{ route('swapped.update', ['phone' => $phone['id']]) }}" class="shrink-0">
                                    @csrf
                                    <select name="status" aria-label="Update status" x-on:change="$event.target.form.submit()"
                                            class="h-8 rounded-lg border border-line bg-white px-2 text-xs text-ink/80 focus:border-mute focus:outline-none">
                                        <option value="in_stock" @if ($phone['status'] === 'in_stock') selected @endif>In stock</option>
                                        <option value="sold" @if ($phone['status'] === 'sold') selected @endif>Sold</option>
                                        <option value="returned" @if ($phone['status'] === 'returned') selected @endif>Returned</option>
                                    </select>
                                    <noscript>
                                        <button type="submit" class="btn btn-secondary btn-sm mt-1">Update</button>
                                    </noscript>
                                </form>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-shop-card>

        {{-- Transactions --}}
        <x-shop-card :title="$txTitle" :subtitle="$txSubtitle">
            @if (count($transactions) === 0)
                <div class="empty-state">No transactions today.</div>
            @else
                <ul class="divide-y divide-paper">
                    @foreach ($transactions as $tx)
                        <li class="flex items-center justify-between gap-3 py-2">
                            <div class="min-w-0">
                                <div class="flex items-center gap-2">
                                    <span class="font-medium text-ink">{{ $txLabels[$tx['id']] !== '' ? $txLabels[$tx['id']] : '—' }}</span>
                                    <span class="{{ $tx['type'] === 'sale' ? 'badge-ok' : ($tx['type'] === 'swap' ? 'badge-brand' : 'badge-muted') }}">{{ $tx['type'] }}</span>
                                </div>
                                <div class="text-xs text-mute">
                                    {{ $tx['customer_name'] ?: 'Walk-in' }}@if ($tx['customer_phone']) · {{ $tx['customer_phone'] }}@endif · {{ \App\Support\Format::dateTime($tx['date']) }} · {{ $tx['staff_name'] ?? '—' }}
                                </div>
                            </div>
                            <div class="flex items-center gap-3 text-sm">
                                <a href="{{ route('transactions.show', ['transaction' => $tx['id']]) }}"
                                   class="text-xs font-medium text-mute underline hover:text-ink">Receipt</a>
                                <span class="font-semibold text-ink">{{ \App\Support\Format::money($tx['amount']) }}</span>
                                @if ($isOwner && $tx['status'] !== 'voided' && $tx['status'] !== 'rejected')
                                    <form method="POST" action="{{ route('transactions.void', ['transaction' => $tx['id']]) }}"
                                          class="flex items-center gap-2"
                                          @submit="if (!window.confirm('Void this transaction? Stock effects will be reversed, but the original receipt and audit history will be preserved.')) $event.preventDefault()">
                                        @csrf
                                        <input type="text" name="reason" placeholder="Void reason" aria-label="Reason for voiding"
                                               class="h-9 w-32 rounded-lg border border-line bg-white px-3 text-xs text-ink placeholder:text-mute focus:border-mute focus:outline-none">
                                        <button type="submit" class="text-xs font-medium text-lowstock hover:text-lowstock">Void</button>
                                    </form>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-shop-card>
    </div>
@endsection
