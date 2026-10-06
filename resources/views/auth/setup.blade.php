@extends('layouts.guest')

@section('title', 'Set up owner — Mr Jeff Stock')

@section('content')
    <div class="rounded-2xl bg-white p-8 shadow-xl">
        <div class="mb-6 flex items-center gap-3">
            <span class="flex h-10 w-10 items-center justify-center rounded-lg bg-brand text-sm font-bold text-white">MJ</span>
            <div>
                <h1 class="text-lg font-bold tracking-tight">Create the owner account</h1>
                <p class="text-sm text-mute">One-time setup. Requires the setup secret.</p>
            </div>
        </div>

        <form method="POST" action="{{ route('setup') }}" class="space-y-4">
            @csrf

            <div>
                <label for="name" class="mb-1 block text-sm font-medium">Your name</label>
                <input id="name" name="name" type="text" autocomplete="name" required
                       value="{{ old('name') }}"
                       class="w-full rounded-lg border border-line bg-paper px-3 py-2.5 text-sm outline-none focus:border-brand focus:bg-white">
            </div>

            <div>
                <label for="email" class="mb-1 block text-sm font-medium">Email</label>
                <input id="email" name="email" type="email" autocomplete="email" required
                       value="{{ old('email') }}"
                       class="w-full rounded-lg border border-line bg-paper px-3 py-2.5 text-sm outline-none focus:border-brand focus:bg-white">
            </div>

            <div>
                <label for="password" class="mb-1 block text-sm font-medium">Password</label>
                <input id="password" name="password" type="password" autocomplete="new-password" minlength="8" required
                       class="w-full rounded-lg border border-line bg-paper px-3 py-2.5 text-sm outline-none focus:border-brand focus:bg-white">
                <p class="mt-1 text-xs text-mute">At least 8 characters.</p>
            </div>

            <div>
                <label for="secret" class="mb-1 block text-sm font-medium">Setup secret</label>
                <input id="secret" name="secret" type="password" required
                       class="w-full rounded-lg border border-line bg-paper px-3 py-2.5 text-sm outline-none focus:border-brand focus:bg-white">
            </div>

            <button type="submit"
                    class="w-full rounded-lg bg-brand px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-brand-deep">
                Create owner account
            </button>
        </form>
    </div>
@endsection
