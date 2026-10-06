@if (session('success'))
    <div x-data="{ show: true }" x-init="setTimeout(() => show = false, 4000)" x-show="show"
         class="fixed left-1/2 top-4 z-50 w-[calc(100%-2rem)] max-w-md -translate-x-1/2 rounded-lg border border-instock/30 bg-instock-tint px-4 py-3 text-sm font-medium text-instock shadow-lg">
        {{ session('success') }}
    </div>
@endif

@php
    $actionErrors = collect($errors->any() ? $errors->get('action') : []);
    $otherErrors = collect($errors->all())->reject(fn ($e) => in_array($e, $actionErrors->all(), true));
    $messages = $actionErrors->merge($otherErrors)->values();
@endphp

@if ($messages->isNotEmpty())
    <div x-data="{ show: true }" x-init="setTimeout(() => show = false, 8000)" x-show="show"
         class="fixed left-1/2 top-4 z-50 w-[calc(100%-2rem)] max-w-md -translate-x-1/2 space-y-1 rounded-lg border border-lowstock/30 bg-lowstock-tint px-4 py-3 text-sm font-medium text-lowstock shadow-lg">
        @foreach ($messages as $message)
            <p>{{ $message }}</p>
        @endforeach
    </div>
@endif

@if (session('warnings'))
    <div x-data="{ show: true }" x-init="setTimeout(() => show = false, 9000)" x-show="show"
         class="fixed left-1/2 top-4 z-50 w-[calc(100%-2rem)] max-w-md -translate-x-1/2 space-y-1 rounded-lg border border-warnstock/30 bg-warnstock-tint px-4 py-3 text-sm font-medium text-warnstock shadow-lg">
        @foreach (session('warnings') as $warning)
            <p>{{ $warning }}</p>
        @endforeach
    </div>
@endif
