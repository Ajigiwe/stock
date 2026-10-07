@props(['model', 'shop' => '', 'canEdit' => false, 'canTransfer' => false, 'shops' => [], 'adjustments' => [], 'simTypes' => [], 'categories' => []])

{{-- Product edit + adjust modal — port of src/components/product-edit-modal.tsx.
     Owner gets the product form and applies adjustments directly; anyone else
     still submits the same fields as a stock request. --}}
@php
    $low = $model['available'] <= $model['low_stock_threshold'];
    $margin = $model['cost_price'] !== null && $model['sale_price'] !== null
        ? $model['sale_price'] - $model['cost_price']
        : null;
    $marginPct = $margin !== null && $model['sale_price'] > 0
        ? (int) round(($margin / $model['sale_price']) * 100)
        : null;
@endphp

<div x-data="{ open: false }">
    <button type="button" @click="open = true" class="btn btn-secondary btn-sm">Edit</button>

    <x-shop-modal size="lg" show="open" onClose="open = false">
        <x-slot name="heading">
            <span class="flex items-center gap-2">
                {{ $model['model_name'] }}
                <span class="{{ $model['condition'] === 'new' ? 'badge-brand' : 'badge-muted' }}">{{ $model['condition'] }}</span>
                @if (($model['sim_type'] ?? '') !== '')
                    <span class="badge-muted">{{ $simTypes[$model['sim_type']] ?? $model['sim_type'] }}</span>
                @endif
                @if (($model['color'] ?? '') !== '')
                    <span class="text-sm text-mute">{{ $model['color'] }}</span>
                @endif
                @if (($model['category'] ?? 'phone') !== 'phone')
                    <span class="badge-brand">{{ $categories[$model['category']] ?? $model['category'] }}</span>
                @endif
            </span>
        </x-slot>
        <x-slot name="sub">Edit product details and adjust stock</x-slot>

        <div class="grid grid-cols-3 gap-2 rounded-xl border border-line bg-paper p-3 text-center">
            <div>
                <div class="text-xs font-medium uppercase tracking-wide text-mute">Opening</div>
                <div class="text-sm font-bold text-ink">{{ $model['opening_stock'] }}</div>
            </div>
            <div>
                <div class="text-xs font-medium uppercase tracking-wide text-mute">Bought in</div>
                <div class="text-sm font-bold text-ink">{{ $model['bought_in'] }}</div>
            </div>
            <div>
                <div class="text-xs font-medium uppercase tracking-wide text-mute">Available</div>
                <div class="text-sm font-bold {{ $low ? 'text-lowstock' : 'text-ink' }}">{{ $model['available'] }}</div>
            </div>
        </div>

        @if ($low)
            <p class="mt-2 text-xs font-medium text-lowstock">
                Low stock — at or below the threshold of {{ $model['low_stock_threshold'] }}.
            </p>
        @endif

        @if ($margin !== null)
            <p class="mt-2 text-xs text-mute">
                Unit margin: <span class="font-semibold text-instock">{{ \App\Support\Format::money($margin) }}</span>@if ($marginPct !== null) ({{ $marginPct }}%)@endif · stock value at cost <span class="font-medium text-ink/80">{{ \App\Support\Format::money($model['cost_price'] * $model['available']) }}</span>
            </p>
        @endif

        <div class="mt-4">
            <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-mute">Product details</h3>
            @if (! $canEdit)
                <p class="mb-2 rounded-lg border border-brand bg-brand-tint px-3 py-2 text-xs text-brand">
                    Editing product details requires stock privileges — only the owner can grant them.
                </p>
            @endif
            @if ($canEdit)
                <form method="POST" action="{{ route('models.update', ['shop' => $shop, 'model' => $model['id']]) }}" class="space-y-3">
                    @csrf
            @endif
                    <div>
                        <label class="label">Model name</label>
                        <input type="text" name="modelName" class="input"
                               value="{{ old('modelName', $model['model_name']) }}"
                               placeholder='e.g. "iPhone 13 128GB"'>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="label">Condition</label>
                            <select name="condition" class="input">
                                <option value="new" @if (old('condition', $model['condition']) === 'new') selected @endif>New</option>
                                <option value="used" @if (old('condition', $model['condition']) === 'used') selected @endif>Used</option>
                            </select>
                        </div>
                        <div>
                            <label class="label">Low-stock threshold</label>
                            <input type="number" min="0" name="lowStockThreshold" class="input"
                                   value="{{ old('lowStockThreshold', $model['low_stock_threshold']) }}">
                        </div>
                        <div>
                            <label class="label">SIM type</label>
                            <select name="simType" class="input">
                                <option value="">Unspecified</option>
                                @foreach ($simTypes as $value => $label)
                                    <option value="{{ $value }}" @if (old('simType', $model['sim_type'] ?? '') === $value) selected @endif>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="label">Color</label>
                            <input type="text" name="color" class="input" maxlength="64"
                                   value="{{ old('color', $model['color'] ?? '') }}" placeholder="optional">
                        </div>
                        <div>
                            <label class="label">Category</label>
                            <select name="category" class="input">
                                @foreach ($categories as $value => $label)
                                    <option value="{{ $value }}" @if (old('category', $model['category'] ?? 'phone') === $value) selected @endif>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="label">Cost price (GHS)</label>
                            <input type="number" min="0" step="0.01" name="costPrice" class="input"
                                   value="{{ old('costPrice', $model['cost_price']) }}" placeholder="optional">
                        </div>
                        <div>
                            <label class="label">Sale price (GHS)</label>
                            <input type="number" min="0" step="0.01" name="salePrice" class="input"
                                   value="{{ old('salePrice', $model['sale_price']) }}" placeholder="optional">
                        </div>
                    </div>
                    @if ($canEdit)
                        <button type="submit" class="btn btn-primary h-9 w-full">Save product</button>
                </form>
            @endif
        </div>

        <form method="POST" action="{{ route('models.adjust', ['shop' => $shop, 'model' => $model['id']]) }}"
              class="mt-5" x-data="{ qty: '1', type: 'restock' }">
            @csrf
            <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-mute">Adjust stock</h3>
            @if (! $canEdit)
                <p class="mb-2 rounded-lg border border-brand bg-brand-tint px-3 py-2 text-xs text-brand">
                    Stock changes are sent to the owner for approval.
                </p>
            @endif
            <div class="flex flex-wrap items-end gap-2">
                <div class="min-w-24 flex-1">
                    <label class="label">Qty</label>
                    <input type="number" min="1" class="input" x-model="qty" value="1">
                </div>
                <div class="min-w-28 flex-1">
                    <label class="label">Type</label>
                    <select class="input" x-model="type">
                        <option value="restock">Restock (+)</option>
                        <option value="correction">Correction (−)</option>
                    </select>
                </div>
                <div class="w-full">
                    <label class="label">Reason</label>
                    <input type="text" name="reason" class="input" placeholder="optional">
                </div>
            </div>
            <input type="hidden" name="delta" value="1"
                   :value="type === 'restock' ? Number(qty) : -Number(qty)">
            <button type="submit" class="btn btn-primary mt-3 h-9 w-full">
                {{ $canEdit ? 'Apply stock change' : 'Request stock change' }}
            </button>
        </form>

        @if ($canTransfer)
            <form method="POST" action="{{ route('models.transfer', ['shop' => $shop, 'model' => $model['id']]) }}" class="mt-5">
                @csrf
                <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-mute">Move to another shop</h3>
                <div class="grid gap-2 sm:grid-cols-2">
                    <div>
                        <label class="label">Destination shop</label>
                        <select name="toShopId" class="input">
                            @foreach ($shops as $dest)
                                @if ($dest['id'] !== $shop)
                                    <option value="{{ $dest['id'] }}">{{ $dest['name'] }}</option>
                                @endif
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="label">Quantity</label>
                        <input type="number" min="1" name="qty" value="1" class="input">
                    </div>
                    <div class="sm:col-span-2">
                        <label class="label">Reason</label>
                        <input type="text" name="reason" class="input" placeholder="optional">
                    </div>
                </div>
                <button type="submit" class="btn btn-secondary mt-3 h-9 w-full">Move stock</button>
            </form>
        @endif

        @if (count($adjustments) > 0)
            <div class="mt-5">
                <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-mute">Recent adjustments</h3>
                <ul class="space-y-1.5">
                    @foreach (array_slice($adjustments, 0, 6) as $adjustment)
                        <li class="flex items-center justify-between gap-2 rounded-lg bg-paper px-3 py-1.5 text-xs">
                            <span>
                                <span class="{{ $adjustment['delta'] > 0 ? 'badge-ok' : 'badge-danger' }}">{{ $adjustment['delta'] > 0 ? '+'.$adjustment['delta'] : $adjustment['delta'] }}</span>
                                <span class="text-mute">{{ $adjustment['type'] }}</span>@if ($adjustment['reason'])<span class="text-mute"> · {{ $adjustment['reason'] }}</span>@endif
                            </span>
                            <span class="text-mute">{{ \App\Support\Format::dateTime($adjustment['date']) }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif
    </x-shop-modal>
</div>
