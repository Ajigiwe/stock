@extends('layouts.app')

@section('title', 'Devices')
@section('mobileTitle', 'Devices')

@php
    use App\Support\Format;

    $shopsList = $shops ?? [];
    $rowsList = $rows ?? [];
    $salesFrom = $salesFrom ?? '';

    $totalUnits = (int) array_sum(array_column($rowsList, 'total'));
    $totalSold = (int) array_sum(array_column($rowsList, 'sold'));
    $lowModels = count(array_filter($rowsList, fn (array $row): bool => (int) $row['low'] > 0));

    $shopNameById = array_column($shopsList, 'name', 'id');

    $windowDays = \App\Services\Queries\QuerySupport::DEVICES_SALES_WINDOW_DAYS;
    $historyLabel = 'sales from the last '.$windowDays.' days'
        .($salesFrom !== '' ? ' (since '.Format::date($salesFrom).')' : '');

    // Stock level per cell — port of level()/levelClass/chipClass.
    $level = function (int $available, int $threshold): string {
        if ($available <= 0) {
            return 'empty';
        }
        if ($available <= $threshold) {
            return 'low';
        }
        if ($available <= $threshold * 2) {
            return 'medium';
        }

        return 'ok';
    };
    $levelClass = [
        'empty' => 'text-line',
        'low' => 'text-lowstock font-bold',
        'medium' => 'text-brand font-semibold',
        'ok' => 'text-instock font-semibold',
    ];
    $chipClass = [
        'empty' => 'border-line bg-paper text-mute',
        'low' => 'border-lowstock bg-lowstock-tint text-lowstock',
        'medium' => 'border-brand bg-brand-tint text-brand',
        'ok' => 'border-instock bg-instock-tint text-instock',
    ];

    // Compact per-row payload behind the Alpine filters and the detail modal.
    $alpineRows = [];
    foreach ($rowsList as $row) {
        $alpineRows[$row['key']] = [
            'model_name' => $row['model_name'],
            'condition' => $row['condition'],
            'sim_type' => $row['sim_type'],
            'sim_label' => $row['sim_label'],
            'color' => $row['color'],
            'category' => $row['category'],
            'cat_label' => $row['cat_label'],
            'total' => (int) $row['total'],
            'sold' => (int) $row['sold'],
            'low' => (int) $row['low'],
            'perShop' => array_map(fn (array $cell): array => [
                'shopId' => $cell['shopId'],
                'available' => (int) $cell['available'],
                'low' => (bool) $cell['low'],
            ], $row['perShop']),
            'sales' => array_map(fn (array $sale): array => [
                'shopId' => $sale['shopId'],
                'qty' => (int) $sale['qty'],
            ], $row['sales']),
        ];
    }
@endphp

