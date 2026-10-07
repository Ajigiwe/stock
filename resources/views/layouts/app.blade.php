<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#14162b">
    <link rel="icon" href="/favicon.svg?v=3" type="image/svg+xml">
    <link rel="icon" href="/favicon.ico?v=3" sizes="any">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="Jeff Stock">
    <title>@yield('title', 'Mr Jeff Stock')</title>
    <link rel="manifest" href="/manifest.webmanifest">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-paper text-ink antialiased"
      x-data="{ menuOpen: false, collapsed: localStorage.getItem('sidebar-collapsed') === '1' }">

@include('partials.flash')
@include('partials.offline-banner')
@include('partials.install-prompt')

@if (is_array(session('impersonator')) && isset(session('impersonator')['name']))
    <div class="fixed inset-x-0 top-0 z-50 flex justify-center px-3 pt-2">
        <div class="flex w-full max-w-md items-center gap-2.5 rounded-xl border border-warnstock/40 bg-ink px-4 py-2.5 text-white shadow-lg">
            <span class="min-w-0 flex-1 truncate text-xs">Superadmin preview — you are seeing the app as <b>{{ $userName }}</b></span>
            <form method="POST" action="{{ route('impersonate.exit') }}" class="shrink-0">
                @csrf
                <button type="submit" class="rounded-lg bg-white/15 px-2.5 py-1 text-xs font-bold transition-colors hover:bg-white/25">Exit</button>
            </form>
        </div>
    </div>
@endif

