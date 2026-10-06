@props(['title' => '', 'subtitle' => ''])

{{-- Card shell — port of <Card> in src/components/ui.tsx: optional title bar with
     an actions cluster on the right, padded body below. --}}
<div {{ $attributes->merge(['class' => 'rounded-xl border border-line bg-white shadow-sm']) }}>
    @if ($title !== '' || $subtitle !== '' || isset($actions))
        <div class="flex items-center justify-between gap-3 border-b border-line px-4 py-3">
            <div>
                @if ($title !== '')
                    <h2 class="text-sm font-semibold text-ink">{{ $title }}</h2>
                @endif
                @if ($subtitle !== '')
                    <p class="mt-0.5 text-xs text-mute">{{ $subtitle }}</p>
                @endif
            </div>
            @isset($actions)
                <div class="flex shrink-0 items-center gap-2">{{ $actions }}</div>
            @endisset
        </div>
    @endif
    <div class="p-4">{{ $slot }}</div>
</div>
