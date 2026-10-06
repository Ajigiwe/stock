@props(['title' => null, 'subtitle' => null])

{{-- Shared card shell — port of the Card primitive in src/components/ui.tsx --}}
<div {{ $attributes->class(['rounded-xl border border-line bg-white shadow-sm']) }}>
    @if ($title !== null || isset($actions))
        <div class="flex items-center justify-between gap-3 border-b border-line px-4 py-3">
            <div class="min-w-0">
                @if ($title !== null)
                    <h2 class="text-sm font-semibold text-ink">{{ $title }}</h2>
                @endif
                @if ($subtitle !== null)
                    <p class="mt-0.5 text-xs text-mute">{{ $subtitle }}</p>
                @endif
            </div>
            @isset($actions)
                <div class="shrink-0">{{ $actions }}</div>
            @endisset
        </div>
    @endif
    <div class="p-4">{{ $slot }}</div>
</div>
