@extends('layouts.guest')

@section('title', 'Sign in — Mr Jeff Stock')

@section('content')
    <div class="grid overflow-hidden rounded-3xl border border-line bg-white shadow-2xl sm:grid-cols-[minmax(0,5fr)_minmax(0,6fr)]">
        <div class="flex flex-col justify-between gap-8 bg-ink p-7 text-white sm:p-8">
            <div class="flex items-center gap-3">
                <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-brand text-sm font-bold text-white">MJ</span>
                <div>
                    <p class="font-bold tracking-tight">Mr Jeff Stock</p>
                    <p class="text-xs text-white/60">Phone stock control</p>
                </div>
            </div>
            <div class="space-y-4">
                <p class="text-xl font-extrabold leading-snug tracking-tight">Every phone accounted for.</p>
                <ul class="space-y-2.5 text-[13px] text-white/75">
                    <li class="flex items-center gap-2.5">
                        <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-white/10">
                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5" /></svg>
                        </span>
                        Record sales, swaps &amp; repairs
                    </li>
                    <li class="flex items-center gap-2.5">
                        <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-white/10">
                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5" /></svg>
                        </span>
                        Works offline on the shop floor
                    </li>
                    <li class="flex items-center gap-2.5">
                        <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-white/10">
                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5" /></svg>
                        </span>
                        Owner reports &amp; stock counts
                    </li>
                </ul>
            </div>
            <p class="text-[11px] text-white/40">Sign in to open your shop&rsquo;s terminal.</p>
        </div>

        <div class="p-7 sm:p-8">
            <h1 class="text-xl font-extrabold tracking-tight">Welcome back</h1>
            <p class="mt-1 text-sm text-mute">Sign in to continue.</p>

            <form method="POST" action="{{ route('login') }}" class="mt-6 space-y-4">
                @csrf
                <input type="hidden" name="next" value="{{ $next }}">

                <div>
                    <label for="email" class="mb-1 block text-sm font-medium">Email or phone number</label>
                    <input id="email" name="email" type="text" autocomplete="username" required autofocus
                           placeholder="you@example.com or 024 123 4567"
                           value="{{ old('email') }}"
                           class="w-full rounded-xl border border-line bg-paper px-3 py-2.5 text-sm outline-none transition focus:border-brand focus:bg-white focus:ring-2 focus:ring-brand/15">
                </div>

                <div x-data="{ show: false }">
                    <label for="password" class="mb-1 block text-sm font-medium">Password</label>
                    <div class="relative">
                        <input id="password" name="password" autocomplete="current-password" required enterkeyhint="go"
                               :type="show ? 'text' : 'password'"
                               class="w-full rounded-xl border border-line bg-paper px-3 py-2.5 pr-14 text-sm outline-none transition focus:border-brand focus:bg-white focus:ring-2 focus:ring-brand/15">
                        <button type="button" @click="show = !show" x-text="show ? 'Hide' : 'Show'"
                                class="absolute right-3 top-1/2 -translate-y-1/2 text-xs font-bold text-mute transition-colors hover:text-ink"></button>
                    </div>
                </div>

                <button type="submit"
                        class="h-11 w-full rounded-xl bg-brand px-4 text-sm font-bold text-white shadow-[0_6px_16px_rgba(67,56,202,0.35)] transition hover:bg-brand-deep active:translate-y-px">
                    Sign in
                </button>
            </form>

            <p class="mt-6 text-center text-xs text-mute">
                Accounts are created by the owner. Ask them to add you.
            </p>
        </div>
    </div>
@endsection
