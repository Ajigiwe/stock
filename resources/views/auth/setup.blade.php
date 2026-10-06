@extends('layouts.guest')

@section('title', 'Set up owner — Mr Jeff Stock')

@section('content')
    <div class="grid overflow-hidden rounded-3xl border border-line bg-white shadow-2xl sm:grid-cols-[minmax(0,5fr)_minmax(0,6fr)]">
        <div class="flex flex-col justify-between gap-8 bg-ink p-7 text-white sm:p-8">
            <div class="flex items-center gap-3">
                <span class="flex h-10 w-10 items-center justify-center overflow-hidden rounded-xl border border-line bg-white">
                    <img src="/icon-192.png" alt="Mr Jeff Stock logo" class="h-full w-full object-cover">
                </span>
                <div>
                    <p class="font-bold tracking-tight">Mr Jeff Stock</p>
                    <p class="text-xs text-white/60">Phone stock control</p>
                </div>
            </div>
            <div class="space-y-4">
                <p class="text-xl font-extrabold leading-snug tracking-tight">One shop, one owner.</p>
                <ul class="space-y-2.5 text-[13px] text-white/75">
                    <li class="flex items-center gap-2.5">
                        <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-white/10">
                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5" /></svg>
                        </span>
                        Creates the owner account
                    </li>
                    <li class="flex items-center gap-2.5">
                        <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-white/10">
                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5" /></svg>
                        </span>
                        Guarded by your setup secret
                    </li>
                    <li class="flex items-center gap-2.5">
                        <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-white/10">
                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5" /></svg>
                        </span>
                        Staff join afterwards
                    </li>
                </ul>
            </div>
            <p class="text-[11px] text-white/40">One-time setup — this page disappears once the owner exists.</p>
        </div>

        <div class="p-7 sm:p-8">
            <h1 class="text-xl font-extrabold tracking-tight">Create the owner account</h1>
            <p class="mt-1 text-sm text-mute">One-time setup. Requires the setup secret.</p>

            <form method="POST" action="{{ route('setup') }}" class="mt-6 space-y-4">
                @csrf

                <div>
                    <label for="name" class="mb-1 block text-sm font-medium">Your name</label>
                    <input id="name" name="name" type="text" autocomplete="name" required autofocus
                           value="{{ old('name') }}"
                           class="w-full rounded-xl border border-line bg-paper px-3 py-2.5 text-sm outline-none transition focus:border-brand focus:bg-white focus:ring-2 focus:ring-brand/15">
                </div>

                <div>
                    <label for="email" class="mb-1 block text-sm font-medium">Email</label>
                    <input id="email" name="email" type="email" autocomplete="email" required
                           value="{{ old('email') }}"
                           class="w-full rounded-xl border border-line bg-paper px-3 py-2.5 text-sm outline-none transition focus:border-brand focus:bg-white focus:ring-2 focus:ring-brand/15">
                </div>

                <div x-data="{ show: false }">
                    <label for="password" class="mb-1 block text-sm font-medium">Password</label>
                    <div class="relative">
                        <input id="password" name="password" autocomplete="new-password" minlength="8" required
                               :type="show ? 'text' : 'password'"
                               class="w-full rounded-xl border border-line bg-paper px-3 py-2.5 pr-14 text-sm outline-none transition focus:border-brand focus:bg-white focus:ring-2 focus:ring-brand/15">
                        <button type="button" @click="show = !show" x-text="show ? 'Hide' : 'Show'"
                                class="absolute right-3 top-1/2 -translate-y-1/2 text-xs font-bold text-mute transition-colors hover:text-ink"></button>
                    </div>
                    <p class="mt-1 text-xs text-mute">At least 8 characters.</p>
                </div>

                <div>
                    <label for="secret" class="mb-1 block text-sm font-medium">Setup secret</label>
                    <input id="secret" name="secret" type="password" required
                           class="w-full rounded-xl border border-line bg-paper px-3 py-2.5 text-sm outline-none transition focus:border-brand focus:bg-white focus:ring-2 focus:ring-brand/15">
                </div>

                <button type="submit"
                        class="h-11 w-full rounded-xl bg-brand px-4 text-sm font-bold text-white shadow-[0_6px_16px_rgba(67,56,202,0.35)] transition hover:bg-brand-deep active:translate-y-px">
                    Create owner account
                </button>
            </form>
        </div>
    </div>
@endsection
