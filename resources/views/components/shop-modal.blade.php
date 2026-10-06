@props(['title' => '', 'subtitle' => '', 'show' => 'open', 'onClose' => 'open = false', 'size' => 'lg'])

{{-- Modal shell — port of <Modal> in src/components/ui.tsx. The caller owns the
     Alpine state: `show` is the x-show expression and `onClose` the close action.
     `heading` / `sub` slots replace title/subtitle when they need markup. --}}
@php
    $widths = ['md' => 'max-w-md', 'lg' => 'max-w-lg', 'xl' => 'max-w-2xl'];
    $width = $widths[$size] ?? 'max-w-lg';
@endphp

<div x-cloak x-show="{{ $show }}" class="fixed inset-0 z-50 flex items-end justify-center sm:items-center sm:p-4">
    <div class="absolute inset-0 bg-ink/50" @click="{{ $onClose }}"></div>
    <div class="relative z-10 max-h-[92vh] w-full overflow-y-auto rounded-t-2xl bg-white p-5 shadow-xl sm:rounded-2xl {{ $width }}">
        <div class="mb-4 flex items-start justify-between gap-3">
            <div>
                <h2 class="text-base font-semibold text-ink">
                    @isset($heading){{ $heading }}@else{{ $title }}@endisset
                </h2>
                @if (isset($sub))
                    <p class="mt-0.5 text-sm text-mute">{{ $sub }}</p>
                @elseif ($subtitle !== '')
                    <p class="mt-0.5 text-sm text-mute">{{ $subtitle }}</p>
                @endif
            </div>
            <button type="button" @click="{{ $onClose }}" aria-label="Close"
                    class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-lg text-mute transition-colors hover:bg-paper hover:text-ink">
                <x-icon name="close" />
            </button>
        </div>
        {{ $slot }}
    </div>
</div>