@section('content')
    <div class="space-y-6">
        <div>
            <h1 class="text-xl font-bold text-ink">Devices</h1>
            <p class="text-sm text-mute">
                Available pieces per model across every shop, plus who sold them — {{ $historyLabel }}
            </p>
        </div>

        {{-- Headline numbers --}}
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <div class="rounded-xl border border-line bg-white p-4 shadow-sm">
                <div class="text-[11px] font-semibold uppercase tracking-wide text-mute">Models</div>
                <div class="mt-1 text-2xl font-bold tnum text-ink">{{ Format::number(count($rowsList)) }}</div>
            </div>
            <div class="rounded-xl border border-line bg-white p-4 shadow-sm">
                <div class="text-[11px] font-semibold uppercase tracking-wide text-mute">Units available</div>
                <div class="mt-1 text-2xl font-bold tnum text-ink">{{ Format::number($totalUnits) }}</div>
            </div>
            <div class="rounded-xl border border-line bg-white p-4 shadow-sm">
                <div class="text-[11px] font-semibold uppercase tracking-wide text-mute">Units sold</div>
                <div class="mt-1 text-2xl font-bold tnum text-instock">{{ Format::number($totalSold) }}</div>
            </div>
            <div class="rounded-xl border border-line bg-white p-4 shadow-sm">
                <div class="text-[11px] font-semibold uppercase tracking-wide text-mute">Low in a shop</div>
                <div class="mt-1 text-2xl font-bold tnum {{ $lowModels > 0 ? 'text-lowstock' : 'text-ink' }}">
                    {{ Format::number($lowModels) }}
                </div>
            </div>
        </div>

        {{-- Filters, matrix and per-model detail modals --}}
        <div class="space-y-3" x-data="devicesTable()">
            {{-- Filters --}}
            <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <div class="relative sm:max-w-xs sm:flex-1">
                    <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                         stroke-linecap="round"
                         class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-mute">
                        <circle cx="11" cy="11" r="7" />
                        <path d="M21 21l-4.3-4.3" />
                    </svg>
                    <input type="search" name="q" x-model="q" placeholder="Search models…" aria-label="Search models"
                           class="input pl-9">
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <select x-model="shopId" aria-label="Filter by shop"
                            class="h-9 w-auto min-w-[9rem] rounded-lg border border-line bg-white px-3 text-xs text-ink focus:border-brand focus:outline-none">
                        <option value="">All shops</option>
                        @foreach ($shopsList as $shopCol)
                            <option value="{{ $shopCol['id'] }}">{{ $shopCol['name'] }}</option>
                        @endforeach
                    </select>

                    <div class="inline-flex rounded-lg border border-line p-0.5">
                        @foreach (['all', 'new', 'used'] as $condOption)
                            <button type="button" @click="cond = '{{ $condOption }}'"
                                    :class="cond === '{{ $condOption }}' ? 'bg-ink text-white' : 'text-mute hover:text-ink'"
                                    class="h-8 rounded-md px-3 text-xs font-medium capitalize transition-colors">
                                {{ $condOption }}
                            </button>
                        @endforeach
                    </div>

                    <button type="button" @click="lowOnly = !lowOnly"
                            :class="lowOnly ? 'border-brand bg-brand-tint text-brand' : 'border-line text-mute hover:text-ink'"
                            class="h-8 rounded-lg border px-3 text-xs font-medium transition-colors">
                        Low stock
                    </button>
                </div>
            </div>

            <p class="text-xs text-mute">
                <span x-text="matchCount()">{{ count($rowsList) }}</span> of {{ count($rowsList) }} models · tap a row to
                see stock by shop and who sold each one
            </p>

            {{-- Legend --}}
            <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-mute">
                <span class="inline-flex items-center gap-1.5">
                    <span class="h-2 w-2 rounded-full bg-instock"></span> Healthy
                </span>
                <span class="inline-flex items-center gap-1.5">
                    <span class="h-2 w-2 rounded-full bg-brand"></span> Running low
                </span>
                <span class="inline-flex items-center gap-1.5">
                    <span class="h-2 w-2 rounded-full bg-lowstock"></span> Low stock
                </span>
                <span class="inline-flex items-center gap-1.5">
                    <span class="font-bold text-instock">3</span> units sold
                </span>
            </div>

            <div x-show="matchCount() === 0" x-cloak class="empty-state text-sm text-mute">
                No models match your search.
            </div>

            <div x-show="matchCount() > 0">
                {{-- Desktop matrix --}}
                <div class="hidden overflow-x-auto rounded-xl border border-line bg-white shadow-sm sm:block">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-line text-left text-xs uppercase tracking-wide text-mute">
                                <th class="sticky left-0 bg-white py-2 pl-4 pr-2 font-medium">Model</th>
                                <th class="py-2 pr-2 font-medium">Condition</th>
                                @foreach ($shopsList as $shopCol)
                                    <th class="py-2 pr-3 text-right font-medium"
                                        x-show="!shopId || shopId === {{ \Illuminate\Support\Js::from($shopCol['id']) }}">
                                        {{ $shopCol['name'] }}
                                    </th>
                                @endforeach
                                <th class="py-2 pr-3 text-right font-medium">Total</th>
                                <th class="py-2 pr-3 text-right font-medium">Sold</th>
                                <th class="py-2 pr-2 text-center font-medium">Low</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rowsList as $row)
                                @php
                                    $cellsByShop = [];
                                    foreach ($row['perShop'] as $cell) {
                                        $cellsByShop[$cell['shopId']] = $cell;
                                    }
                                    $keyExpr = \Illuminate\Support\Js::from($row['key']);
                                @endphp
                                <tr class="cursor-pointer border-b border-paper hover:bg-paper/60"
                                    x-show="matches({{ $keyExpr }})"
                                    @click="selected = {{ $keyExpr }}">
                                    <td class="sticky left-0 bg-white py-2 pl-4 pr-2 font-medium text-ink hover:text-brand">
                                        {{ $row['model_name'] }}
                                        @if ($row['sim_label'] !== '')
                                            <span class="ml-1 badge badge-muted">{{ $row['sim_label'] }}</span>
                                        @endif
                                        @if ($row['color'] !== '')
                                            <span class="ml-1 text-xs font-normal text-mute">{{ $row['color'] }}</span>
                                        @endif
                                        @if ($row['category'] !== 'phone')
                                            <span class="ml-1 badge badge-brand">{{ $row['cat_label'] }}</span>
                                        @endif
                                    </td>
                                    <td class="py-2 pr-2">
                                        <span class="{{ $row['condition'] === 'new' ? 'badge badge-brand' : 'badge badge-muted' }}">
                                            {{ $row['condition'] }}
                                        </span>
                                    </td>
                                    @foreach ($shopsList as $shopCol)
                                        @php $cell = $cellsByShop[$shopCol['id']] ?? null; @endphp
                                        <td class="py-2 pr-3 text-right"
                                            x-show="!shopId || shopId === {{ \Illuminate\Support\Js::from($shopCol['id']) }}">
                                            @if ($cell !== null && (int) $cell['available'] > 0)
                                                <a href="{{ route('shop.show', ['shop' => $shopCol['id']]) }}"
                                                   x-on:click.stop
                                                   class="tnum hover:underline {{ $levelClass[$level((int) $cell['available'], (int) $cell['threshold'])] }}">
                                                    {{ $cell['available'] }}
                                                </a>
                                            @else
                                                <span class="text-line">—</span>
                                            @endif
                                        </td>
                                    @endforeach
                                    <td class="py-2 pr-3 text-right font-bold tnum text-ink"
                                        x-text="scopedTotal({{ $keyExpr }})">{{ $row['total'] }}</td>
                                    <td class="py-2 pr-3 text-right font-bold tnum"
                                        :class="soldClass({{ $keyExpr }})"
                                        x-text="scopedSold({{ $keyExpr }})">{{ $row['sold'] }}</td>
                                    <td class="py-2 pr-4 text-center">
                                        <span class="tnum"
                                              :class="lowBadgeClass({{ $keyExpr }})"
                                              x-text="lowBadgeText({{ $keyExpr }})">{{ $row['low'] > 0 ? $row['low'] : '—' }}</span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                {{-- Mobile compact rows --}}
                <ul class="space-y-1.5 sm:hidden">
                    @foreach ($rowsList as $row)
                        @php $keyExpr = \Illuminate\Support\Js::from($row['key']); @endphp
                        <li class="cursor-pointer rounded-xl border border-line bg-white px-3 py-2.5 transition-colors active:bg-paper"
                            x-show="matches({{ $keyExpr }})"
                            @click="selected = {{ $keyExpr }}">
                            <div class="flex items-center gap-2.5">
                                <span class="h-2 w-2 shrink-0 rounded-full"
                                      :class="statusDot({{ $keyExpr }})"></span>
                                <span class="truncate text-[13.5px] font-bold text-ink">{{ $row['model_name'] }}</span>
                                <span class="{{ $row['condition'] === 'new' ? 'badge badge-brand' : 'badge badge-muted' }}">
                                    {{ $row['condition'] }}
                                </span>
                                @if ($row['sim_label'] !== '')
                                    <span class="badge badge-muted">{{ $row['sim_label'] }}</span>
                                @endif
                                @if ($row['color'] !== '')
                                    <span class="shrink-0 text-xs text-mute">{{ $row['color'] }}</span>
                                @endif
                                @if ($row['category'] !== 'phone')
                                    <span class="badge badge-brand">{{ $row['cat_label'] }}</span>
                                @endif
                                <span class="flex-1"></span>
                                <span class="text-right text-[13px] tnum">
                                    <span class="font-mono font-bold text-ink"
                                          x-text="scopedTotal({{ $keyExpr }})">{{ $row['total'] }}</span><span class="text-mute"> avail</span><span class="mx-1 text-line">·</span><span class="font-mono font-bold"
                                          :class="soldClass({{ $keyExpr }})"
                                          x-text="scopedSold({{ $keyExpr }})">{{ $row['sold'] }}</span><span class="text-mute"> sold</span>
                                </span>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </div>

            {{-- Detail modal — one shell per model, opened by `selected` --}}
            @foreach ($rowsList as $row)
                @php
                    $keyExpr = \Illuminate\Support\Js::from($row['key']);
                    $cellsByShop = [];
                    foreach ($row['perShop'] as $cell) {
                        $cellsByShop[$cell['shopId']] = $cell;
                    }
                    $hasStock = (bool) array_filter($row['perShop'], fn (array $cell): bool => (int) $cell['available'] > 0);
                @endphp
                <x-shop-modal size="xl"
                              :title="$row['model_name']"
                              show="selected === {{ $keyExpr }}"
                              onClose="selected = null">
                    <x-slot name="sub">Details · <span x-text="scopeLabel">All shops</span></x-slot>

                    <div class="space-y-4">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="{{ $row['condition'] === 'new' ? 'badge badge-brand' : 'badge badge-muted' }}">
                                {{ $row['condition'] }}
                            </span>
                            @if ($row['sim_label'] !== '')
                                <span class="badge badge-brand">{{ $row['sim_label'] }}</span>
                            @endif
                            @if ($row['color'] !== '')
                                <span class="text-sm text-mute">{{ $row['color'] }}</span>
                            @endif
                            @if ($row['category'] !== 'phone')
                                <span class="badge badge-brand">{{ $row['cat_label'] }}</span>
                            @endif
                            <span class="text-sm text-mute">
                                <span class="font-semibold tnum text-ink" x-text="scopedTotal({{ $keyExpr }})">{{ $row['total'] }}</span>
                                available ·
                                <span class="font-semibold tnum text-ink" x-text="scopedSold({{ $keyExpr }})">{{ $row['sold'] }}</span>
                                sold across <span x-text="detailScopeLabel">all shops</span>
                            </span>
                        </div>

                        <div>
                            <h3 class="mb-1.5 text-xs font-semibold uppercase tracking-wide text-mute">Stock by shop</h3>
                            <div class="flex flex-wrap gap-1.5">
                                @if (! $hasStock)
                                    <p class="text-sm text-mute">No stock in this shop.</p>
                                @else
                                    @foreach ($row['perShop'] as $cell)
                                        @if ((int) $cell['available'] > 0)
                                            <a href="{{ route('shop.show', ['shop' => $cell['shopId']]) }}"
                                               x-show="!shopId || shopId === {{ \Illuminate\Support\Js::from($cell['shopId']) }}"
                                               class="inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs {{ $chipClass[$level((int) $cell['available'], (int) $cell['threshold'])] }}">
                                                <span class="font-medium">{{ $shopNameById[$cell['shopId']] ?? '—' }}</span>
                                                <span class="font-bold tnum">{{ $cell['available'] }}</span>
                                            </a>
                                        @endif
                                    @endforeach
                                @endif
                            </div>
                        </div>

                        <div>
                            <h3 class="mb-1.5 text-xs font-semibold uppercase tracking-wide text-mute">Sold by / to</h3>
                            @if (count($row['sales']) === 0)
                                <div class="empty-state text-sm text-mute">No sales recorded for this model yet.</div>
                            @else
                                <div class="overflow-x-auto">
                                    <table class="w-full text-sm">
                                        <thead>
                                            <tr class="border-b border-line text-left text-xs uppercase tracking-wide text-mute">
                                                <th class="py-1.5 pr-4 font-medium">Sold by</th>
                                                <th class="py-1.5 pr-4 font-medium">To</th>
                                                <th class="py-1.5 pr-4 font-medium">Shop</th>
                                                <th class="py-1.5 pr-4 font-medium">Date</th>
                                                <th class="py-1.5 pr-4 text-right font-medium">Qty</th>
                                                <th class="py-1.5 text-right font-medium">Amount</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach ($row['sales'] as $sale)
                                                <tr class="border-b border-paper"
                                                    x-show="!shopId || shopId === {{ \Illuminate\Support\Js::from($sale['shopId']) }}">
                                                    <td class="py-1.5 pr-4 font-medium text-ink">{{ $sale['staffName'] ?? '—' }}</td>
                                                    <td class="py-1.5 pr-4 text-ink/70">
                                                        {{ $sale['customerName'] ?? 'Walk-in' }}@if ($sale['customerPhone'])<span class="ml-1 text-xs text-mute">({{ $sale['customerPhone'] }})</span>@endif
                                                    </td>
                                                    <td class="py-1.5 pr-4 text-mute">{{ $sale['shopName'] ?? '—' }}</td>
                                                    <td class="py-1.5 pr-4 text-mute">{{ Format::dateTime($sale['date']) }}</td>
                                                    <td class="py-1.5 pr-4 text-right tnum">{{ $sale['qty'] }}</td>
                                                    <td class="py-1.5 text-right font-semibold tnum text-ink">{{ Format::money($sale['amount']) }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @endif
                        </div>
                    </div>
                </x-shop-modal>
            @endforeach
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        function devicesTable() {
            const rows = {!! json_encode($alpineRows, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!};
            const shopNames = {!! json_encode($shopNameById, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!};

            return {
                q: '',
                cond: 'all',
                lowOnly: false,
                shopId: '',
                selected: null,

                // DevicesTable's scopeLabel — the modal subtitle.
                get scopeLabel() {
                    return this.shopId ? (shopNames[this.shopId] || 'All shops') : 'All shops';
                },
                // ModelDetail's scopeLabel — the body copy under the badges.
                get detailScopeLabel() {
                    return this.shopId ? (shopNames[this.shopId] || 'all shops') : 'all shops';
                },

                scopedTotal(key) {
                    const row = rows[key];
                    if (!row) return 0;
                    if (!this.shopId) return row.total;
                    const cell = row.perShop.find((c) => c.shopId === this.shopId);
                    return cell ? cell.available : 0;
                },

                scopedSold(key) {
                    const row = rows[key];
                    if (!row) return 0;
                    if (!this.shopId) return row.sold;
                    return row.sales
                        .filter((s) => s.shopId === this.shopId)
                        .reduce((total, s) => total + s.qty, 0);
                },

                scopedLow(key) {
                    const row = rows[key];
                    if (!row) return 0;
                    if (!this.shopId) return row.low;
                    const cell = row.perShop.find((c) => c.shopId === this.shopId);
                    return cell && cell.low ? 1 : 0;
                },

                matches(key) {
                    const row = rows[key];
                    if (!row) return false;
                    if (this.cond !== 'all' && row.condition !== this.cond) return false;
                    if (this.lowOnly && this.scopedLow(key) === 0) return false;
                    const term = this.q.trim().toLowerCase();
                    if (term && (row.model_name + ' ' + row.sim_label + ' ' + row.color).toLowerCase().indexOf(term) === -1) return false;
                    return true;
                },

                matchCount() {
                    return Object.keys(rows).filter((key) => this.matches(key)).length;
                },

                soldClass(key) {
                    return this.scopedSold(key) > 0 ? 'text-instock' : 'text-mute';
                },

                lowBadgeClass(key) {
                    return this.scopedLow(key) > 0
                        ? 'inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-lowstock-tint px-1.5 text-xs font-semibold text-lowstock'
                        : 'text-line';
                },

                lowBadgeText(key) {
                    const low = this.scopedLow(key);
                    return low > 0 ? String(low) : '—';
                },

                statusDot(key) {
                    if (this.scopedTotal(key) <= 0) return 'bg-line';
                    return this.scopedLow(key) > 0 ? 'bg-lowstock' : 'bg-instock';
                }
            };
        }
    </script>
@endpush
