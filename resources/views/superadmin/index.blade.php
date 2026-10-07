@extends('layouts.app')

@section('title', 'Superadmin — Mr Jeff Stock')
@section('mobileTitle', 'Superadmin')

@section('content')
    <div class="space-y-6">
        <div>
            <h1 class="text-xl font-bold text-ink">Superadmin</h1>
            <p class="text-sm text-mute">Every shop, every account, every pending decision — one screen.</p>
        </div>

        {{-- Money overview --}}
        <section class="rounded-xl border border-line bg-white shadow-sm">
            <div class="border-b border-line px-4 py-3">
                <h2 class="text-sm font-semibold text-ink">Money overview</h2>
                <p class="mt-0.5 text-xs text-mute">Completed sales across all shops</p>
            </div>
            <div class="grid grid-cols-2 gap-3 p-4 lg:grid-cols-4">
                <div class="rounded-lg bg-paper p-3">
                    <div class="text-[11px] font-semibold uppercase tracking-wide text-mute">Today</div>
                    <div class="tnum mt-1 text-lg font-bold text-ink">{{ \App\Support\Format::money($money['today_revenue']) }}</div>
                    <div class="text-xs text-mute">{{ $money['today_txs'] }} sales</div>
                </div>
                <div class="rounded-lg bg-paper p-3">
                    <div class="text-[11px] font-semibold uppercase tracking-wide text-mute">Last 7 days</div>
                    <div class="tnum mt-1 text-lg font-bold text-ink">{{ \App\Support\Format::money($money['week_revenue']) }}</div>
                </div>
                <div class="rounded-lg bg-paper p-3">
                    <div class="text-[11px] font-semibold uppercase tracking-wide text-mute">Awaiting review</div>
                    <div class="tnum mt-1 text-lg font-bold text-warnstock">{{ \App\Support\Format::money($money['pending_revenue']) }}</div>
                    <div class="text-xs text-mute">{{ $money['pending_txs'] }} sales</div>
                </div>
                <div class="rounded-lg bg-paper p-3">
                    <div class="text-[11px] font-semibold uppercase tracking-wide text-mute">Today by method</div>
                    @forelse ($money['methods'] as $method => $total)
                        <div class="tnum text-xs text-ink">{{ str_replace('_', ' ', $method) }}: {{ \App\Support\Format::money((float) $total) }}</div>
                    @empty
                        <div class="text-xs text-mute">No sales yet today.</div>
                    @endforelse
                </div>
            </div>
            <div class="overflow-x-auto px-4 pb-4">
                <table class="table-base min-w-[560px]">
                    <thead>
                        <tr>
                            <th scope="col">Shop</th>
                            <th scope="col" class="text-right">Today</th>
                            <th scope="col" class="text-right">7 days</th>
                            <th scope="col"><span class="sr-only">Open</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($money['perShop'] as $shop)
                            <tr>
                                <td class="font-medium text-ink">{{ $shop['name'] }}</td>
                                <td class="tnum text-right">{{ \App\Support\Format::money($shop['today_revenue']) }} <span class="text-mute">({{ $shop['today_txs'] }})</span></td>
                                <td class="tnum text-right">{{ \App\Support\Format::money($shop['week_revenue']) }} <span class="text-mute">({{ $shop['week_txs'] }})</span></td>
                                <td class="text-right">
                                    <a href="{{ route('shop.show', ['shop' => $shop['id']]) }}" class="text-xs font-medium text-brand underline">Open shop</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        {{-- Approvals inbox --}}
        <section class="rounded-xl border border-line bg-white shadow-sm">
            <div class="border-b border-line px-4 py-3">
                <h2 class="text-sm font-semibold text-ink">Approvals inbox</h2>
                <p class="mt-0.5 text-xs text-mute">{{ $approvals['request_count'] }} stock requests · {{ $approvals['submitted_count'] }} counts · {{ $money['pending_txs'] }} sales awaiting review</p>
            </div>
            <div class="space-y-2 p-4">
                @forelse ($approvals['requests'] as $stockRequest)
                    <div class="flex items-start justify-between gap-3 rounded-lg border border-line bg-paper p-3">
                        <div class="min-w-0 text-sm">
                            <span class="font-medium text-ink">
                                {{ $stockRequest['type'] === 'create_model' ? 'New model: '.($stockRequest['model_name_display'] ?? '—') : 'Stock change' }}
                            </span>
                            <span class="badge-muted ml-2">{{ $stockRequest['shop_name'] ?? '' }}</span>
                            <div class="mt-0.5 text-xs text-mute">
                                {{ $stockRequest['staff_name'] ?? 'Staff' }} · {{ \App\Support\Format::dateTime($stockRequest['created_at']) }}
                            </div>
                        </div>
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
                    </div>
                @empty
                    <p class="text-xs text-mute">No pending stock requests.</p>
                @endforelse

                @foreach ($approvals['submitted_counts'] as $count)
                    <div class="flex items-center justify-between gap-3 rounded-lg border border-line bg-paper p-3 text-sm">
                        <span class="text-ink">Stock count · <span class="font-medium">{{ $count['shop_name'] }}</span> <span class="text-mute">by {{ $count['submitted_by'] }}</span></span>
                        <a href="{{ route('shop.show', ['shop' => $count['shop_id']]) }}" class="text-xs font-medium text-brand underline">Review</a>
                    </div>
                @endforeach

                @foreach ($approvals['reviews'] as $tx)
                    <div class="flex items-center justify-between gap-3 rounded-lg border border-line bg-paper p-3 text-sm">
                        <span class="text-ink">Below-list sale · <span class="font-medium">{{ $tx['shop_name'] }}</span> <span class="tnum">{{ \App\Support\Format::money($tx['amount']) }}</span></span>
                        <a href="{{ route('transactions.show', ['transaction' => $tx['id']]) }}" class="text-xs font-medium text-brand underline">Review</a>
                    </div>
                @endforeach
            </div>
        </section>

        {{-- Stock alerts --}}
        <section class="rounded-xl border border-line bg-white shadow-sm">
            <div class="border-b border-line px-4 py-3">
                <h2 class="text-sm font-semibold text-ink">Stock alerts</h2>
                <p class="mt-0.5 text-xs text-mute">{{ $stock['low_count'] }} running low · {{ $stock['out_count'] }} out of stock · {{ $stock['model_count'] }} models</p>
            </div>
            <div class="space-y-2 p-4">
                @forelse ($stock['low'] as $model)
                    <div class="flex items-center justify-between gap-3 rounded-lg border border-line bg-paper p-3 text-sm">
                        <span class="min-w-0 truncate text-ink">
                            <span class="font-medium">{{ $model['model_name'] }}</span>
                            @if ($model['sim_type'] !== '')
                                <span class="badge-muted ml-1">{{ $simTypes[$model['sim_type']] ?? $model['sim_type'] }}</span>
                            @endif
                            @if ($model['color'] !== '')
                                <span class="ml-1 text-xs text-mute">{{ $model['color'] }}</span>
                            @endif
                            <span class="ml-1 text-xs text-mute">{{ $model['shop_name'] }}</span>
                        </span>
                        <span class="tnum shrink-0 font-bold {{ $model['available'] === 0 ? 'text-lowstock' : 'text-ink' }}">{{ $model['available'] }} left</span>
                    </div>
                @empty
                    <p class="text-xs text-mute">Nothing running low anywhere.</p>
                @endforelse
            </div>
        </section>

        {{-- Staff oversight --}}
        <section class="rounded-xl border border-line bg-white shadow-sm">
            <div class="border-b border-line px-4 py-3">
                <h2 class="text-sm font-semibold text-ink">Staff oversight</h2>
                <p class="mt-0.5 text-xs text-mute">{{ $staff['owners'] }} owners · {{ $staff['attendants'] }} attendants · {{ $staff['deactivated'] }} deactivated</p>
            </div>
            <div class="space-y-4 p-4">
                <form method="POST" action="{{ route('superadmin.owners.store') }}"
                      class="grid gap-3 rounded-lg border border-line bg-paper p-4 sm:grid-cols-2">
                    @csrf
                    <div class="sm:col-span-2">
                        <span class="text-xs font-semibold text-ink">Add owner</span>
                    </div>
                    <div>
                        <label class="label" for="ownerName">Full name</label>
                        <input id="ownerName" name="name" type="text" class="input" autocomplete="off">
                    </div>
                    <div>
                        <label class="label" for="ownerEmail">Email (optional)</label>
                        <input id="ownerEmail" name="email" type="email" class="input" autocomplete="off">
                    </div>
                    <div>
                        <label class="label" for="ownerPhone">Phone number (optional)</label>
                        <input id="ownerPhone" name="phone" type="tel" class="input" autocomplete="off">
                    </div>
                    <div>
                        <label class="label" for="ownerPassword">Temporary password</label>
                        <input id="ownerPassword" name="password" type="password" class="input" autocomplete="new-password">
                    </div>
                    <div class="sm:col-span-2">
                        <button type="submit" class="btn-primary btn-sm h-8">Create owner</button>
                    </div>
                </form>

                <div class="overflow-x-auto rounded-lg border border-line">
                    <table class="table-base min-w-[720px]">
                        <thead>
                            <tr>
                                <th scope="col">Account</th>
                                <th scope="col">Role</th>
                                <th scope="col">Shop</th>
                                <th scope="col">Grants</th>
                                <th scope="col">Last sign-in</th>
                                <th scope="col"><span class="sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($staff['rows'] as $member)
                                <tr>
                                    <td>
                                        <div class="font-medium text-ink">{{ $member['name'] }}</div>
                                        <div class="text-xs text-mute">{{ collect([$member['email'], $member['phone']])->filter()->join(' · ') }}</div>
                                    </td>
                                    <td>
                                        <span class="{{ $member['role'] === 'superadmin' ? 'badge-brand' : ($member['role'] === 'owner' ? 'badge-muted' : '') }}">
                                            {{ $member['role'] }}
                                        </span>
                                        @if (! $member['active'])
                                            <span class="badge-danger">deactivated</span>
                                        @endif
                                    </td>
                                    <td class="text-mute">{{ $member['shop_name'] ?? '—' }}</td>
                                    <td class="text-xs text-mute">
                                        {{ collect(['perm_approve_requests' => 'Approve', 'perm_adjust_stock' => 'Adjust', 'perm_reconcile' => 'Reconcile'])->filter(fn ($label, $key) => $member[$key])->join(', ') ?: '—' }}
                                    </td>
                                    <td class="text-xs text-mute">{{ $member['last_login'] !== null ? \App\Support\Format::dateTime($member['last_login']) : 'never' }}</td>
                                    <td class="text-right">
                                        @if ($member['role'] !== 'superadmin')
                                            @if ($member['active'])
                                                <form method="POST" action="{{ route('superadmin.users.deactivate', $member['id']) }}" class="inline">
                                                    @csrf
                                                    <button type="submit" class="btn btn-danger btn-sm">Deactivate</button>
                                                </form>
                                            @else
                                                <form method="POST" action="{{ route('superadmin.users.reactivate', $member['id']) }}" class="inline">
                                                    @csrf
                                                    <button type="submit" class="btn btn-secondary btn-sm">Reactivate</button>
                                                </form>
                                            @endif
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        {{-- System & data --}}
        <section class="rounded-xl border border-line bg-white shadow-sm">
            <div class="border-b border-line px-4 py-3">
                <h2 class="text-sm font-semibold text-ink">System &amp; data</h2>
                <p class="mt-0.5 text-xs text-mute">Row counts and backup controls</p>
            </div>
            <div class="space-y-4 p-4">
                <div class="flex flex-wrap items-center gap-2">
                    <a href="{{ route('settings.backup.download') }}" class="btn-secondary btn-sm h-8">Download backup</a>
                    <a href="{{ route('settings.index') }}" class="btn-secondary btn-sm h-8">Restore, import &amp; wipe</a>
                    <a href="{{ route('logs.index') }}" class="btn-secondary btn-sm h-8">Audit logs</a>
                </div>
                <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                    @foreach ($system['tables'] as $table => $count)
                        <div class="rounded-lg bg-paper px-3 py-2">
                            <div class="truncate text-[11px] font-medium text-mute">{{ $table }}</div>
                            <div class="tnum text-base font-bold text-ink">{{ number_format($count) }}</div>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>
    </div>
@endsection