@php
    // Nav structure — port of app-shell.tsx: a 4-tab mobile bar (Home,
    // Record, Devices|My shop, Reports) and a fuller desktop sidebar / mobile
    // menu sheet. Owners get Devices, Logs and Settings; attendants get "My
    // shop" instead.
    $path = request()->path();
    $active = fn (string $prefix) => $prefix === '/'
        ? $path === '/'
        : str_starts_with($path, $prefix);

    $mobileTabs = [
        ['url' => route('dashboard'), 'label' => 'Home', 'icon' => 'dashboard', 'active' => $active('/')],
        ['url' => route('transactions.create'), 'label' => 'Record', 'icon' => 'record', 'active' => $active('transactions/new')],
        $isOwner
            ? ['url' => route('devices.index'), 'label' => 'Devices', 'icon' => 'devices', 'active' => $active('devices')]
            : ($myShopId
                ? ['url' => route('shop.show', $myShopId), 'label' => 'My shop', 'icon' => 'shop', 'active' => $active('shops/'.$myShopId)]
                : ['url' => route('reports.index'), 'label' => 'Reports', 'icon' => 'reports', 'active' => $active('reports')]),
        ['url' => route('reports.index'), 'label' => 'Reports', 'icon' => 'reports', 'active' => $active('reports')],
    ];
    if ($isSuperAdmin ?? false) {
        // The till is closed to superadmins: their tab opens the dashboard.
        $mobileTabs[1] = ['url' => route('superadmin.index'), 'label' => 'Admin', 'icon' => 'superadmin', 'active' => $active('superadmin')];
    }

    $primaryNav = [
        ['url' => route('dashboard'), 'label' => 'Home', 'icon' => 'dashboard', 'active' => $active('/')],
        ['url' => route('transactions.create'), 'label' => 'Record', 'icon' => 'record', 'active' => $active('transactions')],
    ];
    if ($isSuperAdmin ?? false) {
        array_unshift(
            $primaryNav,
            ['url' => route('superadmin.index'), 'label' => 'Superadmin', 'icon' => 'superadmin', 'active' => $active('superadmin')]
        );
        // No till for superadmins: drop the Record entry they cannot use.
        $primaryNav = array_values(array_filter(
            $primaryNav,
            static fn (array $item): bool => $item['label'] !== 'Record'
        ));
    }
    if ($isOwner) {
        $primaryNav[] = ['url' => route('devices.index'), 'label' => 'Devices', 'icon' => 'devices', 'active' => $active('devices')];
    } elseif ($myShopId) {
        $primaryNav[] = ['url' => route('shop.show', $myShopId), 'label' => 'My shop', 'icon' => 'shop', 'active' => $active('shops/'.$myShopId)];
    }
    $primaryNav[] = ['url' => route('reports.index'), 'label' => 'Reports', 'icon' => 'reports', 'active' => $active('reports')];
    if ($isOwner) {
        // Owner tools: reachable on mobile via the menu sheet, but the
        // desktop sidebar never linked them — attendants must not see these.
        $primaryNav[] = ['url' => route('logs.index'), 'label' => 'Logs', 'icon' => 'logs', 'active' => $active('logs')];
        $primaryNav[] = ['url' => route('settings.index'), 'label' => 'Settings', 'icon' => 'settings', 'active' => $active('settings')];
    }
    $primaryNav[] = ['url' => route('account.index'), 'label' => 'Account', 'icon' => 'account', 'active' => $active('account')];

    $menuNav = [
        ['url' => route('dashboard'), 'label' => 'Dashboard', 'icon' => 'dashboard', 'active' => $active('/')],
        ['url' => route('transactions.create'), 'label' => 'Record transaction', 'icon' => 'record', 'active' => $active('transactions/new')],
        ['url' => route('reports.index'), 'label' => 'Reports', 'icon' => 'reports', 'active' => $active('reports')],
    ];
    if ($isSuperAdmin ?? false) {
        array_unshift(
            $menuNav,
            ['url' => route('superadmin.index'), 'label' => 'Superadmin', 'icon' => 'superadmin', 'active' => $active('superadmin')]
        );
        $menuNav = array_values(array_filter(
            $menuNav,
            static fn (array $item): bool => $item['label'] !== 'Record transaction'
        ));
    }
    if ($isOwner) {
        $menuNav[] = ['url' => route('devices.index'), 'label' => 'Devices', 'icon' => 'devices', 'active' => $active('devices')];
        $menuNav[] = ['url' => route('logs.index'), 'label' => 'Logs', 'icon' => 'logs', 'active' => $active('logs')];
        $menuNav[] = ['url' => route('settings.index'), 'label' => 'Settings', 'icon' => 'settings', 'active' => $active('settings')];
    } elseif ($myShopId) {
        $menuNav[] = ['url' => route('shop.show', $myShopId), 'label' => 'My shop', 'icon' => 'shop', 'active' => $active('shops/'.$myShopId)];
    }
    $menuNav[] = ['url' => route('account.index'), 'label' => 'Account', 'icon' => 'account', 'active' => $active('account')];

    // POS transaction types live in the sidebar, not on the page: each links
    // to the POS with ?type=, and the active one follows the query string
    // (falling back to the just-posted type after a failed submit).
    $txType = request()->query('type', old('type', 'sale'));
    if (! in_array($txType, ['sale', 'swap', 'repair'], true)) {
        $txType = 'sale';
    }
    $onPos = $active('transactions/new');
    $txTypes = [
        ['type' => 'sale', 'label' => 'Sale'],
        ['type' => 'swap', 'label' => 'Swap'],
        ['type' => 'repair', 'label' => 'Repair'],
    ];
@endphp

