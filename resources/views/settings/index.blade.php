@extends('layouts.app')

@section('title', 'Settings — Mr Jeff Stock')
@section('mobileTitle', 'Settings')

@section('content')
    <div class="space-y-6">
        <div>
            <h1 class="text-xl font-bold text-ink">Settings</h1>
            <p class="text-sm text-mute">Manage shops, staff, and data</p>
        </div>

        {{-- Shops --}}
        <section class="rounded-xl border border-line bg-white shadow-sm">
            <div class="flex items-center justify-between gap-3 border-b border-line px-4 py-3">
                <div>
                    <h2 class="text-sm font-semibold text-ink">Shops</h2>
                    <p class="mt-0.5 text-xs text-mute">Your shop locations</p>
                </div>
            </div>
            <div class="space-y-4 p-4" x-data="{ open: false }">
                <div x-show="!open" x-cloak>
                    <button type="button" class="btn-secondary btn-sm h-8" @click="open = true">+ Add shop</button>
                </div>

                <form method="POST" action="{{ route('settings.shops.store') }}" x-show="open" x-cloak
                      class="grid gap-3 rounded-lg border border-line bg-paper p-4 sm:grid-cols-3">
                    @csrf
                    <div>
                        <label class="label" for="shopName">Name</label>
                        <input id="shopName" name="name" type="text" class="input" autocomplete="off"
                               placeholder="e.g. Takoradi Market Circle">
                    </div>
                    <div>
                        <label class="label" for="shopLocation">Location</label>
                        <input id="shopLocation" name="location" type="text" class="input" autocomplete="off" placeholder="optional">
                    </div>
                    <div>
                        <label class="label" for="shopPhone">Phone</label>
                        <input id="shopPhone" name="phone" type="text" class="input" autocomplete="off" placeholder="optional">
                    </div>
                    <div class="flex gap-2 sm:col-span-3">
                        <button type="submit" class="btn-primary btn-sm h-8">Save</button>
                        <button type="button" class="btn-secondary btn-sm h-8" @click="open = false">Cancel</button>
                    </div>
                </form>

                <ul class="divide-y divide-paper">
                    @forelse ($shops as $shop)
                        <li class="flex items-center justify-between gap-3 py-2" x-data="{ confirming: false }">
                            <div>
                                <div class="text-sm font-medium text-ink">{{ $shop['name'] }}</div>
                                <div class="text-xs text-mute">
                                    {{ $shop['location'] ?? 'No location' }} · {{ $shop['phone'] ?? 'No phone' }}
                                </div>
                            </div>
                            <button type="button" class="btn-danger btn-sm h-8 px-2" @click="confirming = true">Delete</button>

                            <template x-if="confirming">
                                <div class="fixed inset-0 z-50 flex items-end justify-center sm:items-center sm:p-4">
                                    <div class="absolute inset-0 bg-ink/50" @click="confirming = false"></div>
                                    <div class="relative z-10 w-full max-w-md rounded-t-2xl bg-white p-5 shadow-xl sm:rounded-2xl">
                                        <h3 class="text-base font-semibold text-ink">Delete &quot;{{ $shop['name'] }}&quot;?</h3>
                                        <p class="mt-2 text-sm text-mute">
                                            All its stock and history will be removed. This cannot be undone.
                                        </p>
                                        <div class="mt-4 flex justify-end gap-2">
                                            <button type="button" class="btn-secondary" @click="confirming = false">Cancel</button>
                                            <form method="POST" action="{{ route('settings.shops.delete', $shop['id']) }}">
                                                @csrf
                                                <button type="submit" class="btn-danger">Delete shop</button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </template>
                        </li>
                    @empty
                        <li class="empty-state">No shops yet. Add your first shop above.</li>
                    @endforelse
                </ul>
            </div>
        </section>

        {{-- Staff --}}
        <section class="rounded-xl border border-line bg-white shadow-sm">
            <div class="flex items-center justify-between gap-3 border-b border-line px-4 py-3">
                <div>
                    <h2 class="text-sm font-semibold text-ink">Staff</h2>
                    <p class="mt-0.5 text-xs text-mute">Shop attendants</p>
                </div>
            </div>
            <div class="space-y-4 p-4" x-data="{ open: false }">
                <div x-show="!open" x-cloak>
                    <button type="button" class="btn-secondary btn-sm h-8" @click="open = true">+ Add staff</button>
                </div>

                <form method="POST" action="{{ route('settings.staff.store') }}" x-show="open" x-cloak
                      class="grid gap-3 rounded-lg border border-line bg-paper p-4 sm:grid-cols-2">
                    @csrf
                    <div>
                        <label class="label" for="staffName">Full name</label>
                        <input id="staffName" name="name" type="text" class="input" autocomplete="off">
                    </div>
                    <div>
                        <label class="label" for="staffEmail">Email</label>
                        <input id="staffEmail" name="email" type="email" class="input" autocomplete="off">
                    </div>
                    <div>
                        <label class="label" for="staffPassword">Temporary password</label>
                        <input id="staffPassword" name="password" type="password" class="input"
                               autocomplete="new-password" placeholder="min 6 characters">
                    </div>
                    <div>
                        <label class="label" for="staffShop">Shop</label>
                        <select id="staffShop" name="shopId" class="input">
                            @foreach ($shops as $shop)
                                <option value="{{ $shop['id'] }}">{{ $shop['name'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="flex gap-2">
                        <button type="submit" class="btn-primary btn-sm h-8">Create account</button>
                        <button type="button" class="btn-secondary btn-sm h-8" @click="open = false">Cancel</button>
                    </div>
                    <p class="text-xs text-mute sm:col-span-2">
                        The staff member signs in with this email and password, then can only
                        see and record their own shop.
                    </p>
                </form>

                @php
                    $shopNames = collect($shops)->mapWithKeys(fn (array $shop): array => [$shop['id'] => $shop['name']]);
                @endphp

                <ul class="divide-y divide-paper">
                    @forelse ($staff as $member)
                        <li class="py-2" x-data="{ resetOpen: false, pw: '' }">
                            <div class="flex items-center justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="text-sm font-medium text-ink">{{ $member['name'] }}</span>
                                        <span class="badge-muted">{{ $shopNames[$member['shop_id']] ?? 'No shop' }}</span>
                                        <span class="badge-brand">{{ $member['role'] }}</span>
                                        <span class="{{ $member['active'] ? 'badge-ok' : 'badge-danger' }}">
                                            {{ $member['active'] ? 'active' : 'deactivated' }}
                                        </span>
                                    </div>
                                    @if ($member['role'] === 'attendant')
                                        <div class="mt-1.5 text-xs text-mute">
                                            Stock edits go through your approval — no direct access.
                                        </div>
                                    @endif
                                </div>

                                <div class="flex shrink-0 flex-wrap justify-end gap-2">
                                    <button type="button" class="btn-secondary btn-sm h-8 px-2"
                                            @click="resetOpen = !resetOpen; pw = ''">Reset password</button>

                                    @if ($member['active'])
                                        <span class="inline-flex" x-data="{ confirming: false }">
                                            <button type="button" class="btn-danger btn-sm h-8 px-2" @click="confirming = true">Deactivate</button>
                                            <template x-if="confirming">
                                                <div class="fixed inset-0 z-50 flex items-end justify-center sm:items-center sm:p-4">
                                                    <div class="absolute inset-0 bg-ink/50" @click="confirming = false"></div>
                                                    <div class="relative z-10 w-full max-w-md rounded-t-2xl bg-white p-5 shadow-xl sm:rounded-2xl">
                                                        <h3 class="text-base font-semibold text-ink">Remove {{ $member['name'] }}?</h3>
                                                        <p class="mt-2 text-sm text-mute">
                                                            They will be signed out and unable to log in. Their transaction and audit history will be preserved.
                                                        </p>
                                                        <div class="mt-4 flex justify-end gap-2">
                                                            <button type="button" class="btn-secondary" @click="confirming = false">Cancel</button>
                                                            <form method="POST" action="{{ route('settings.staff.deactivate', $member['id']) }}">
                                                                @csrf
                                                                <button type="submit" class="btn-danger">Deactivate staff</button>
                                                            </form>
                                                        </div>
                                                    </div>
                                                </div>
                                            </template>
                                        </span>
                                    @else
                                        <form method="POST" action="{{ route('settings.staff.reactivate', $member['id']) }}">
                                            @csrf
                                            <button type="submit" class="btn-secondary btn-sm h-8 px-2">Reactivate</button>
                                        </form>
                                    @endif
                                </div>
                            </div>

                            <form method="POST" action="{{ route('settings.staff.reset-password', $member['id']) }}"
                                  x-show="resetOpen" x-cloak
                                  class="mt-3 grid gap-3 rounded-lg border border-line bg-paper p-4 sm:grid-cols-2">
                                @csrf
                                <div>
                                    <label class="label" for="reset-{{ $member['id'] }}">New password for {{ $member['name'] }}</label>
                                    <input id="reset-{{ $member['id'] }}" name="password" type="password"
                                           class="input" x-model="pw" autocomplete="new-password" placeholder="min 6 characters">
                                </div>
                                <div class="flex items-end gap-2">
                                    <button type="submit" class="btn-primary btn-sm h-8">Save password</button>
                                    <button type="button" class="btn-secondary btn-sm h-8"
                                            @click="resetOpen = false; pw = ''">Cancel</button>
                                </div>
                            </form>
                        </li>
                    @empty
                        <li class="empty-state">No staff accounts yet.</li>
                    @endforelse
                </ul>
            </div>
        </section>

        {{-- Bulk add devices --}}
        <section class="rounded-xl border border-line bg-white shadow-sm">
            <div class="flex items-center justify-between gap-3 border-b border-line px-4 py-3">
                <div>
                    <h2 class="text-sm font-semibold text-ink">Bulk add devices</h2>
                    <p class="mt-0.5 text-xs text-mute">Import many phone models at once</p>
                </div>
            </div>
            <form method="POST" action="{{ route('settings.models.bulk') }}"
                  class="space-y-4 p-4"
                  x-data="{
                      shopId: '',
                      error: '',
                      rows: [{ model_name: '', condition: 'new', cost_price: '', sale_price: '', opening_stock: '0', low_stock_threshold: '5' }],
                      addRow() {
                          this.rows.push({ model_name: '', condition: 'new', cost_price: '', sale_price: '', opening_stock: '0', low_stock_threshold: '5' });
                      },
                      removeRow(i) {
                          this.rows = this.rows.length > 1
                              ? this.rows.filter((_, idx) => idx !== i)
                              : [{ model_name: '', condition: 'new', cost_price: '', sale_price: '', opening_stock: '0', low_stock_threshold: '5' }];
                      },
                      check(e) {
                          this.error = '';
                          if (!this.shopId) { this.error = 'Select a shop first.'; e.preventDefault(); return; }
                          if (!this.rows.some((r) => r.model_name.trim())) { this.error = 'No rows to import.'; e.preventDefault(); }
                      }
                  }"
                  x-on:submit="check($event)">
                @csrf

                <div class="flex flex-wrap items-end gap-3">
                    <div class="min-w-48 flex-1">
                        <label class="label" for="bulkShop">Shop</label>
                        <select id="bulkShop" name="shopId" class="input" x-model="shopId">
                            <option value="">Select a shop…</option>
                            @foreach ($shops as $shop)
                                <option value="{{ $shop['id'] }}">{{ $shop['name'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <button type="button" class="btn-secondary btn-sm h-8" @click="addRow()">+ Add row</button>
                </div>

                <div class="overflow-x-auto rounded-lg border border-line">
                    <table class="table-base min-w-[720px]">
                        <thead>
                            <tr>
                                <th scope="col">Model name</th>
                                <th scope="col">Condition</th>
                                <th scope="col">Cost price</th>
                                <th scope="col">Sale price</th>
                                <th scope="col">Opening stock</th>
                                <th scope="col">Low-stock threshold</th>
                                <th scope="col"><span class="sr-only">Remove</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="(row, i) in rows" :key="i">
                                <tr>
                                    <td>
                                        <input type="text" class="input" autocomplete="off" placeholder="e.g. iPhone 14 128GB"
                                               x-model="row.model_name" :name="'rows[' + i + '][model_name]'">
                                    </td>
                                    <td>
                                        <select class="input" x-model="row.condition" :name="'rows[' + i + '][condition]'">
                                            <option value="new">new</option>
                                            <option value="used">used</option>
                                        </select>
                                    </td>
                                    <td>
                                        <input type="text" inputmode="decimal" class="input" autocomplete="off" placeholder="optional"
                                               x-model="row.cost_price" :name="'rows[' + i + '][cost_price]'">
                                    </td>
                                    <td>
                                        <input type="text" inputmode="decimal" class="input" autocomplete="off" placeholder="optional"
                                               x-model="row.sale_price" :name="'rows[' + i + '][sale_price]'">
                                    </td>
                                    <td>
                                        <input type="number" min="0" class="input" autocomplete="off"
                                               x-model="row.opening_stock" :name="'rows[' + i + '][opening_stock]'">
                                    </td>
                                    <td>
                                        <input type="number" min="0" class="input" autocomplete="off"
                                               x-model="row.low_stock_threshold" :name="'rows[' + i + '][low_stock_threshold]'">
                                    </td>
                                    <td class="text-right">
                                        <button type="button" class="btn-ghost btn-sm px-2" aria-label="Remove row"
                                                @click="removeRow(i)">&#10005;</button>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <button type="submit" class="btn-primary btn-sm h-8">Import devices</button>
                    <button type="button" class="btn-secondary btn-sm h-8" @click="addRow()">+ Add row</button>
                </div>

                <div x-show="error !== ''" x-cloak
                     class="rounded-lg border border-lowstock bg-lowstock-tint px-3 py-2 text-sm text-lowstock" x-text="error"></div>
            </form>
        </section>

        {{-- Backup & restore --}}
        <section class="rounded-xl border border-line bg-white shadow-sm">
            <div class="flex items-center justify-between gap-3 border-b border-line px-4 py-3">
                <div>
                    <h2 class="text-sm font-semibold text-ink">Backup &amp; restore</h2>
                    <p class="mt-0.5 text-xs text-mute">Download or restore a full data backup</p>
                </div>
            </div>
            <div class="space-y-4 p-4"
                 x-data="{
                     fileName: '',
                     error: '',
                     confirming: false,
                     askRestore() {
                         this.error = '';
                         if (!this.fileName) { this.error = 'Choose a backup file first.'; return; }
                         this.confirming = true;
                     }
                 }">
                <form method="POST" action="{{ route('settings.backup.restore') }}"
                      enctype="multipart/form-data" x-ref="restoreForm">
                    @csrf
                    <div class="flex flex-wrap items-center gap-3">
                        <a href="{{ route('settings.backup.download') }}"
                           class="inline-flex h-8 items-center justify-center rounded-lg bg-ink px-3 text-xs font-medium text-white transition-colors hover:bg-ink/70">
                            Download backup
                        </a>
                        <input type="file" name="backup" accept=".json,application/json"
                               class="max-w-full text-xs text-mute"
                               @input="fileName = $event.target.files.length ? $event.target.files[0].name : ''; error = ''">
                        <button type="button" class="btn-secondary btn-sm h-8" @click="askRestore()">Restore backup</button>
                    </div>
                </form>

                <p class="text-xs text-mute">
                    Backup downloads a JSON file with all shops, devices, transactions, and
                    adjustments. Restoring <span class="font-medium">replaces everything</span>
                    with the selected backup file. Staff login accounts are kept where the
                    account still exists.
                </p>

                <div x-show="error !== ''" x-cloak
                     class="rounded-lg border border-lowstock bg-lowstock-tint px-3 py-2 text-sm text-lowstock" x-text="error"></div>

                <template x-if="confirming">
                    <div class="fixed inset-0 z-50 flex items-end justify-center sm:items-center sm:p-4">
                        <div class="absolute inset-0 bg-ink/50" @click="confirming = false"></div>
                        <div class="relative z-10 w-full max-w-md rounded-t-2xl bg-white p-5 shadow-xl sm:rounded-2xl">
                            <h3 class="text-base font-semibold text-ink">Restore this backup?</h3>
                            <p class="mt-2 text-sm text-mute">
                                This REPLACES all current shops, devices, transactions, and adjustments with the backup. This cannot be undone.
                            </p>
                            <div class="mt-4 flex justify-end gap-2">
                                <button type="button" class="btn-secondary" @click="confirming = false">Cancel</button>
                                <button type="button" class="btn-danger" @click="confirming = false; $refs.restoreForm.submit()">
                                    Replace everything
                                </button>
                            </div>
                        </div>
                    </div>
                </template>
            </div>
        </section>
    </div>
@endsection
