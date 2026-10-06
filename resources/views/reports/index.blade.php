@extends('layouts.app')

@section('title', 'Reports')
@section('mobileTitle', 'Reports')

@section('content')

@php
    use App\Support\Format;

    $paymentLabels = [
        'cash' => 'Cash',
        'mobile_money' => 'Mobile money',
        'card' => 'Card',
        'bank_transfer' => 'Bank transfer',
        'other' => 'Other',
    ];
    $typeLabels = ['sale' => 'Sale', 'swap' => 'Swap', 'repair' => 'Repair'];

    // CSV export carries the same query string as the current filters.
    $exportQuery = array_filter($filters, fn ($value): bool => $value !== null && $value !== '');
    $exportHref = route('reports.export').($exportQuery === [] ? '' : '?'.http_build_query($exportQuery));

    $itemsLabel = fn (array $tx): string => collect($tx['items'])
        ->map(fn (array $item): string => ($item['direction'] === 'out' ? '−' : '+').$item['model_name'])
        ->implode(', ');

    $badgeClass = fn (array $tx): string => match (true) {
        $tx['status'] === 'pending_review' => 'badge badge-brand',
        $tx['status'] === 'voided', $tx['status'] === 'rejected' => 'badge badge-danger',
        $tx['type'] === 'sale' => 'badge badge-ok',
        $tx['type'] === 'swap' => 'badge badge-brand',
        default => 'badge badge-muted',
    };
    $rowBadgeLabel = fn (array $tx): string => $tx['status'] === 'pending_review' ? 'review' : $tx['status'];
    $typeBadgeClass = fn (array $tx): string => match ($tx['type']) {
        'sale' => 'badge badge-ok',
        'swap' => 'badge badge-brand',
        default => 'badge badge-muted',
    };
@endphp

