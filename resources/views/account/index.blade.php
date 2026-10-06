@extends('layouts.app')

@section('title', 'Account')
@section('mobileTitle', 'Account')

@php
    $isOwner = $user->isOwner();
    $shopName = $user->shop_id !== null ? ($user->shop?->name ?? null) : null;
    $showShop = ! $isOwner || $user->shop_id !== null;
@endphp

@section('content')
    <div class="space-y-6">
        <div>
            <h1 class="text-xl font-bold text-ink">Account</h1>
            <p class="text-sm text-mute">Your profile and password</p>
        </div>

        <x-dash-card title="Your details">
            <dl class="grid gap-x-6 gap-y-3 sm:grid-cols-2">
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-mute">Name</dt>
                    <dd class="mt-0.5 text-sm font-medium text-ink">{{ $user->name ?: '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-mute">Email</dt>
                    <dd class="mt-0.5 text-sm text-ink">{{ $user->email ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-mute">Role</dt>
                    <dd class="mt-0.5">
                        <span class="{{ $isOwner ? 'badge badge-brand' : 'badge badge-muted' }}">
                            {{ $isOwner ? 'Owner' : 'Attendant' }}
                        </span>
                    </dd>
                </div>
                @if ($showShop)
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-mute">Shop</dt>
                        <dd class="mt-0.5 text-sm text-ink">{{ $shopName ?? '—' }}</dd>
                    </div>
                @endif
            </dl>
        </x-dash-card>

        <x-dash-card title="Change password" subtitle="Use at least 6 characters">
            <form method="POST" action="{{ route('account.password') }}" class="space-y-3">
                @csrf
                <div>
                    <label class="label" for="current-password">Current password</label>
                    <input id="current-password" name="currentPassword" type="password" required
                           autocomplete="current-password" placeholder="your current password" class="input">
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label class="label" for="new-password">New password</label>
                        <input id="new-password" name="password" type="password" required minlength="8"
                               autocomplete="new-password" placeholder="min 8 characters" class="input">
                    </div>
                    <div>
                        <label class="label" for="confirm-password">Confirm new password</label>
                        <input id="confirm-password" name="confirm" type="password" required minlength="8"
                               autocomplete="new-password" placeholder="re-enter password" class="input">
                    </div>
                </div>
                <button type="submit" class="btn btn-primary h-9 text-sm">Update password</button>
            </form>
        </x-dash-card>
    </div>
@endsection
