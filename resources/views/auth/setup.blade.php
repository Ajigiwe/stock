@extends('layouts.guest')

@section('title', 'Set up owner — Mr Jeff Stock')

@section('content')
    <div class="rounded-3xl border border-line bg-white p-5 shadow-2xl sm:p-8">
        <div class="mb-6 flex items-center gap-3">
            <span class="flex h-11 w-11 shrink-0 items-center justify-center overflow-hidden rounded-xl border border-line bg-white">
                <img src="/icon-192.png" alt="Mr Jeff Stock logo" class="h-full w-full object-cover">
            </span>
            <div>
                <h1 class="text-xl font-extrabold tracking-tight">Create the owner account</h1>
                <p class="mt-0.5 text-sm text-mute">One-time setup. Requires the setup secret.</p>
            </div>
        </div>

        <form method="POST" action="{{ route('setup') }}" class="space-y-4">
            @csrf

            <div>
                <label for="name" class="mb-1 block text-[15px] font-medium">Your name</label>
                <input id="name" name="name" type="text" autocomplete="name" required autofocus
                       value="{{ old('name') }}"
                       class="w-full rounded-xl border border-line bg-paper px-3.5 py-3 text-base outline-none transition focus:border-brand focus:bg-white focus:ring-2 focus:ring-brand/15">
            </div>

            <div>
                <label for="email" class="mb-1 block text-[15px] font-medium">Email</label>
                <input id="email" name="email" type="email" autocomplete="email" required
                       value="{{ old('email') }}"
                       class="w-full rounded-xl border border-line bg-paper px-3.5 py-3 text-base outline-none transition focus:border-brand focus:bg-white focus:ring-2 focus:ring-brand/15">
            </div>

            <div x-data="{ show: false }">
                <label for="password" class="mb-1 block text-[15px] font-medium">Password</label>
                <div class="relative">
                    <input id="password" name="password" autocomplete="new-password" minlength="8" required
                           :type="show ? 'text' : 'password'"
                           class="w-full rounded-xl border border-line bg-paper px-3.5 py-3 pr-14 text-base outline-none transition focus:border-brand focus:bg-white focus:ring-2 focus:ring-brand/15">
                    <button type="button" @click="show = !show" x-text="show ? 'Hide' : 'Show'"
                            class="absolute right-3 top-1/2 -translate-y-1/2 text-xs font-bold text-mute transition-colors hover:text-ink"></button>
                </div>
                <p class="mt-1 text-xs text-mute">At least 8 characters.</p>
            </div>

            <div>
                <label for="secret" class="mb-1 block text-[15px] font-medium">Setup secret</label>
                <input id="secret" name="secret" type="password" required
                       class="w-full rounded-xl border border-line bg-paper px-3.5 py-3 text-base outline-none transition focus:border-brand focus:bg-white focus:ring-2 focus:ring-brand/15">
            </div>

            <button type="submit"
                    class="h-12 w-full rounded-xl bg-brand px-4 text-[15px] font-bold text-white shadow-[0_6px_16px_rgba(67,56,202,0.35)] transition hover:bg-brand-deep active:translate-y-px">
                Create owner account
            </button>
        </form>
    </div>
@endsection
