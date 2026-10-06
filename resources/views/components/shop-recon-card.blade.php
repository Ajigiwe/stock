@props(['reconRow'])

{{-- One recon row as a card — the mobile rendering of the end-of-day table. --}}
@php
    $reconNotes = [];
    if ($reconRow['trade_in']) {
        $reconNotes[] = '+'.$reconRow['trade_in'].' trade-in';
    }
    if ($reconRow['restocked']) {
        $reconNotes[] = '+'.$reconRow['restocked'].' restocked';
    }
    if ($reconRow['removed']) {
        $reconNotes[] = '−'.$reconRow['removed'].' removed';
    }
@endphp

<div class="rounded-lg border border-line bg-paper px-3 py-2">
    <div class="flex items-center justify-between gap-2">
        <div class="flex flex-wrap items-center gap-2">
            <span class="truncate text-sm font-medium text-ink">{{ $reconRow['model_name'] }}</span>
            <span class="{{ $reconRow['condition'] === 'new' ? 'badge-brand' : 'badge-muted' }}">{{ $reconRow['condition'] }}</span>
        </div>
        @if ($reconRow['counted'] !== null)
            @if ($reconRow['variance'] === 0)
                <span class="badge-ok">match</span>
            @else
                <span class="badge-danger">{{ $reconRow['variance'] > 0 ? '+'.$reconRow['variance'] : $reconRow['variance'] }}</span>
            @endif
        @endif
    </div>

    @if (count($reconNotes) > 0)
        <div class="mt-0.5 text-xs text-mute">{{ implode(' · ', $reconNotes) }}</div>
    @endif

    <div class="mt-1 grid grid-cols-3 gap-2 text-xs text-mute">
        <span>Morning <b class="text-ink">{{ $reconRow['opening'] }}</b></span>
        <span>Bought <b class="text-ink">{{ $reconRow['sold'] }}{{ $reconRow['pending'] > 0 ? ' +'.$reconRow['pending'] : '' }}</b></span>
        <span>Left <b class="text-ink">{{ $reconRow['closing'] }}</b></span>
    </div>
</div>
