@extends('layouts.app')

@section('title', 'Record transaction — Mr Jeff Stock')
@section('mobileTitle', 'Record transaction')

@section('content')
    @php
        // Type-card glyphs — the original's inline stroke icons (there is no
        // shared icon name for sale/swap/repair in the shell's icon set).
        $iconSale = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z" /><path d="M3 6h18" /><path d="M16 10a4 4 0 0 1-8 0" /></svg>';
        $iconSwap = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 1 21 5l-4 4" /><path d="M3 11V9a4 4 0 0 1 4-4h14" /><path d="M7 23 3 19l4-4" /><path d="M21 13v2a4 4 0 0 1-4 4H3" /></svg>';
        $iconRepair = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z" /></svg>';

        // Trade-ins are picked from the same fixed iPhone list as the original.
        $iphoneModels = [
            'iPhone 7', 'iPhone 7 Plus', 'iPhone 8', 'iPhone 8 Plus',
            'iPhone X', 'iPhone XR', 'iPhone XS', 'iPhone XS Max',
            'iPhone 11', 'iPhone 11 Pro', 'iPhone 11 Pro Max',
            'iPhone 12', 'iPhone 12 mini', 'iPhone 12 Pro', 'iPhone 12 Pro Max',
            'iPhone 13', 'iPhone 13 mini', 'iPhone 13 Pro', 'iPhone 13 Pro Max',
            'iPhone 14', 'iPhone 14 Plus', 'iPhone 14 Pro', 'iPhone 14 Pro Max',
            'iPhone 15', 'iPhone 15 Plus', 'iPhone 15 Pro', 'iPhone 15 Pro Max',
            'iPhone 16', 'iPhone 16 Plus', 'iPhone 16 Pro', 'iPhone 16 Pro Max',
            'iPhone SE (2nd gen)', 'iPhone SE (3rd gen)',
        ];
    @endphp

    <div x-data="posForm()" class="mx-auto w-full max-w-5xl pb-6">
        <form method="POST" action="{{ route('transactions.store') }}"
              x-on:submit="validate($event)"
              data-offline-queue="repair" :data-shop-name="shopName()">
            @csrf

            {{-- Step 1 — what kind of transaction --}}
            <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-extrabold tracking-tight text-ink">Record transaction</h1>
                    <p class="mt-1 text-[13px] text-mute">Pick a type, add the phones, then take payment — all on one page.</p>
                </div>
                <div x-show="shopName() !== ''" x-cloak
                     class="flex items-center gap-2 rounded-full border border-line bg-white px-3.5 py-2 text-xs font-semibold text-ink/80">
                    <span class="h-1.5 w-1.5 rounded-full bg-instock" aria-hidden="true"></span>
                    <span x-text="shopName()"></span>
                    <span class="text-line">|</span>
                    <span class="font-medium text-mute" x-text="dateLabel()"></span>
                </div>
            </div>

            <div class="grid grid-cols-3 gap-2.5">
                <button type="button" @click="type = 'sale'"
                        :class="type === 'sale'
                            ? 'border-brand bg-brand-tint shadow-[0_4px_14px_rgba(67,56,202,0.18)]'
                            : 'border-line bg-white hover:border-brand/40 hover:bg-brand-tint/40'"
                        class="group flex min-h-[84px] flex-col items-center justify-center gap-1.5 rounded-2xl border-[1.5px] px-3 py-4 text-center transition-all">
                    <span :class="type === 'sale' ? 'bg-brand text-white' : 'bg-paper text-mute group-hover:bg-brand/10 group-hover:text-brand'"
                          class="flex h-9 w-9 items-center justify-center rounded-xl transition-colors">{!! $iconSale !!}</span>
                    <span class="text-[13.5px] font-extrabold tracking-tight text-ink">Sale</span>
                    <span class="text-[11px] leading-tight text-mute">Phone leaves the shop</span>
                </button>

                <button type="button" @click="type = 'swap'"
                        :class="type === 'swap'
                            ? 'border-brand bg-brand-tint shadow-[0_4px_14px_rgba(67,56,202,0.18)]'
                            : 'border-line bg-white hover:border-brand/40 hover:bg-brand-tint/40'"
                        class="group flex min-h-[84px] flex-col items-center justify-center gap-1.5 rounded-2xl border-[1.5px] px-3 py-4 text-center transition-all">
                    <span :class="type === 'swap' ? 'bg-brand text-white' : 'bg-paper text-mute group-hover:bg-brand/10 group-hover:text-brand'"
                          class="flex h-9 w-9 items-center justify-center rounded-xl transition-colors">{!! $iconSwap !!}</span>
                    <span class="text-[13.5px] font-extrabold tracking-tight text-ink">Swap</span>
                    <span class="text-[11px] leading-tight text-mute">Out + trade-in + top-up</span>
                </button>

                <button type="button" @click="type = 'repair'"
                        :class="type === 'repair'
                            ? 'border-brand bg-brand-tint shadow-[0_4px_14px_rgba(67,56,202,0.18)]'
                            : 'border-line bg-white hover:border-brand/40 hover:bg-brand-tint/40'"
                        class="group flex min-h-[84px] flex-col items-center justify-center gap-1.5 rounded-2xl border-[1.5px] px-3 py-4 text-center transition-all">
                    <span :class="type === 'repair' ? 'bg-brand text-white' : 'bg-paper text-mute group-hover:bg-brand/10 group-hover:text-brand'"
                          class="flex h-9 w-9 items-center justify-center rounded-xl transition-colors">{!! $iconRepair !!}</span>
                    <span class="text-[13.5px] font-extrabold tracking-tight text-ink">Repair</span>
                    <span class="text-[11px] leading-tight text-mute">Logged for service</span>
                </button>
            </div>

            {{-- Owners pick the shop; attendants are locked to theirs. --}}
            @if ($isOwner)
                <div class="mt-3 sm:max-w-xs">
                    <label class="label" for="pos-shop">Shop</label>
                    <select id="pos-shop" name="shopId" class="input" x-model="shopId" @change="switchShop($event.target.value)">
                        @foreach ($shops as $shop)
                            <option value="{{ $shop['id'] }}" @selected($shop['id'] === $defaultShopId)>{{ $shop['name'] }}</option>
                        @endforeach
                    </select>
                </div>
            @else
                <input type="hidden" name="shopId" value="{{ $defaultShopId }}">
            @endif

            <input type="hidden" name="type" :value="type">
            <input type="hidden" name="idempotencyKey" :value="idempotencyKey">

            {{-- Everything below stacks full-width: phones, then customer/payment side-by-side, then the totals bar. --}}
            <div class="mt-5 space-y-4">
                {{-- Left: phones going out / repair note / trade-ins --}}
                <div class="min-w-0 space-y-4">
                    <template x-if="type !== 'repair'">
                        <section class="rounded-2xl border border-l-4 border-line border-l-lowstock bg-white shadow-[0_1px_2px_rgba(20,22,43,0.04)]">
                            <div class="w-full p-4 sm:p-5">
                                <div class="mb-3.5 flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <h2 class="text-[15px] font-bold tracking-tight text-ink">Phones going out</h2>
                                        <p class="mt-0.5 text-[12.5px] text-mute">These leave the shop's stock</p>
                                    </div>
                                    <button type="button" @click="addOut()"
                                            class="shrink-0 rounded-lg bg-brand-tint px-3 py-1.5 text-xs font-bold text-brand transition-colors hover:bg-brand/10">
                                        + Add phone
                                    </button>
                                </div>

                                <div class="space-y-2.5">
                                    <template x-for="(line, i) in outLines" :key="line.key">
                                        <div class="rounded-xl border border-line bg-paper p-3">
                                            <div class="flex items-end gap-2.5">
                                                <div class="relative min-w-0 flex-1">
                                                    <label class="label" :for="'pos-model-' + i">Phone model</label>
                                                    <input type="hidden" :name="'outItems[' + i + '][modelId]'" :value="line.modelId">
                                                    <input type="text" autocomplete="off" aria-haspopup="listbox"
                                                           :id="'pos-model-' + i"
                                                           class="input" placeholder="Type to search model…"
                                                           :value="line.text"
                                                           @input="typeText(line, $event.target.value)"
                                                           @focus="line.open = true"
                                                           @blur="setTimeout(() => line.open = false, 120)"
                                                           @keydown="pickerKey($event, line)">

                                                    <div x-show="line.open" x-cloak
                                                         class="absolute z-20 mt-1 max-h-64 min-w-full overflow-y-auto rounded-lg border border-line bg-white shadow-lg">
                                                        <ul x-show="matches(line).length > 0" role="listbox">
                                                            <template x-for="(m, mi) in matches(line)" :key="m.id">
                                                                <li role="option" :aria-selected="mi === line.hl"
                                                                    @mousedown.prevent="pick(line, m)"
                                                                    @mouseenter="line.hl = mi"
                                                                    :class="mi === line.hl ? 'bg-paper' : ''"
                                                                    class="flex cursor-pointer items-center justify-between gap-2 px-3 py-2 text-sm">
                                                                    <span class="flex items-center gap-2">
                                                                        <span class="whitespace-nowrap font-medium text-ink" x-text="m.model_name"></span>
                                                                        <span class="badge" :class="m.condition === 'new' ? 'badge-brand' : 'badge-muted'" x-text="m.condition"></span>
                                                                    </span>
                                                                    <span class="shrink-0 text-xs" :class="low(m) ? 'text-lowstock' : 'text-mute'"
                                                                          x-text="m.available + ' in stock'"></span>
                                                                </li>
                                                            </template>
                                                        </ul>
                                                        <div x-show="matches(line).length === 0" x-cloak class="px-3 py-2 text-sm text-mute">
                                                            No matching models.
                                                        </div>
                                                    </div>
                                                </div>

                                                <div class="inline-flex shrink-0 items-center overflow-hidden rounded-[10px] border border-line bg-white">
                                                    <button type="button" aria-label="Decrease quantity" @click="stepQty(i, -1)"
                                                            class="flex h-11 w-10 items-center justify-center bg-paper text-lg text-ink transition-colors hover:bg-line/50 active:bg-line">&minus;</button>
                                                    <input type="number" inputmode="numeric" min="1"
                                                           x-model="line.qty" @blur="normaliseQty(line)"
                                                           :name="'outItems[' + i + '][qty]'"
                                                           class="h-11 w-10 border-x border-line bg-white text-center font-mono text-sm font-bold tabular-nums text-ink [appearance:textfield] focus:outline-none [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none">
                                                    <button type="button" aria-label="Increase quantity" @click="stepQty(i, 1)"
                                                            class="flex h-11 w-10 items-center justify-center bg-paper text-lg text-ink transition-colors hover:bg-line/50 active:bg-line">+</button>
                                                </div>

                                                <button type="button" aria-label="Remove phone" @click="removeOut(i)"
                                                        class="mb-0.5 inline-flex h-11 w-10 shrink-0 items-center justify-center rounded-lg text-mute transition-colors hover:bg-lowstock-tint hover:text-lowstock">
                                                    &#10005;
                                                </button>
                                            </div>

                                            <p class="mt-2 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs" x-show="line.modelId" x-cloak>
                                                <span class="badge" :class="model(line.modelId).condition === 'new' ? 'badge-brand' : 'badge-muted'"
                                                      x-text="model(line.modelId).condition"></span>
                                                <span class="text-mute" x-show="model(line.modelId).sale_price != null">
                                                    Listed <span class="tnum" x-text="money(model(line.modelId).sale_price)"></span>
                                                </span>
                                                <span :class="low(model(line.modelId)) ? 'text-lowstock' : 'text-mute'"
                                                      x-text="stockHint(model(line.modelId))"></span>
                                            </p>
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </section>
                    </template>

                    <template x-if="type === 'repair'">
                        <div class="rounded-2xl border border-line bg-white p-5 shadow-[0_1px_2px_rgba(20,22,43,0.04)]">
                            <div class="flex items-start gap-3">
                                <span class="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-brand-tint text-brand">{!! $iconRepair !!}</span>
                                <div>
                                    <h2 class="text-[15px] font-bold tracking-tight text-ink">Service-only repair</h2>
                                    <p class="mt-1 text-[13px] leading-relaxed text-mute">
                                        The customer&rsquo;s phone comes in and goes back with them — no stock moves.
                                        Only the repair charge below is recorded, and an offline repair stays
                                        <span class="font-semibold text-brand"> awaiting sync </span> until the server confirms it.
                                    </p>
                                </div>
                            </div>
                        </div>
                    </template>

                    {{-- What comes in: the trade-in model, no valuation. --}}
                    <template x-if="type === 'swap'">
                        <section class="rounded-2xl border border-l-4 border-line border-l-instock bg-white shadow-[0_1px_2px_rgba(20,22,43,0.04)]">
                            <div class="w-full p-4 sm:p-5">
                                <div class="mb-3.5 flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <h2 class="text-[15px] font-bold tracking-tight text-ink">Old iPhone received</h2>
                                        <p class="mt-0.5 text-[12.5px] text-mute">The trade-in model — no need to enter its details</p>
                                    </div>
                                    <button type="button" @click="addSwap()"
                                            class="shrink-0 rounded-lg bg-instock-tint px-3 py-1.5 text-xs font-bold text-instock transition-colors hover:bg-instock/10">
                                        + Add phone
                                    </button>
                                </div>

                                <div class="space-y-2.5">
                                    <template x-for="(line, i) in swapLines" :key="line.key">
                                        <div class="flex items-end gap-2.5 rounded-xl border border-line bg-paper p-3">
                                            <div class="min-w-0 flex-1">
                                                <label class="label" :for="'pos-swap-' + i">iPhone model</label>
                                                <select class="input" :id="'pos-swap-' + i" x-model="line.name" :name="'swapIn[' + i + '][name]'">
                                                    <option value="">Select iPhone model…</option>
                                                    @foreach ($iphoneModels as $iphone)
                                                        <option value="{{ $iphone }}">{{ $iphone }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <button type="button" aria-label="Remove" @click="removeSwap(i)"
                                                    class="mb-0.5 inline-flex h-11 w-10 shrink-0 items-center justify-center rounded-lg text-mute transition-colors hover:bg-lowstock-tint hover:text-lowstock">
                                                &#10005;
                                            </button>
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </section>
                    </template>
                </div>

                {{-- Customer + payment, side by side on desktop --}}
                <div class="grid items-stretch gap-4 lg:grid-cols-2">
                    <section class="flex h-full flex-col rounded-2xl border border-l-4 border-line border-l-brand bg-white shadow-[0_1px_2px_rgba(20,22,43,0.04)]">
                        <div class="flex w-full flex-1 flex-col p-4 sm:p-5">
                            <div class="mb-3.5 flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <h2 class="text-[15px] font-bold tracking-tight text-ink">Customer</h2>
                                </div>
                            </div>
                            <div class="grid gap-3 sm:grid-cols-2">
                                <div>
                                    <label class="label" for="customerName">Name <span class="ml-0.5 text-lowstock">*</span></label>
                                    <input id="customerName" name="customerName" class="input" x-model="customerName"
                                           placeholder="Customer name" autocomplete="off">
                                </div>
                                <div>
                                    <label class="label" for="customerPhone">Phone <span class="ml-0.5 text-lowstock">*</span></label>
                                    <input id="customerPhone" name="customerPhone" class="input" x-model="customerPhone"
                                           placeholder="Customer phone" autocomplete="off">
                                </div>
                            </div>
                            <p class="mt-auto pt-3 text-[11.5px] leading-relaxed text-mute">
                                Name and phone are required for every sale — they print on the receipt.
                            </p>
                        </div>
                    </section>

                    <section class="h-full rounded-2xl border border-l-4 border-line border-l-brand bg-white shadow-[0_1px_2px_rgba(20,22,43,0.04)]">
                        <div class="w-full p-4 sm:p-5">
                            <div class="mb-3.5 flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <h2 class="text-[15px] font-bold tracking-tight text-ink">Payment</h2>
                                    <p class="mt-0.5 text-[12.5px] text-mute" x-text="paymentSub()"></p>
                                </div>
                            </div>

                            <div class="space-y-3">
                                <div class="relative">
                                    <span class="absolute left-3.5 top-1/2 -translate-y-1/2 text-[13px] font-bold text-mute">GHS</span>
                                    <input type="number" min="0" step="0.01" placeholder="0.00" name="amount"
                                           x-model="amount"
                                           class="h-14 w-full rounded-lg border border-line bg-white pl-12 pr-3 font-mono text-lg font-bold text-ink outline-none transition focus:border-brand focus:ring-2 focus:ring-brand/15">
                                </div>

                                <button type="button" x-show="suggested() != null && suggested() > 0" x-cloak
                                        @click="amount = String(suggested())"
                                        class="text-xs font-bold text-brand underline underline-offset-2 hover:text-brand-deep">
                                    Use suggested amount — <span class="tnum" x-text="money(suggested())"></span>
                                </button>

                                <div class="grid gap-3 sm:grid-cols-2">
                                    <div>
                                        <label class="label" for="paymentMethod">Payment method</label>
                                        <select id="paymentMethod" name="paymentMethod" class="input" x-model="paymentMethod">
                                            <option value="cash">Cash</option>
                                            <option value="mobile_money">Mobile money</option>
                                            <option value="card">Card</option>
                                            <option value="bank_transfer">Bank transfer</option>
                                            <option value="other">Other</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="label" for="paymentReference">Payment reference</label>
                                        <input id="paymentReference" name="paymentReference" class="input" x-model="paymentReference"
                                               :placeholder="paymentMethod === 'mobile_money' ? 'MoMo reference' : 'optional'"
                                               autocomplete="off">
                                    </div>
                                    <div>
                                        <label class="label" for="txDate">Date</label>
                                        <input id="txDate" type="date" name="date" class="input" x-model="date">
                                    </div>
                                </div>

                                <div x-show="type === 'sale' && belowList()" x-cloak>
                                    <label class="label" for="discountReason">Discount reason <span class="ml-0.5 text-lowstock">*</span></label>
                                    <input id="discountReason" name="discountReason" class="input" x-model="discountReason"
                                           placeholder="Why is this below the listed price?" autocomplete="off">
                                </div>

                                <p x-show="type === 'repair' && !online" x-cloak
                                   class="rounded-lg border border-brand bg-brand-tint px-3 py-2 text-xs text-brand">
                                    Offline repair charges are saved as awaiting sync and do not count as completed revenue until the server receives them.
                                </p>

                                <p x-show="type === 'sale' && belowList()" x-cloak
                                   class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-800">
                                    Below list price — this sale will be saved for the owner&rsquo;s review before it counts as revenue.
                                </p>
                            </div>
                        </div>
                    </section>

                </div>

                {{-- Totals bar: the running ticket on the left, save on the right --}}
                <div class="grid items-start gap-4 lg:grid-cols-[minmax(0,1fr)_320px]">
                    <section class="min-w-0 rounded-2xl border border-line bg-white shadow-[0_1px_2px_rgba(20,22,43,0.04)]">
                        <div class="w-full p-4 sm:p-5">
                            <div class="mb-3.5">
                                <h2 class="text-[15px] font-bold tracking-tight text-ink">Order summary</h2>
                                <p class="mt-0.5 text-[12.5px] text-mute">What this transaction records</p>
                            </div>
                            <div class="overflow-hidden rounded-xl border border-line">
                                <div class="bg-paper px-3.5 py-2 text-[10.5px] font-bold uppercase tracking-wider text-mute"
                                     x-text="summaryTitle()"></div>
                                <div class="divide-y divide-line/70">
                                    <p class="px-3.5 py-2.5 text-xs text-mute" x-show="validOut().length === 0 && type !== 'repair'">
                                        No phones added yet.
                                    </p>
                                    <template x-for="line in validOut()" :key="line.key">
                                        <div class="flex items-center justify-between gap-3 px-3.5 py-2 text-[12.5px]">
                                            <span class="min-w-0 truncate text-ink/90">
                                                <b class="font-mono font-semibold text-mute" x-text="line.qty + '\u00d7'"></b>
                                                <span x-text="model(line.modelId).model_name"></span>
                                            </span>
                                            <span class="shrink-0 font-mono font-semibold tabular-nums text-ink"
                                                  x-text="money(model(line.modelId).sale_price * Number(line.qty))"></span>
                                        </div>
                                    </template>
                                    <template x-for="line in summarySwaps()" :key="line.key">
                                        <div class="flex items-center justify-between gap-3 px-3.5 py-2 text-[12.5px]">
                                            <span class="truncate text-ink/90">
                                                <b class="font-mono font-semibold text-mute">IN</b>
                                                <span x-text="line.name"></span> (trade-in)
                                            </span>
                                            <span class="shrink-0 text-[11px] font-semibold uppercase tracking-wide text-instock">+valued</span>
                                        </div>
                                    </template>
                                    <p class="px-3.5 py-2.5 text-xs text-mute" x-show="type === 'repair'">
                                        Repair charge — no stock movement.
                                    </p>
                                    <div class="flex items-center justify-between gap-3 bg-white px-3.5 py-2.5">
                                        <span class="text-[12px] font-bold uppercase tracking-wide text-mute"
                                              x-text="type === 'swap' ? 'Total top-up' : 'Total due'"></span>
                                        <span class="font-mono text-base font-bold tabular-nums text-ink"
                                              x-text="money(amountValid() ? enteredAmount() : 0)"></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </section>

                    <div class="min-w-0 space-y-3">
                        <div x-show="error !== ''" x-cloak
                             class="rounded-lg border border-lowstock bg-lowstock-tint px-3 py-2 text-sm text-lowstock" x-text="error"></div>

                        <button type="submit"
                                class="flex h-12 w-full items-center justify-center gap-2 rounded-xl text-[14px] font-bold tracking-tight transition-all"
                                :class="saving
                                    ? 'cursor-wait bg-line text-mute'
                                    : 'bg-brand text-white shadow-[0_6px_16px_rgba(67,56,202,0.3)] hover:bg-brand-deep hover:shadow-[0_8px_20px_rgba(67,56,202,0.35)] active:translate-y-px'">
                            <span x-text="saving ? 'Saving…' : saveLabel()"></span>
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M5 12h14M12 5l7 7-7 7" />
                            </svg>
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </div>
@endsection

@push('scripts')
    <script>
        function posForm() {
            const shops = {!! json_encode($shops, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!};
            const stock = {!! json_encode($stock, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!};
            const EMPTY_MODEL = { id: '', shop_id: '', model_name: '', condition: '', cost_price: null, sale_price: null, available: 0, low_stock_threshold: 0 };
            let nextKey = 1;
            const blankOut = () => ({ key: nextKey++, modelId: '', qty: '1', text: '', open: false, hl: 0 });
            const blankSwap = () => ({ key: nextKey++, name: '' });

            function uuid() {
                if (typeof crypto !== 'undefined' && crypto.randomUUID) return crypto.randomUUID();
                return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
                    const r = (Math.random() * 16) | 0;
                    return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
                });
            }

            return {
                type: 'sale',
                shopId: {{ \Illuminate\Support\Js::from($defaultShopId) }},
                customerName: {{ \Illuminate\Support\Js::from(old('customerName', '')) }},
                customerPhone: {{ \Illuminate\Support\Js::from(old('customerPhone', '')) }},
                paymentMethod: {{ \Illuminate\Support\Js::from(old('paymentMethod', 'cash')) }},
                amount: {{ \Illuminate\Support\Js::from(old('amount', '')) }},
                discountReason: {{ \Illuminate\Support\Js::from(old('discountReason', '')) }},
                paymentReference: {{ \Illuminate\Support\Js::from(old('paymentReference', '')) }},
                date: {{ \Illuminate\Support\Js::from(old('date', \App\Support\Format::today())) }},
                idempotencyKey: uuid(),
                outLines: [blankOut()],
                swapLines: [blankSwap()],
                error: '',
                saving: false,
                online: typeof navigator !== 'undefined' ? navigator.onLine : true,

                init() {
                    // One key per filled-in form: generated on mount and again
                    // after each successful submit (a redirect remounts the
                    // form), so a retry of the same submission reuses it.
                    this.idempotencyKey = uuid();

                    this.online = navigator.onLine;
                    window.addEventListener('online', () => { this.online = true; });
                    window.addEventListener('offline', () => { this.online = false; });

                    // Warn before leaving with a half-filled form.
                    window.addEventListener('beforeunload', (e) => {
                        if (this.dirty()) {
                            e.preventDefault();
                            e.returnValue = '';
                        }
                    });
                },

                shopModels() { return stock.filter((m) => m.shop_id === this.shopId); },
                model(id) { return this.shopModels().find((m) => m.id === id) || EMPTY_MODEL; },
                shopName() {
                    const found = shops.find((s) => s.id === this.shopId);
                    return found ? found.name : '';
                },
                money(n) {
                    return 'GHS ' + new Intl.NumberFormat('en-GB', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(Number(n) || 0);
                },
                dateLabel() {
                    const d = new Date(this.date + 'T12:00:00');
                    if (isNaN(d.getTime())) return '';
                    return d.toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short' });
                },
                validOut() { return this.outLines.filter((l) => l.modelId && Number(l.qty) > 0); },
                validSwap() { return this.swapLines.filter((l) => l.name.trim()); },
                summarySwaps() { return this.type === 'swap' ? this.validSwap() : []; },
                unitsOut() { return this.validOut().reduce((a, l) => a + Number(l.qty), 0); },
                suggested() {
                    if (this.type !== 'sale') return null;
                    return this.validOut().reduce((sum, l) => {
                        const m = this.model(l.modelId);
                        return sum + (m.sale_price != null ? m.sale_price * Number(l.qty) : 0);
                    }, 0);
                },
                amountValid() { const n = Number(this.amount); return isFinite(n) && n >= 0; },
                enteredAmount() { const n = Number(this.amount); return isFinite(n) && n >= 0 ? n : 0; },
                belowList() {
                    const s = this.suggested();
                    return s != null && s > 0 && Number(this.amount) < s;
                },
                low(m) { return m != null && m.available <= m.low_stock_threshold; },
                stockHint(m) { return m.available + ' in stock' + (this.low(m) ? ' \u00b7 low!' : ''); },
                saveLabel() {
                    if (this.type === 'swap') return 'Record swap';
                    if (this.type === 'repair') return 'Record repair charge';
                    return 'Record sale';
                },
                paymentSub() {
                    if (this.type === 'swap') return 'Top-up cash (GHS)';
                    if (this.type === 'repair') return 'Repair charge (GHS)';
                    return 'Total sale amount (GHS)';
                },
                summaryTitle() {
                    if (this.type === 'swap') return 'Swap summary';
                    if (this.type === 'repair') return 'Repair summary';
                    return 'Order summary';
                },
                dirty() {
                    return !this.saving && (
                        this.customerName.trim() !== '' ||
                        this.customerPhone.trim() !== '' ||
                        this.amount !== '' ||
                        this.validOut().length > 0
                    );
                },
                switchShop(id) {
                    this.shopId = id;
                    this.outLines = [blankOut()];
                    this.swapLines = [blankSwap()];
                },
                addOut() { this.outLines.push(blankOut()); },
                removeOut(i) {
                    this.outLines = this.outLines.length > 1
                        ? this.outLines.filter((_, idx) => idx !== i)
                        : [blankOut()];
                },
                addSwap() { this.swapLines.push(blankSwap()); },
                removeSwap(i) {
                    this.swapLines = this.swapLines.length > 1
                        ? this.swapLines.filter((_, idx) => idx !== i)
                        : [blankSwap()];
                },
                stepQty(i, delta) {
                    const line = this.outLines[i];
                    line.qty = String(Math.max(1, (Number(line.qty) || 1) + delta));
                },
                normaliseQty(line) {
                    line.qty = String(Math.max(1, Math.floor(Number(line.qty) || 1)));
                },
                typeText(line, value) {
                    line.text = value;
                    line.open = true;
                    line.hl = 0;
                    if (line.modelId) line.modelId = '';
                },
                matches(line) {
                    const q = (line.text || '').trim().toLowerCase();
                    const models = this.shopModels();
                    const found = q ? models.filter((m) => m.model_name.toLowerCase().includes(q)) : models;
                    return found.slice(0, 30);
                },
                pick(line, m) {
                    line.modelId = m.id;
                    line.text = m.model_name;
                    line.open = false;
                    line.hl = 0;
                },
                pickerKey(event, line) {
                    const list = this.matches(line);
                    if (event.key === 'ArrowDown') {
                        event.preventDefault();
                        line.open = true;
                        line.hl = (line.hl + 1) % Math.max(list.length, 1);
                    } else if (event.key === 'ArrowUp') {
                        event.preventDefault();
                        line.open = true;
                        line.hl = (line.hl - 1 + Math.max(list.length, 1)) % Math.max(list.length, 1);
                    } else if (event.key === 'Enter') {
                        event.preventDefault();
                        if (line.open && list[line.hl]) this.pick(line, list[line.hl]);
                        else if (list.length === 1 && line.text && !line.open) this.pick(line, list[0]);
                    } else if (event.key === 'Escape') {
                        line.open = false;
                    }
                },
                validate(event) {
                    // The offline queue hook may already have taken the form
                    // (capture phase); never fight it.
                    if (event.defaultPrevented) return;

                    this.error = '';
                    if (this.type !== 'repair' && this.validOut().length === 0) {
                        this.error = 'Add at least one phone going out.';
                        event.preventDefault();
                        return;
                    }
                    if (this.type === 'swap' && this.validSwap().length === 0) {
                        this.error = 'Add the old phone the customer is trading in.';
                        event.preventDefault();
                        return;
                    }
                    if (!this.amountValid()) {
                        this.error = 'Enter a valid amount.';
                        event.preventDefault();
                        return;
                    }
                    if (!this.customerName.trim() || !this.customerPhone.trim()) {
                        this.error = 'Customer name and phone are required.';
                        event.preventDefault();
                        return;
                    }
                    if (this.type === 'sale' && this.belowList() && !this.discountReason.trim()) {
                        this.error = 'Add a reason for the discount before saving.';
                        event.preventDefault();
                        return;
                    }

                    this.saving = true;
                },
            };
        }
    </script>
@endpush
