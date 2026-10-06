<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#f4f5fa">
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    <title>@yield('title', 'Mr Jeff Stock')</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="relative flex min-h-screen items-center justify-center overflow-hidden bg-paper px-4 py-10 text-ink">
    <div class="pointer-events-none absolute -left-32 -top-32 h-96 w-96 rounded-full bg-brand/15 blur-3xl" aria-hidden="true"></div>
    <div class="pointer-events-none absolute -bottom-40 -right-24 h-[28rem] w-[28rem] rounded-full bg-brand/10 blur-3xl" aria-hidden="true"></div>
    <div class="relative w-full max-w-3xl">
        @include('partials.flash')
        @yield('content')
    </div>
    @stack('scripts')
</body>
</html>