<div class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-xl font-bold text-ink">Reports</h1>
            <p class="text-sm text-mute">{{ count($transactions) }} transactions in range</p>
        </div>
        <a href="{{ $exportHref }}"
           class="inline-flex h-10 items-center rounded-lg border border-line bg-white px-4 text-sm font-medium text-ink hover:bg-paper">
            Export CSV
        </a>
    </div>

    <x-dash-card title="Filters">
        <form method="GET" action="{{ route('reports.index') }}" class="grid gap-3 sm:grid-cols-5">
            @if ($is_owner)
                <div>
                    <label class="label">Shop</label>
                    <select name="shop" class="input">
                        <option value="">All shops</option>
                        @foreach ($shops as $s)
                            <option value="{{ $s['id'] }}" @selected($filters['shop'] === $s['id'])>{{ $s['name'] }}</option>
                        @endforeach
                    </select>
                </div>
            @endif
            <div>
                <label class="label">From</label>
                <input type="date" name="from" value="{{ $filters['from'] }}" class="input">
            </div>
            <div>
                <label class="label">To</label>
                <input type="date" name="to" value="{{ $filters['to'] }}" class="input">
            </div>
            <div>
                <label class="label">Type</label>
                <select name="type" class="input">
                    <option value="">All</option>
                    @foreach ($typeLabels as $value => $label)
                        <option value="{{ $value }}" @selected($filters['type'] === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="label">Payment</label>
                <select name="payment" class="input">
                    <option value="">All</option>
                    @foreach ($paymentLabels as $value => $label)
                        <option value="{{ $value }}" @selected($filters['payment'] === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="label">Status</label>
                <select name="status" class="input">
                    <option value="" @selected($filters['status'] === null)>Completed only</option>
                    <option value="all" @selected($filters['status'] === 'all')>All statuses</option>
                    <option value="pending_review" @selected($filters['status'] === 'pending_review')>Pending review</option>
                    <option value="voided" @selected($filters['status'] === 'voided')>Voided</option>
                    <option value="rejected" @selected($filters['status'] === 'rejected')>Rejected</option>
                </select>
            </div>
            <div class="sm:col-span-5">
                <button type="submit"
                        class="inline-flex h-10 w-full items-center justify-center rounded-lg bg-brand px-4 text-sm font-medium text-white hover:bg-brand-deep sm:w-auto">
                    Apply
                </button>
            </div>
        </form>
    </x-dash-card>

    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <x-dash-card>
            <div class="text-xs font-medium uppercase tracking-wide text-mute">Revenue</div>
            <div class="mt-1 text-xl font-bold text-ink">{{ Format::money($revenue) }}</div>
        </x-dash-card>
        <x-dash-card>
            <div class="text-xs font-medium uppercase tracking-wide text-mute">Sales</div>
            <div class="mt-1 text-xl font-bold tnum text-ink">{{ $sales }}</div>
        </x-dash-card>
        <x-dash-card>
            <div class="text-xs font-medium uppercase tracking-wide text-mute">Swaps</div>
            <div class="mt-1 text-xl font-bold tnum text-ink">{{ $swaps }}</div>
        </x-dash-card>
        <x-dash-card>
            <div class="text-xs font-medium uppercase tracking-wide text-mute">Repairs</div>
            <div class="mt-1 text-xl font-bold tnum text-ink">{{ $repairs }}</div>
        </x-dash-card>
    </div>

    @if (count($payment_breakdown) > 0)
        <x-dash-card title="Revenue by payment method">
            <div class="flex flex-wrap gap-4 text-sm">
                @foreach ($payment_breakdown as $method => $amount)
                    <div class="flex items-center gap-2">
                        <span class="badge badge-brand">{{ $paymentLabels[$method] ?? $method }}</span>
                        <span class="font-semibold text-ink">{{ Format::money($amount) }}</span>
                    </div>
                @endforeach
            </div>
        </x-dash-card>
    @endif

    <x-dash-card title="Transactions">
        @if (count($transactions) === 0)
            <div class="empty-state text-sm text-mute">No transactions match these filters.</div>
        @else
            <div class="hidden overflow-x-auto sm:block">
                <table class="table-base">
                    <thead>
                        <tr>
                            <th>Date</th>
                            @if ($is_owner)
                                <th>Shop</th>
                            @endif
                            <th>Staff</th>
                            <th>Type</th>
                            <th>Items</th>
                            <th>Payment</th>
                            <th class="text-right">Amount</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($transactions as $tx)
                            @php $itemsLine = $itemsLabel($tx); @endphp
                            <tr>
                                <td class="text-mute">{{ Format::dateTime($tx['date']) }}</td>
                                @if ($is_owner)
                                    <td>{{ $tx['shop_name'] ?? '—' }}</td>
                                @endif
                                <td class="text-mute">{{ $tx['staff_name'] ?? '—' }}</td>
                                <td><span class="{{ $badgeClass($tx) }}">{{ $rowBadgeLabel($tx) }}</span></td>
                                <td class="text-ink">{{ $itemsLine === '' ? '—' : $itemsLine }}</td>
                                <td class="text-mute">{{ $paymentLabels[$tx['payment_method']] ?? $tx['payment_method'] }}</td>
                                <td class="text-right font-semibold text-ink tnum">{{ Format::money($tx['amount']) }}</td>
                                <td class="text-right">
                                    <a href="{{ route('transactions.show', ['transaction' => $tx['id']]) }}"
                                       class="text-xs font-medium text-mute underline hover:text-ink">Receipt</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <ul class="space-y-2 sm:hidden">
                @foreach ($transactions as $tx)
                    @php $itemsLine = $itemsLabel($tx); @endphp
                    <li class="rounded-lg border border-line bg-paper p-3">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="{{ $typeBadgeClass($tx) }}">{{ $tx['type'] }}</span>
                                    @if ($is_owner && $tx['shop_name'])
                                        <span class="text-xs text-mute">{{ $tx['shop_name'] }}</span>
                                    @endif
                                </div>
                                <div class="mt-1 text-sm font-medium text-ink">{{ $itemsLine === '' ? '—' : $itemsLine }}</div>
                                <div class="mt-0.5 text-xs text-mute">
                                    {{ Format::dateTime($tx['date']) }} · {{ $tx['staff_name'] ?? '—' }} ·
                                    {{ $paymentLabels[$tx['payment_method']] ?? $tx['payment_method'] }}
                                </div>
                            </div>
                            <div class="shrink-0 text-right">
                                <div class="text-sm font-semibold text-ink tnum">{{ Format::money($tx['amount']) }}</div>
                                <a href="{{ route('transactions.show', ['transaction' => $tx['id']]) }}"
                                   class="text-xs font-medium text-mute underline hover:text-ink">Receipt</a>
                            </div>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-dash-card>
</div>
@endsection
