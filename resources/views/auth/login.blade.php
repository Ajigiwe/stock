@extends('layouts.guest')

@section('title', 'Sign in — Mr Jeff Stock')

@section('content')
    <div class="rounded-3xl border border-line bg-white p-5 shadow-2xl sm:p-8">
        <div class="mb-6 flex items-center gap-3">
            <span class="flex h-11 w-11 shrink-0 items-center justify-center overflow-hidden rounded-xl border border-line bg-white">
                <img src="/icon-192.png" alt="Mr Jeff Stock logo" class="h-full w-full object-cover">
            </span>
            <div>
                <h1 class="text-xl font-extrabold tracking-tight">Welcome back</h1>
                <p class="mt-0.5 text-sm text-mute">Sign in to continue.</p>
            </div>
        </div>

        <form method="POST" action="{{ route('login') }}" class="space-y-4">
            @csrf
            <input type="hidden" name="next" value="{{ $next }}">

            <div>
                <label for="email" class="mb-1 block text-[15px] font-medium">Email or phone number</label>
                <input id="email" name="email" type="text" autocomplete="username" required autofocus
                       placeholder="you@example.com or 024 123 4567"
                       value="{{ old('email') }}"
                       class="w-full rounded-xl border border-line bg-paper px-3.5 py-3 text-base outline-none transition focus:border-brand focus:bg-white focus:ring-2 focus:ring-brand/15">
            </div>

            <div x-data="{ show: false }">
                <label for="password" class="mb-1 block text-[15px] font-medium">Password</label>
                <div class="relative">
                    <input id="password" name="password" autocomplete="current-password" required enterkeyhint="go"
                           :type="show ? 'text' : 'password'"
                           class="w-full rounded-xl border border-line bg-paper px-3.5 py-3 pr-14 text-base outline-none transition focus:border-brand focus:bg-white focus:ring-2 focus:ring-brand/15">
                    <button type="button" @click="show = !show" x-text="show ? 'Hide' : 'Show'"
                            class="absolute right-3 top-1/2 -translate-y-1/2 text-xs font-bold text-mute transition-colors hover:text-ink"></button>
                </div>
            </div>

            <button type="submit"
                    class="h-12 w-full rounded-xl bg-brand px-4 text-[15px] font-bold text-white shadow-[0_6px_16px_rgba(67,56,202,0.35)] transition hover:bg-brand-deep active:translate-y-px">
                Sign in
            </button>
        </form>

        <p class="mt-6 text-center text-xs text-mute">
            Accounts are created by the owner. Ask them to add you.
        </p>
    </div>
@endsection
