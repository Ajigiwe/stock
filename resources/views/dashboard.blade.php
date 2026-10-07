@extends('layouts.app')

@section('title', 'Dashboard')
@section('mobileTitle', 'Dashboard')

@section('content')

@php
    use App\Support\Format;

    $periodLabels = ['today' => 'Today', '7d' => 'Last 7 days', '30d' => 'Last 30 days'];
    $period = in_array($period, ['today', '7d', '30d'], true) ? $period : 'today';
    $periodLabel = $periodLabels[$period];

    $isOwner = $role === 'owner';
    $summaryCount = count($summaries);
    $scopeLabel = $isOwner
        ? ($shop ? $shop['name'] : $summaryCount.' shop'.($summaryCount === 1 ? '' : 's'))
        : 'your shop';
    $headerTitle = $isOwner ? $periodLabel : ($shop['name'] ?? $periodLabel);
    $dayLabel = date('D, j M', strtotime(Format::today()));

    // Low-stock models across the scoped shops (alert strip).
    $lowItems = [];
    foreach ($summaries as $summary) {
        foreach ($summary['low_stock'] as $model) {
            $lowItems[] = ['shop' => $summary['shop'], 'model' => $model];
        }
    }

    // Top models moving — units out per model across the scoped shops.
    $moverMap = [];
    foreach ($summaries as $summary) {
        foreach ($summary['rows'] as $row) {
            $name = $row['model_name'];
            $moverMap[$name] = ($moverMap[$name] ?? 0) + $row['sold'] + $row['swapped_out'];
        }
    }
    $movers = [];
    foreach ($moverMap as $name => $units) {
        if ($units > 0) {
            $movers[] = ['name' => $name, 'units' => $units];
        }
    }
    usort($movers, fn (array $a, array $b): int => $b['units'] <=> $a['units']);
    $movers = array_slice($movers, 0, 5);

    // Top models for the chart card — keyed by model + condition, top six.
    $modelMap = [];
    foreach ($summaries as $summary) {
        foreach ($summary['rows'] as $row) {
            $key = $row['model_name'].'|'.$row['condition'];
            if (! isset($modelMap[$key])) {
                $modelMap[$key] = ['name' => $row['model_name'], 'condition' => $row['condition'], 'units' => 0];
            }
            $modelMap[$key]['units'] += $row['sold'] + $row['swapped_out'];
        }
    }
    $topModels = array_values(array_filter($modelMap, fn (array $m): bool => $m['units'] > 0));
    usort($topModels, fn (array $a, array $b): int => $b['units'] <=> $a['units']);
    $topModels = array_slice($topModels, 0, 6);
    $maxUnits = max([1, ...array_column($topModels, 'units')]);

    $hasTx = ($totals['sales'] + $totals['swaps'] + $totals['repairs']) > 0;
    $typeItems = [
        ['label' => 'Sales', 'value' => $totals['sales'], 'cls' => 'bg-instock'],
        ['label' => 'Swaps', 'value' => $totals['swaps'], 'cls' => 'bg-brand'],
        ['label' => 'Repairs', 'value' => $totals['repairs'], 'cls' => 'bg-mute'],
    ];
    $maxType = max([1, ...array_column($typeItems, 'value')]);

    // Daily revenue bars.
    $showTrend = count($series) > 1;
    $maxRev = max([1.0, ...array_values(array_column($series, 'revenue'))]);
    $labelStep = max(1, (int) ceil(count($series) / 7));
    $dm = fn (string $iso): string => ((int) substr($iso, 8, 2)).'/'.((int) substr($iso, 5, 2));

    $pendingCount = count($pending);
    $pendingSubtitle = ($isOwner
        ? $pendingCount.' change'.($pendingCount === 1 ? '' : 's').' waiting for you'
        : $pendingCount.' change'.($pendingCount === 1 ? '' : 's').' awaiting the owner');

    $statItems = [
        ['label' => 'Revenue', 'value' => Format::money($totals['revenue']), 'tone' => 'text-ledger'],
        ['label' => 'Sales', 'value' => Format::number($totals['sales']), 'tone' => 'text-ink'],
        ['label' => 'Swaps', 'value' => Format::number($totals['swaps']), 'tone' => 'text-ink'],
        ['label' => 'Repairs', 'value' => Format::number($totals['repairs']), 'tone' => 'text-ink'],
        ['label' => 'Units out', 'value' => Format::number($totals['units_out']), 'tone' => 'text-ink'],
        ['label' => 'Low stock', 'value' => Format::number($totals['low_stock']), 'tone' => $totals['low_stock'] > 0 ? 'text-lowstock' : 'text-ink'],
    ];
