@extends('layouts.app')

@section('title', 'Receipt — Mr Jeff Stock')
@section('mobileTitle', 'Receipt')

@section('content')
    @php
        $typeLabels = ['sale' => 'Sale', 'swap' => 'Swap', 'repair' => 'Repair'];
        $paymentLabels = [
            'cash' => 'Cash',
            'mobile_money' => 'Mobile money',
            'card' => 'Card',
            'bank_transfer' => 'Bank transfer',
            'other' => 'Other',
        ];
        $swapStatusLabels = ['in_stock' => 'In stock', 'sold' => 'Sold', 'returned' => 'Returned'];
    @endphp

    @if (empty($tx))
        {{-- Original state for a missing (or not visible) transaction. --}}
        <div class="mx-auto max-w-md py-16 text-center">
            <p class="text-sm text-mute">Transaction not found.</p>
            <a href="{{ route('reports.index') }}" class="mt-3 inline-block text-sm font-medium text-ink underline">Back to reports</a>
        </div>
    @else
        @php
            $typeLabel = $typeLabels[$tx['type']] ?? $tx['type'];
            $paymentLabel = $paymentLabels[$tx['payment_method']] ?? $tx['payment_method'];
            $shopName = $shop['name'] ?? ($tx['shop_name'] ?? 'Mr Jeff Stock');

            $outItems = array_values(array_filter($tx['items'], fn (array $i): bool => $i['direction'] === 'out'));
            $inItems = array_values(array_filter($tx['items'], fn (array $i): bool => $i['direction'] === 'in'));
            $itemVariant = static fn (array $i): string => implode(', ', array_filter([
                $i['condition'] ?? null,
                \App\Services\StockService::simLabel($i['sim_type'] ?? ''),
                ($i['color'] ?? '') !== '' ? $i['color'] : null,
                ($i['category'] ?? 'phone') !== 'phone' ? \App\Services\StockService::catLabel($i['category']) : null,
            ]));

            // Trade-ins: prefer the swapped-phones list; fall back to legacy
            // "in" items — and while the fallback is in play those items are
            // not repeated in the receipt's item list.
            $tradeInLabels = [];
            foreach ($swaps as $swap) {
                $tradeInLabels[] = $swap['model_name'];
            }
            if ($tradeInLabels === []) {
                foreach ($inItems as $item) {
                    $tradeInLabels[] = $item['model_name'].' (x'.$item['qty'].')';
                }
            }
            $listItems = $swaps !== [] ? $tx['items'] : $outItems;

            $shareLines = [
                '*'.$shopName.'* — Receipt '.$receipt_no,
                ($shop['phone'] ?? null) !== null ? 'Tel: '.$shop['phone'] : null,
                'Date: '.\App\Support\Format::dateTime($tx['date']),
                'Type: '.$typeLabel,
                $outItems !== [] ? 'Items: '.implode(', ', array_map(
                    fn (array $i): string => $i['qty'].' x '.$i['model_name'].' ('.$itemVariant($i).')',
                    $outItems
                )) : null,
                $tradeInLabels !== [] ? 'Trade-in: '.implode(', ', $tradeInLabels) : null,
                'Total: '.\App\Support\Format::money($tx['amount']).' ('.$paymentLabel.')',
                $tx['customer_name'] ? 'Customer: '.$tx['customer_name'] : null,
                'Thank you for your business!',
            ];
            $shareText = implode("\n", array_values(array_filter($shareLines, fn ($line) => $line !== null)));

            // wa.me link — the original's waLink(): strip to digits, Ghana 0… → 233…
            $digits = preg_replace('/[^0-9]/', '', (string) ($tx['customer_phone'] ?? ''));
            if (str_starts_with($digits, '00')) {
                $digits = substr($digits, 2);
            } elseif (str_starts_with($digits, '0')) {
                $digits = '233'.substr($digits, 1);
            }
            $waUrl = 'https://wa.me/'.$digits.'?text='.rawurlencode($shareText);

            $typeBadge = ['sale' => 'badge-ok', 'swap' => 'badge-brand'][$tx['type']] ?? 'badge-muted';
        @endphp

        <div class="mx-auto max-w-md space-y-4">
            <div class="no-print flex items-center justify-between">
                <a href="{{ route('shop.show', $tx['shop_id']) }}" class="text-sm text-mute hover:text-ink">&larr; Back to shop</a>
                <a href="{{ route('transactions.create') }}" class="text-sm font-medium text-ink underline">New transaction</a>
            </div>

            <div class="rounded-xl border border-line bg-white p-6 shadow-sm">
                <div class="text-center">
                    <h1 class="text-lg font-bold text-ink">{{ $shopName }}</h1>
                    @if (! empty($shop['location']))
                        <p class="text-xs text-mute">{{ $shop['location'] }}</p>
                    @endif
                    @if (! empty($shop['phone']))
                        <p class="text-xs text-mute">Tel: {{ $shop['phone'] }}</p>
                    @endif
                </div>

                <div class="my-4 border-t border-dashed border-line"></div>

                <div class="flex items-center justify-between text-xs text-mute">
                    <span>Receipt #{{ $receipt_no }}</span>
                    <div class="flex items-center gap-2">
                        <span class="{{ $typeBadge }}">{{ $typeLabel }}</span>
                        @if ($tx['status'] !== 'completed')
                            <span class="{{ $tx['status'] === 'pending_review' ? 'badge-warn' : 'badge-danger' }}">
                                {{ str_replace('_', ' ', $tx['status']) }}
                            </span>
                        @endif
                    </div>
                </div>
                <div class="mt-1 text-xs text-mute">{{ \App\Support\Format::dateTime($tx['date']) }}</div>
                @if ($tx['staff_name'])
                    <div class="text-xs text-mute">Served by {{ $tx['staff_name'] }}</div>
                @endif
                @if ($tx['customer_name'] || $tx['customer_phone'])
                    <div class="mt-1 text-xs text-mute">
                        Customer: {{ $tx['customer_name'] ?: '—' }}@if ($tx['customer_phone']) · {{ $tx['customer_phone'] }}@endif
                    </div>
                @endif

                <div class="my-4 border-t border-dashed border-line"></div>

                @if ($listItems !== [])
                    <div>
                        <div class="mb-1 text-[11px] font-semibold uppercase tracking-wide text-mute">Items</div>
                        <ul class="space-y-1 text-sm text-ink">
                            @foreach ($listItems as $item)
                                <li class="flex justify-between gap-2">
                                    <span class="min-w-0">
                                        {{ $item['model_name'] }}
                                        @if ($itemVariant($item) !== '' && $itemVariant($item) !== ($item['condition'] ?? ''))
                                            <span class="ml-1 align-middle text-xs font-normal text-mute">{{ $itemVariant($item) }}</span>
                                        @endif
                                        @if ($item['direction'] === 'in')
                                            <span class="badge-ok ml-1 align-middle">in</span>
                                        @endif
                                    </span>
                                    <span class="tnum shrink-0 text-mute">
                                        @if ($item['direction'] === 'in')
                                            +{{ $item['qty'] }}
                                        @else
                                            x{{ $item['qty'] }}
                                        @endif
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if ($tradeInLabels !== [])
                    <div class="mt-3">
                        <div class="mb-1 text-[11px] font-semibold uppercase tracking-wide text-mute">Trade-in received</div>
                        <ul class="space-y-1 text-sm text-ink">
                            @if ($swaps !== [])
                                @foreach ($swaps as $swap)
                                    <li class="flex flex-wrap items-center justify-between gap-2">
                                        <span class="flex flex-wrap items-center gap-2">
                                            <span>{{ $swap['model_name'] }}</span>
                                            <span class="{{ $swap['condition'] === 'new' ? 'badge-brand' : 'badge-muted' }}">{{ $swap['condition'] }}</span>
                                            <span class="badge-muted">{{ $swapStatusLabels[$swap['status']] ?? str_replace('_', ' ', $swap['status']) }}</span>
                                        </span>
                                        <span class="tnum shrink-0 text-xs text-mute">{{ \App\Support\Format::dateTime($swap['created_at']) }}</span>
                                    </li>
                                @endforeach
                            @else
                                @foreach ($tradeInLabels as $label)
                                    <li class="flex justify-between gap-2">
                                        <span>{{ $label }}</span>
                                    </li>
                                @endforeach
                            @endif
                        </ul>
                    </div>
                @endif

                <div class="my-4 border-t border-dashed border-line"></div>

                <div class="flex items-end justify-between">
                    <span class="text-sm font-medium text-mute">
                        @if ($tx['type'] === 'swap') Top-up paid
                        @elseif ($tx['type'] === 'repair') Repair charge
                        @else Total
                        @endif
                    </span>
                    <span class="text-xl font-bold text-ink">{{ \App\Support\Format::money($tx['amount']) }}</span>
                </div>
                <div class="mt-0.5 text-right text-xs text-mute">
                    Paid by {{ $paymentLabel }}@if ($tx['payment_reference']) · Ref {{ $tx['payment_reference'] }}@endif
                </div>
                @if ($tx['discount_reason'])
                    <p class="mt-2 text-right text-xs text-brand">Discount reason: {{ $tx['discount_reason'] }}</p>
                @endif
                @if ($tx['void_reason'])
                    <p class="mt-2 text-right text-xs text-lowstock">Void reason: {{ $tx['void_reason'] }}</p>
                @endif

                @if ($is_owner && $events !== [])
                    <div class="no-print mt-5 rounded-lg border border-line bg-paper p-3">
                        <div class="text-[11px] font-semibold uppercase tracking-wide text-mute">Audit history</div>
                        <ul class="mt-2 space-y-2">
                            @foreach ($events as $event)
                                @php
                                    $eventLabel = ['created' => 'Recorded', 'approved' => 'Approved', 'rejected' => 'Rejected'][$event['action']] ?? 'Voided';
                                @endphp
                                <li class="flex items-start justify-between gap-3 text-xs">
                                    <span class="text-ink">
                                        <b>{{ $eventLabel }}</b>@if ($event['actor_name']) by {{ $event['actor_name'] }}@endif
                                    </span>
                                    <span class="shrink-0 text-mute">{{ \App\Support\Format::dateTime($event['created_at']) }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <p class="mt-5 text-center text-xs text-mute">Thank you for your business!</p>
            </div>

            <div class="no-print flex flex-wrap gap-2">
                <a href="{{ $waUrl }}" target="_blank" rel="noopener" class="btn-primary flex-1">Send on WhatsApp</a>
                <button type="button" class="btn-secondary flex-1" x-on:click="window.print()">Print / Save PDF</button>
            </div>

            @if ($is_owner && $tx['status'] === 'pending_review')
                {{-- Owner: discount review — approve or reject (with a reason). --}}
                <div class="card no-print">
                    <h2 class="section-title">Review</h2>
                    <div class="mt-3 rounded-xl border border-brand bg-brand-tint/40 p-3">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="badge-warn">discount review</span>
                                    <span class="text-sm font-bold text-ink">{{ $tx['customer_name'] ?: 'Walk-in' }}</span>
                                    <span class="text-xs text-mute">{{ $tx['shop_name'] ?? 'Shop' }} · {{ $tx['staff_name'] ?? 'Staff' }}</span>
                                </div>
                                <div class="mt-1 text-xs text-mute">
                                    {{ $outItems !== [] ? implode(', ', array_map(fn (array $i): string => $i['qty'].'× '.$i['model_name'], $outItems)) : 'No item details' }}
                                    · {{ \App\Support\Format::dateTime($tx['date']) }}
                                </div>
                                <div class="mt-1 text-sm font-semibold text-ink">
                                    Recorded {{ \App\Support\Format::money($tx['amount']) }}{{ $tx['discount_reason'] ? ' · '.$tx['discount_reason'] : '' }}
                                </div>
                            </div>

                            <div class="flex shrink-0 flex-wrap items-start justify-end gap-2">
                                <form method="POST" action="{{ route('transactions.review', $tx['id']) }}">
                                    @csrf
                                    <input type="hidden" name="decision" value="approve">
                                    <button type="submit" class="btn-primary btn-sm h-11 px-3">Approve</button>
                                </form>

                                <form method="POST" action="{{ route('transactions.review', $tx['id']) }}"
                                      class="flex flex-wrap items-center justify-end gap-2"
                                      x-data="{ asked: false, reason: '' }"
                                      x-on:submit="reason.trim() === '' && $event.preventDefault()">
                                    @csrf
                                    <input type="hidden" name="decision" value="reject">
                                    <button type="button" class="btn-danger btn-sm h-11 px-3" @click="asked = !asked">Reject</button>
                                    <span x-show="asked" class="flex items-center gap-2" x-cloak>
                                        <input type="text" name="reason" x-model="reason" autocomplete="off"
                                               placeholder="Reason for rejecting"
                                               class="input h-9 w-40 text-xs">
                                        <button type="submit" class="btn-secondary btn-sm h-9">Confirm reject</button>
                                    </span>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            @endif

            @if ($is_owner && $tx['status'] !== 'voided')
                {{-- Owner: void — stock effects are reversed, the receipt is kept. --}}
                <div class="card no-print" x-data="{ asked: false, reason: '', confirming: false, attempted: false }">
                    <h2 class="section-title">Void</h2>
                    <form method="POST" action="{{ route('transactions.void', $tx['id']) }}" x-ref="voidForm" class="mt-3">
                        @csrf
                        <div class="flex flex-wrap items-end gap-2">
                            <div class="min-w-0 flex-1">
                                <label class="label" for="voidReason">Reason for voiding</label>
                                <input id="voidReason" name="reason" type="text" class="input" x-model="reason"
                                       placeholder="Void reason" autocomplete="off">
                            </div>
                            <button type="button"
                                    class="text-xs font-medium text-lowstock hover:text-lowstock"
                                    @click="attempted = true; confirming = reason.trim() !== ''">
                                Void
                            </button>
                        </div>
                        <p class="field-error" x-show="attempted && reason.trim() === ''" x-cloak>Enter a reason before voiding.</p>
                    </form>

                    <template x-if="confirming">
                        <div class="fixed inset-0 z-50 flex items-end justify-center sm:items-center sm:p-4">
                            <div class="absolute inset-0 bg-ink/50" @click="confirming = false"></div>
                            <div class="relative z-10 w-full max-w-md rounded-t-2xl bg-white p-5 shadow-xl sm:rounded-2xl">
                                <h3 class="text-base font-semibold text-ink">Void this transaction?</h3>
                                <p class="mt-2 text-sm text-mute">
                                    Stock effects will be reversed, but the original receipt and audit history will be preserved.
                                </p>
                                <div class="mt-4 flex justify-end gap-2">
                                    <button type="button" class="btn-secondary" @click="confirming = false">Cancel</button>
                                    <button type="button" class="btn-danger" @click="confirming = false; $refs.voidForm.submit()">
                                        Void transaction
                                    </button>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>
            @endif
        </div>
    @endif
@endsection
