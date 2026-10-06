@extends('layouts.guest')

@section('title', 'Sign in — Mr Jeff Stock')

@section('content')
    <div class="rounded-2xl bg-white p-8 shadow-xl">
        <div class="mb-6 flex items-center gap-3">
            <span class="flex h-10 w-10 items-center justify-center rounded-lg bg-brand text-sm font-bold text-white">MJ</span>
            <div>
                <h1 class="text-lg font-bold tracking-tight">Mr Jeff Stock</h1>
                <p class="text-sm text-mute">Sign in to continue</p>
            </div>
        </div>

        <form method="POST" action="{{ route('login') }}" class="space-y-4">
            @csrf
            <input type="hidden" name="next" value="{{ $next }}">

            <div>
                <label for="email" class="mb-1 block text-sm font-medium">Email</label>
                <input id="email" name="email" type="email" autocomplete="email" required
                       value="{{ old('email') }}"
                       class="w-full rounded-lg border border-line bg-paper px-3 py-2.5 text-sm outline-none focus:border-brand focus:bg-white">
            </div>

            <div>
                <label for="password" class="mb-1 block text-sm font-medium">Password</label>
                <input id="password" name="password" type="password" autocomplete="current-password" required
                       class="w-full rounded-lg border border-line bg-paper px-3 py-2.5 text-sm outline-none focus:border-brand focus:bg-white">
            </div>

            <button type="submit"
                    class="w-full rounded-lg bg-brand px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-brand-deep">
                Sign in
            </button>
        </form>

        <p class="mt-6 text-center text-xs text-mute">
            Accounts are created by the owner. Ask them to add you.
        </p>
    </div>
@endsection