{{-- Desktop sidebar --}}
<aside class="fixed inset-y-0 left-0 z-30 hidden flex-col border-r border-line bg-white transition-[width] duration-150 lg:flex"
       :class="collapsed ? 'w-20' : 'w-64'">
    <div class="flex h-16 items-center gap-2 border-b border-line px-4">
        <span class="flex h-9 w-9 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-line bg-white">
            <img src="/icon-192.png" alt="Mr Jeff Stock logo" class="h-full w-full object-cover">
        </span>
        <span x-show="!collapsed" class="truncate font-bold tracking-tight">Mr Jeff Stock</span>
    </div>

    <nav class="flex-1 space-y-1 overflow-y-auto p-3">
        @foreach ($primaryNav as $item)
            <a href="{{ $item['url'] }}"
               @class([
                   'flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition',
                   'bg-brand-tint text-brand' => $item['active'],
                   'text-mute hover:bg-paper hover:text-ink' => !$item['active'],
               ])
               :title="collapsed ? '{{ $item['label'] }}' : null">
                <x-icon :name="$item['icon']" />
                <span x-show="!collapsed" class="truncate">{{ $item['label'] }}</span>
            </a>
            @if ($item['label'] === 'Record')
                <div x-show="!collapsed" class="mb-1 ml-11 space-y-0.5 border-l-2 border-line pl-2">
                    @foreach ($txTypes as $tx)
                        <a href="{{ route('transactions.create', ['type' => $tx['type']]) }}"
                           @class([
                               'block rounded-md px-2 py-1.5 text-[13px] font-medium transition',
                               'bg-brand-tint text-brand' => $onPos && $txType === $tx['type'],
                               'text-mute hover:bg-paper hover:text-ink' => !($onPos && $txType === $tx['type']),
                           ])>{{ $tx['label'] }}</a>
                    @endforeach
                </div>
            @endif
        @endforeach
    </nav>

    <div class="border-t border-line p-3">
        <div class="flex items-center gap-3 rounded-lg px-3 py-2">
            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-brand-tint text-xs font-bold text-brand">
                {{ strtoupper(substr($userName ?? '?', 0, 1)) }}
            </span>
            <span x-show="!collapsed" class="min-w-0 flex-1">
                <span class="block truncate text-sm font-medium">{{ $userName }}</span>
                <span class="block truncate text-xs text-mute">{{ $isOwner ? 'Owner' : 'Attendant' }}</span>
            </span>
        </div>
        <div class="mt-1 flex items-center gap-2">
            <button type="button" @click="collapsed = !collapsed; localStorage.setItem('sidebar-collapsed', collapsed ? '1' : '0')"
                    class="rounded-lg p-2 text-mute hover:bg-paper" title="Toggle sidebar">
                {{-- x-bind:class, not :class: on Blade components ":" is PHP, and Alpine's
                     `collapsed` state would be read as a bare PHP constant. --}}
                <x-icon name="chevron" x-bind:class="collapsed ? 'rotate-90' : '-rotate-90'" />
            </button>
            <form method="POST" action="{{ route('logout') }}" class="flex-1" x-show="!collapsed">
                @csrf
                <button type="submit"
                        class="flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium text-mute hover:bg-paper hover:text-ink">
                    <x-icon name="logout" /> Sign out
                </button>
            </form>
        </div>
    </div>
</aside>

<div class="min-h-screen transition-[padding] duration-150" :class="collapsed ? 'lg:pl-20' : 'lg:pl-64'">
    {{-- Mobile header --}}
    <header class="sticky top-0 z-20 flex h-14 items-center gap-3 bg-ink px-3 text-paper lg:hidden">
        <button type="button" @click="menuOpen = true" class="rounded-lg p-2 hover:bg-ink-soft" aria-label="Open menu">
            <x-icon name="menu" />
        </button>
        <h1 class="flex-1 truncate text-sm font-semibold">@yield('mobileTitle', 'Mr Jeff Stock')</h1>
        @if ($isOwner && $myShops->isNotEmpty())
            <div class="relative" x-data="{ open: false }">
                <button type="button" @click="open = !open"
                        class="flex items-center gap-1 rounded-lg bg-ink-soft px-3 py-1.5 text-xs font-medium">
                    <x-icon name="shop" :size="16" /> Shop <x-icon name="chevron" :size="14" />
                </button>
                <div x-show="open" @click.outside="open = false" x-cloak
                     class="absolute right-0 mt-2 w-56 rounded-lg border border-line bg-white py-1 text-ink shadow-lg">
                    <a href="{{ route('dashboard') }}" class="block px-4 py-2 text-sm hover:bg-paper">All shops</a>
                    @foreach ($myShops as $shop)
                        <a href="{{ route('shop.show', $shop) }}" class="block truncate px-4 py-2 text-sm hover:bg-paper">
                            {{ $shop->name }}
                        </a>
                    @endforeach
                </div>
            </div>
        @endif
    </header>

    {{-- Desktop top bar --}}
    <header class="hidden h-16 items-center justify-between border-b border-line bg-white px-8 lg:flex">
        <h1 class="text-lg font-bold tracking-tight">@yield('title', 'Mr Jeff Stock')</h1>
        <div class="flex items-center gap-3 text-sm text-mute">
            <span>{{ $userName }}</span>
            <span class="rounded-full bg-brand-tint px-2.5 py-1 text-xs font-semibold text-brand">
                {{ $isOwner ? 'Owner' : 'Attendant' }}
            </span>
        </div>
    </header>

    <main class="mx-auto w-full max-w-7xl px-4 pb-28 pt-5 lg:px-8 lg:pb-12 lg:pt-8">
        @yield('content')
    </main>