@endphp

@if (! $isOwner && $shop === null && $summaryCount === 0)
    {{-- No role or shop assigned yet — port of the home page's welcome branch. --}}
    <div class="mx-auto max-w-md py-12">
        <div class="rounded-xl border border-line bg-white p-6 shadow-sm">
            <h1 class="text-lg font-semibold text-ink">Welcome</h1>
            <p class="mt-2 text-sm text-mute">
                Your account has no role or shop assigned yet. Ask the owner to
                assign you to a shop from Settings.
            </p>
        </div>
    </div>
@elseif ($isOwner && $summaryCount === 0)
    <div class="mx-auto max-w-md py-12 text-center">
        <h1 class="text-lg font-semibold text-ink">No shops yet</h1>
        <p class="mt-2 text-sm text-mute">Add your first shop to start tracking stock.</p>
        <a href="{{ route('settings.index') }}"
           class="mt-4 inline-block rounded-lg bg-brand px-4 py-2 text-sm font-medium text-white hover:bg-brand-deep">
            Add a shop
        </a>
    </div>
@else
    <div class="space-y-6">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-xl font-extrabold tracking-tight text-ink">{{ $headerTitle }}</h1>
                <p class="mt-0.5 text-[12.5px] text-mute">{{ $dayLabel }} · {{ $scopeLabel }} · live</p>
            </div>
            @if (! request()->user()?->isSuperAdmin())
                <a href="{{ route('transactions.create') }}"
                   class="inline-flex h-10 shrink-0 items-center gap-1.5 rounded-[10px] bg-brand px-4 text-sm font-bold text-white transition-colors hover:bg-brand-deep">
                    Record
                </a>
            @endif
        </div>

        <div class="flex flex-wrap items-center gap-3">
            {{-- Period switcher — keeps the current shop filter. --}}
            <div class="inline-flex rounded-lg border border-line bg-white p-0.5">
                @foreach (['today', '7d', '30d'] as $p)
                    @php
                        $periodParams = [];
                        if ($p !== 'today') {
                            $periodParams['period'] = $p;
                        }
                        if (! empty($shop['id'])) {
                            $periodParams['shop'] = $shop['id'];
                        }
                    @endphp
                    <a href="{{ route('dashboard', $periodParams) }}"
                       @class([
                           'h-8 rounded-md px-3 text-xs font-medium leading-8 transition-colors',
                           'bg-brand text-white' => $period === $p,
                           'text-mute hover:text-ink' => $period !== $p,
                       ])>{{ $periodLabels[$p] }}</a>
                @endforeach
            </div>

            @if ($isOwner && count($shops) > 1)
                <form method="GET" action="{{ route('dashboard') }}">
                    @if ($period !== 'today')
                        <input type="hidden" name="period" value="{{ $period }}">
                    @endif
                    <select name="shop" aria-label="Filter by shop"
                            x-on:change="$el.form.submit()"
                            class="h-9 w-auto min-w-[9rem] rounded-lg border border-line bg-white px-3 text-xs text-ink focus:border-brand focus:outline-none">
                        <option value="">All shops</option>
                        @foreach ($shops as $s)
                            <option value="{{ $s['id'] }}" @selected(($shop['id'] ?? null) === $s['id'])>{{ $s['name'] }}</option>
                        @endforeach
                    </select>
                </form>
            @endif
        </div>

        {{-- KPI cards --}}
        <div class="grid grid-cols-2 gap-2.5 sm:grid-cols-3 lg:grid-cols-6">
            @foreach ($statItems as $stat)
                <div class="flex min-h-[84px] flex-col gap-1 rounded-2xl border border-line bg-white px-4 py-3.5">
                    <span class="text-xs font-semibold uppercase tracking-wider text-mute">{{ $stat['label'] }}</span>
                    <span class="font-mono text-[22px] font-bold leading-tight tnum {{ $stat['tone'] }}">{{ $stat['value'] }}</span>
                </div>
            @endforeach
        </div>

        @if (count($lowItems) > 0)
            @php
                $firstLow = $lowItems[0];
                $lowHref = $isOwner ? route('devices.index') : route('shop.show', ['shop' => $firstLow['shop']['id']]);
                $lowDetail = $firstLow['model']['model_name'].' · '.$firstLow['model']['available'].' left'
                    .($isOwner ? ' · '.$firstLow['shop']['name'] : '')
                    .(count($lowItems) > 1 ? ' · +'.(count($lowItems) - 1).' more' : '');
            @endphp
            <a href="{{ $lowHref }}"
               class="flex items-center justify-between gap-3 rounded-2xl border border-[#ebc9bb] bg-lowstock-tint p-4 transition-transform hover:-translate-y-px">
                <div class="flex min-w-0 items-center gap-3">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-lowstock text-[15px] font-extrabold text-white">
                        {{ count($lowItems) }}
                    </span>
                    <div class="min-w-0">
                        <div class="text-[13.5px] font-bold text-ink">
                            {{ count($lowItems) === 1 ? 'Model running low' : 'Models running low' }}
                        </div>
                        <div class="mt-0.5 truncate text-xs text-mute">{{ $lowDetail }}</div>
                    </div>
                </div>
                <x-icon name="chevron" :size="18" class="shrink-0 -rotate-90 text-lowstock" />
            </a>
        @endif

        @if (count($movers) > 0)
            <section>
                <h2 class="mb-2.5 mt-1 text-[13px] font-bold text-ink">Top models moving</h2>
                <div class="flex flex-col gap-2">
                    @foreach ($movers as $mover)
                        <div class="flex items-center justify-between rounded-xl border border-line bg-white px-3.5 py-2.5">
                            <span class="truncate text-[13px] font-semibold text-ink">{{ $mover['name'] }}</span>
                            <span class="shrink-0 font-mono text-xs font-semibold tnum text-mute">{{ $mover['units'] }} out</span>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        {{-- Charts --}}
        <div class="space-y-6">
            @if ($showTrend)
                <x-dash-card title="Revenue trend" subtitle="Daily revenue">
                    <div class="flex h-40 items-end gap-1">
                        @foreach ($series as $point)
                            @php $revPct = $point['revenue'] > 0 ? max(2, ($point['revenue'] / $maxRev) * 100) : 0; @endphp
                            <div class="flex h-full flex-1 flex-col justify-end"
                                 title="{{ $dm($point['date']) }} — {{ Format::money($point['revenue']) }}">
                                <div class="relative w-full rounded-t bg-instock/80" style="height: {{ $revPct }}%"></div>
                            </div>
                        @endforeach
                    </div>
                    <div class="mt-1 flex gap-1">
                        @foreach ($series as $index => $point)
                            <div class="flex-1 truncate text-center text-[10px] text-mute">
                                {{ $index % $labelStep === 0 ? $dm($point['date']) : '' }}
                            </div>
                        @endforeach
                    </div>
                </x-dash-card>
            @endif

            <div class="grid gap-6 lg:grid-cols-2">
                <x-dash-card title="Sales by type" subtitle="Transactions in period">
                    @if (! $hasTx)
                        <div class="empty-state text-sm text-mute">No transactions in this period.</div>
                    @else
                        <ul class="space-y-3">
                            @foreach ($typeItems as $type)
                                <li>
                                    <div class="mb-1 flex justify-between text-xs">
                                        <span class="font-medium text-ink/80">{{ $type['label'] }}</span>
                                        <span class="text-mute">{{ $type['value'] }}</span>
                                    </div>
                                    <div class="h-2.5 w-full overflow-hidden rounded-full bg-paper">
                                        <div class="h-full rounded-full {{ $type['cls'] }}"
                                             style="width: {{ ($type['value'] / $maxType) * 100 }}%"></div>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </x-dash-card>

                <x-dash-card title="Top models" subtitle="Units out in period">
                    @if (count($topModels) === 0)
                        <div class="empty-state text-sm text-mute">No units out in this period.</div>
                    @else
                        <ul class="space-y-3">
                            @foreach ($topModels as $model)
                                <li>
                                    <div class="mb-1 flex justify-between gap-2 text-xs">
                                        <span class="min-w-0 truncate font-medium text-ink/80">{{ $model['name'] }}</span>
                                        <span class="shrink-0 text-mute">{{ $model['units'] }}</span>
                                    </div>
                                    <div class="h-2.5 w-full overflow-hidden rounded-full bg-paper">
                                        <div class="h-full rounded-full bg-ink/80"
                                             style="width: {{ ($model['units'] / $maxUnits) * 100 }}%"></div>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </x-dash-card>
            </div>
        </div>

        {{-- Owner review queue --}}
        @if ($isOwner)
            <x-dash-card title="Transactions needing review"
                        subtitle="Discounted sales do not count as finalized revenue until approved">
                @if (count($reviewTransactions) === 0)
                    <div class="empty-state text-sm text-mute">No discounted transactions need review.</div>
                @else
                    <div class="space-y-2">
                        @foreach ($reviewTransactions as $tx)
                            @php
                                $outItems = [];
                                foreach ($tx['items'] as $item) {
                                    if ($item['direction'] === 'out') {
                                        $outItems[] = $item['qty'].'× '.$item['model_name'];
                                    }
                                }
                                $itemLine = implode(', ', $outItems);
                                $itemLine = $itemLine === '' ? 'No item details' : $itemLine;
                                $recorded = 'Recorded '.Format::money($tx['amount'])
                                    .($tx['discount_reason'] ? ' · '.$tx['discount_reason'] : '');
                            @endphp
                            <div class="rounded-xl border border-brand bg-brand-tint/40 p-3"
                                 x-data="{ rejectOpen: false, rejectError: '' }">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="badge badge-brand">discount review</span>
                                            <span class="text-sm font-bold text-ink">{{ $tx['customer_name'] ?: 'Walk-in' }}</span>
                                            <span class="text-xs text-mute">{{ $tx['shop_name'] ?? 'Shop' }} · {{ $tx['staff_name'] ?? 'Staff' }}</span>
                                        </div>
                                        <div class="mt-1 text-xs text-mute">
                                            {{ $itemLine }} · {{ Format::dateTime($tx['date']) }}
                                        </div>
                                        <div class="mt-1 text-sm font-semibold text-ink">{{ $recorded }}</div>
                                    </div>
                                    <div class="flex shrink-0 gap-2">
                                        <form method="POST" action="{{ route('transactions.review', ['transaction' => $tx['id']]) }}">
                                            @csrf
                                            <input type="hidden" name="decision" value="approve">
                                            <button type="submit" class="btn btn-primary h-11 px-3 text-xs">Approve</button>
                                        </form>
                                        <button type="button" @click="rejectOpen = !rejectOpen"
                                                class="btn btn-danger h-11 px-3 text-xs">Reject</button>
                                    </div>
                                </div>
                                <div x-show="rejectOpen" x-cloak class="mt-3">
                                    <form method="POST" action="{{ route('transactions.review', ['transaction' => $tx['id']]) }}"
                                          x-on:submit="if (! $el.reason.value.trim()) { rejectError = 'A reason is required.'; $event.preventDefault(); } else { rejectError = ''; }"
                                          class="flex flex-col gap-2 sm:flex-row">
                                        @csrf
                                        <input type="hidden" name="decision" value="reject">
                                        <input name="reason" placeholder="Reason for rejecting" aria-label="Reason for rejecting"
                                               x-effect="if (rejectOpen) $nextTick(() => $el.focus())"
                                               class="input h-11 flex-1">
                                        <button type="submit" class="btn btn-secondary shrink-0 px-3 text-xs">Confirm reject</button>
                                    </form>
                                    <p x-show="rejectError" x-cloak class="field-error">{{ rejectError }}</p>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-dash-card>
        @endif

        {{-- Pending stock approvals --}}
        @if ($pendingCount > 0)
            <x-dash-card title="Pending stock approvals" :subtitle="$pendingSubtitle">
                <div class="space-y-2" x-data="{ showReason: false }">
                    @if ($isOwner)
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-xs text-mute">
                                {{ $pendingCount }} pending change{{ $pendingCount === 1 ? '' : 's' }}
                            </span>
                            <form method="POST" action="{{ route('requests.approve-all') }}">
                                @csrf
                                <button type="submit" class="btn btn-primary btn-sm">Approve all</button>
                            </form>
                        </div>
                    @endif

                    @foreach ($pending as $request)
                        @php
                            $isCreate = $request['type'] === 'create_model';
                            $delta = $request['delta'] ?? 0;
                            $title = $isCreate
                                ? 'New model: '.($request['model_name_display'] ?? '—')
                                : ($delta > 0 ? 'Restock' : 'Correction').' '.($delta > 0 ? '+' : '').$delta.' · '.($request['model_name_display'] ?? '—');

                            $meta = $request['staff_name'] ?? 'Staff';
                            if ($isOwner && $request['shop_name']) {
                                $meta .= ' · '.$request['shop_name'];
                            }
                            if ($request['opening_stock'] !== null) {
                                $meta .= ' · opening '.$request['opening_stock'];
                            }
                            if ($request['cost_price'] !== null) {
                                $meta .= ' · cost '.$request['cost_price'].' GHS';
                            }
                            if ($request['sale_price'] !== null) {
                                $meta .= ' · sale '.$request['sale_price'].' GHS';
                            }
                            if ($request['reason']) {
                                $meta .= ' · "'.$request['reason'].'"';
                            }
                            $meta .= ' · '.Format::dateTime($request['created_at']);
                        @endphp
                        <div class="rounded-lg border border-line bg-paper p-3">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2 text-sm">
                                        <span class="font-medium text-ink">{{ $title }}</span>
                                        @if ($isCreate && $request['condition'])
                                            <span class="{{ $request['condition'] === 'new' ? 'badge badge-brand' : 'badge badge-muted' }}">{{ $request['condition'] }}</span>
                                        @endif
                                        <span class="badge badge-brand">pending</span>
                                    </div>
                                    <div class="mt-1 text-xs text-mute">{{ $meta }}</div>
                                    @if ($request['error_note'])
                                        <div class="mt-1 text-xs text-lowstock">Failed to apply: {{ $request['error_note'] }}</div>
                                    @endif
                                </div>
                                @if ($isOwner)
                                    <div class="flex shrink-0 gap-2">
                                        <form method="POST" action="{{ route('requests.approve', ['stockRequest' => $request['id']]) }}">
                                            @csrf
                                            <button type="submit" class="btn btn-primary btn-sm">Approve</button>
                                        </form>
                                        <form method="POST" action="{{ route('requests.reject', ['stockRequest' => $request['id']]) }}">
                                            @csrf
                                            <button type="submit" class="btn btn-danger btn-sm">Reject</button>
                                        </form>
                                    </div>
                                @else
                                    <span class="badge badge-brand">awaiting owner</span>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </x-dash-card>
        @endif

        {{-- Recent transactions --}}
        <x-dash-card title="Recent transactions" subtitle="Latest activity">
            <x-slot:actions>
                <a href="{{ route('reports.index') }}" class="text-sm font-medium text-brand hover:underline">View reports →</a>
            </x-slot:actions>

            @if (count($recent) === 0)
                <div class="empty-state text-sm text-mute">No transactions yet. Record your first sale or swap.</div>
            @else
                <ul class="divide-y divide-line">
                    @foreach ($recent as $tx)
                        @php
                            $outItems = [];
                            foreach ($tx['items'] as $item) {
                                $outItems[] = ($item['direction'] === 'out' ? '−' : '+').$item['model_name'];
                            }
                            $itemLine = implode(', ', $outItems);
                            $itemLine = $itemLine === '' ? '—' : $itemLine;

                            $badgeClass = match (true) {
                                $tx['status'] === 'pending_review' => 'badge badge-brand',
                                $tx['status'] === 'voided', $tx['status'] === 'rejected' => 'badge badge-danger',
                                $tx['type'] === 'sale' => 'badge badge-ok',
                                $tx['type'] === 'swap' => 'badge badge-brand',
                                default => 'badge badge-muted',
                            };
                            $badgeLabel = $tx['status'] === 'completed' ? $tx['type'] : str_replace('_', ' ', $tx['status']);

                            $meta = ($tx['customer_name'] ?: 'Walk-in').' · '.Format::dateTime($tx['date']);
                            if ($isOwner && $tx['shop_name']) {
                                $meta .= ' · '.$tx['shop_name'];
                            }
                        @endphp
                        <li>
                            <a href="{{ route('transactions.show', ['transaction' => $tx['id']]) }}"
                               class="flex items-center justify-between gap-3 rounded-lg px-1 py-2 hover:bg-paper">
                                <div class="min-w-0">
                                    <div class="flex items-center gap-2">
                                        <span class="truncate font-medium text-ink">{{ $itemLine }}</span>
                                        <span class="{{ $badgeClass }}">{{ $badgeLabel }}</span>
                                    </div>
                                    <div class="text-xs text-mute">{{ $meta }}</div>
                                </div>
                                <span class="shrink-0 font-mono text-sm font-semibold tnum text-ink">{{ Format::money($tx['amount']) }}</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-dash-card>

        {{-- Per-shop closing cards --}}
        @foreach ($summaries as $summary)
            @php
                $closingTitle = $isOwner
                    ? $summary['shop']['name'].' · '.mb_strtolower($periodLabel)
                    : $periodLabel.' closing';
                $closingSubtitle = $isOwner ? ($summary['shop']['location'] ?? null) : null;
            @endphp
            <x-dash-card :title="$closingTitle" :subtitle="$closingSubtitle">
                <x-slot:actions>
                    <a href="{{ route('shop.show', ['shop' => $summary['shop']['id']]) }}"
                       class="text-sm font-medium text-brand hover:underline">Shop view →</a>
                </x-slot:actions>

                <div class="mb-3 flex flex-wrap gap-3 text-sm text-mute">
                    <span>Sales: <b class="text-ink">{{ $summary['total_sales'] }}</b></span>
                    <span>Swaps: <b class="text-ink">{{ $summary['total_swaps'] }}</b></span>
                    <span>Revenue: <b class="font-mono tnum text-ledger">{{ Format::money($summary['revenue']) }}</b></span>
                </div>

                @if (count($summary['rows']) === 0)
                    <div class="empty-state text-sm text-mute">No units out today yet.</div>
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
                                @foreach ($summary['rows'] as $row)
                                    <tr>
                                        <td class="font-medium text-ink">{{ $row['model_name'] }}</td>
                                        <td>
                                            <span class="{{ $row['condition'] === 'new' ? 'badge badge-brand' : 'badge badge-muted' }}">{{ $row['condition'] }}</span>
                                        </td>
                                        <td class="text-right tnum">{{ $row['sold'] }}</td>
                                        <td class="text-right tnum">{{ $row['swapped_out'] }}</td>
                                        <td class="text-right font-semibold tnum">{{ $row['sold'] + $row['swapped_out'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <ul class="space-y-2 sm:hidden">
                        @foreach ($summary['rows'] as $row)
                            <li class="flex items-center justify-between gap-3 rounded-lg border border-line bg-paper px-3 py-2">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="truncate text-sm font-medium text-ink">{{ $row['model_name'] }}</span>
                                        <span class="{{ $row['condition'] === 'new' ? 'badge badge-brand' : 'badge badge-muted' }}">{{ $row['condition'] }}</span>
                                    </div>
                                    <div class="mt-0.5 text-xs text-mute">Sold {{ $row['sold'] }} · Swapped out {{ $row['swapped_out'] }}</div>
                                </div>
                                <div class="shrink-0 font-mono text-sm font-semibold tnum text-ink">{{ $row['sold'] + $row['swapped_out'] }}</div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-dash-card>
        @endforeach
    </div>
@endif
@endsection