</div>

{{-- Mobile bottom tabs --}}
<nav class="fixed inset-x-0 bottom-0 z-30 grid grid-cols-4 border-t border-line bg-white pb-[env(safe-area-inset-bottom)] lg:hidden">
    @foreach ($mobileTabs as $tab)
        <a href="{{ $tab['url'] }}"
           @class([
               'flex flex-col items-center gap-1 py-2.5 text-[11px] font-medium',
               'text-brand' => $tab['active'],
               'text-mute' => !$tab['active'],
           ])>
            <x-icon :name="$tab['icon']" />
            {{ $tab['label'] }}
        </a>
    @endforeach
</nav>

{{-- Mobile menu sheet --}}
<div x-show="menuOpen" x-cloak class="fixed inset-0 z-40 lg:hidden">
    <div class="absolute inset-0 bg-ink/50" @click="menuOpen = false"></div>
    <div x-show="menuOpen"
         x-transition:enter="transition duration-150" x-transition:enter-start="-translate-x-full" x-transition:enter-end="translate-x-0"
         x-transition:leave="transition duration-150" x-transition:leave-start="translate-x-0" x-transition:leave-end="-translate-x-full"
         class="absolute inset-y-0 left-0 flex w-72 max-w-[85%] flex-col bg-white shadow-xl">
        <div class="flex h-14 items-center justify-between border-b border-line px-4">
            <span class="font-bold tracking-tight">Mr Jeff Stock</span>
            <button type="button" @click="menuOpen = false" class="rounded-lg p-2 text-mute hover:bg-paper" aria-label="Close menu">
                <x-icon name="close" />
            </button>
        </div>
        <nav class="flex-1 space-y-1 overflow-y-auto p-3">
            @foreach ($menuNav as $item)
                <a href="{{ $item['url'] }}"
                   @class([
                       'flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium',
                       'bg-brand-tint text-brand' => $item['active'],
                       'text-mute hover:bg-paper hover:text-ink' => !$item['active'],
                   ])>
                    <x-icon :name="$item['icon']" /> {{ $item['label'] }}
                </a>
                @if ($item['label'] === 'Record transaction')
                    <div class="mb-1 ml-11 space-y-0.5 border-l-2 border-line pl-2">
                        @foreach ($txTypes as $tx)
                            <a href="{{ route('transactions.create', ['type' => $tx['type']]) }}"
                               @class([
                                   'block rounded-md px-2 py-1.5 text-sm font-medium',
                                   'bg-brand-tint text-brand' => $onPos && $txType === $tx['type'],
                                   'text-mute hover:bg-paper hover:text-ink' => !($onPos && $txType === $tx['type']),
                               ])>{{ $tx['label'] }}</a>
                        @endforeach
                    </div>
                @endif
            @endforeach
        </nav>
        <form method="POST" action="{{ route('logout') }}" class="border-t border-line p-3">
            @csrf
            <button type="submit"
                    class="flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium text-mute hover:bg-paper">
                <x-icon name="logout" /> Sign out
            </button>
        </form>
    </div>
</div>

@stack('scripts')
</body>
</html>
