@extends('layouts.app')

@section('title', 'Logs')
@section('mobileTitle', 'Logs')

@php
    use App\Support\Format;

    $loginLogs = $login_logs ?? [];
    $stockLogs = $stock_logs ?? [];

    // The controller may render this page for anyone who reaches /logs — an
    // attendant gets the notice below instead of the owner's audit trails.
    $actor = auth()->user();
    $isOwner = $is_owner ?? ($actor instanceof \App\Models\User && $actor->isAdmin());

    $actionLabels = [
        'create_model' => 'Added model',
        'update_model' => 'Edited product',
        'adjust_stock' => 'Adjusted stock',
        'bulk_create' => 'Bulk added models',
        'delete_transaction' => 'Deleted transaction',
    ];

    /** Human summary of a stock_logs details payload — port of stockDetails(). */
    $stockDetails = function (string $action, mixed $details): string {
        if (! is_array($details)) {
            return '';
        }

        if ($action === 'adjust_stock') {
            $delta = (int) ($details['delta'] ?? 0);
            $type = (string) ($details['type'] ?? '');
            $reason = (string) ($details['reason'] ?? '');

            return ($delta > 0 ? '+' : '').$delta.' ('.$type.')'.($reason !== '' ? ' · '.$reason : '');
        }

        if ($action === 'create_model') {
            return 'Opening stock '.(int) ($details['opening_stock'] ?? 0);
        }

        if ($action === 'bulk_create') {
            return '';
        }

        if ($action === 'delete_transaction') {
            $snapshot = $details['deleted_transaction'] ?? null;
            if (! is_array($snapshot) || ! is_array($snapshot['transaction'] ?? null)) {
                return '';
            }

            $tx = $snapshot['transaction'];
            $type = $tx['type'] ?? 'tx';
            $customer = $tx['customer_name'] ?? 'customer';
            $items = is_array($snapshot['items'] ?? null) ? count($snapshot['items']) : 0;

            return 'Deleted '.$type.' for '.$customer.' · '.Format::money($tx['amount'] ?? 0)
                .' · '.$items.' item'.($items === 1 ? '' : 's');
        }

        return '';
    };

    /** Badge class + label for a stock log row — port of the page's tone map. */
    $actionBadge = function (array $log) use ($actionLabels): array {
        $action = (string) ($log['action'] ?? '');
        $details = is_array($log['details'] ?? null) ? $log['details'] : [];
        $label = $actionLabels[$action] ?? $action;

        $class = match (true) {
            $action === 'adjust_stock' => ((int) ($details['delta'] ?? 0)) > 0 ? 'badge badge-ok' : 'badge badge-danger',
            $action === 'create_model' => 'badge badge-brand',
            $action === 'delete_transaction' => 'badge badge-danger',
            default => 'badge badge-muted',
        };

        return ['label' => $label, 'class' => $class];
    };

    $loginSubtitle = count($loginLogs).' recent login'.(count($loginLogs) === 1 ? '' : 's');
    $stockSubtitle = count($stockLogs).' recent change'.(count($stockLogs) === 1 ? '' : 's');
@endphp

@section('content')
    @if (! $isOwner)
        <div class="mx-auto max-w-md py-12 text-center">
            <span class="mx-auto flex h-11 w-11 items-center justify-center rounded-xl bg-warnstock-tint text-warnstock">
                <x-icon name="alert" />
            </span>
            <h1 class="mt-4 text-lg font-semibold text-ink">Only the owner can view logs</h1>
            <p class="mt-2 text-sm text-mute">
                Sign-in history and every stock edit are owner-only. Ask the owner
                if you need to review them.
            </p>
            <a href="{{ route('dashboard') }}" class="btn btn-secondary mt-5">Back to dashboard</a>
        </div>
    @else
        <div class="space-y-6">
            <div>
                <h1 class="text-xl font-bold text-ink">Logs</h1>
                <p class="text-sm text-mute">Who signed in and every stock edit, for your review</p>
            </div>

            {{-- Sign-ins --}}
            <x-dash-card title="Sign-ins" :subtitle="$loginSubtitle">
                @if (count($loginLogs) === 0)
                    <div class="empty-state text-sm text-mute">No sign-ins recorded yet.</div>
                @else
                    <div class="hidden overflow-x-auto sm:block">
                        <table class="table-base">
                            <thead>
                                <tr>
                                    <th>User</th>
                                    <th>Device</th>
                                    <th>IP</th>
                                    <th>Status</th>
                                    <th class="text-right">Time</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($loginLogs as $log)
                                    <tr>
                                        <td>
                                            <div class="font-medium text-ink">
                                                {{ $log['name'] ?? $log['email'] ?? 'Unknown' }}
                                            </div>
                                            @if ($log['email'] !== null && $log['email'] !== '')
                                                <div class="text-xs text-mute">{{ $log['email'] }}</div>
                                            @endif
                                        </td>
                                        <td>
                                            @if ($log['device'] !== null && $log['device'] !== '')
                                                <span class="badge badge-muted">{{ $log['device'] }}</span>
                                            @elseif ($log['user_agent'] !== null && $log['user_agent'] !== '')
                                                <span class="block max-w-[18rem] truncate text-xs text-mute"
                                                      title="{{ $log['user_agent'] }}">{{ $log['user_agent'] }}</span>
                                            @else
                                                <span class="text-mute">—</span>
                                            @endif
                                        </td>
                                        <td class="tnum">
                                            @if ($log['ip'] !== null && $log['ip'] !== '')
                                                <span class="text-ink">{{ $log['ip'] }}</span>
                                            @else
                                                <span class="text-mute">location unknown</span>
                                            @endif
                                        </td>
                                        <td><span class="badge badge-ok">Signed in</span></td>
                                        <td class="text-right text-mute tnum">{{ Format::dateTime($log['created_at']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <ul class="space-y-2 sm:hidden">
                        @foreach ($loginLogs as $log)
                            <li class="rounded-lg border border-line bg-paper p-3">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="font-medium text-ink">
                                                {{ $log['name'] ?? $log['email'] ?? 'Unknown' }}
                                            </span>
                                            @if ($log['device'] !== null && $log['device'] !== '')
                                                <span class="badge badge-muted">{{ $log['device'] }}</span>
                                            @endif
                                            <span class="badge badge-ok">Signed in</span>
                                        </div>
                                        <div class="mt-0.5 text-xs text-mute">
                                            {{ $log['email'] ? $log['email'].' · ' : '' }}{{ $log['ip'] ? 'IP '.$log['ip'] : 'location unknown' }}
                                        </div>
                                    </div>
                                    <span class="shrink-0 text-xs text-mute tnum">{{ Format::dateTime($log['created_at']) }}</span>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-dash-card>

            {{-- Stock audit --}}
            <x-dash-card title="Stock audit" :subtitle="$stockSubtitle">
                @if (count($stockLogs) === 0)
                    <div class="empty-state text-sm text-mute">No stock edits recorded yet.</div>
                @else
                    <div class="hidden overflow-x-auto sm:block">
                        <table class="table-base">
                            <thead>
                                <tr>
                                    <th>Actor</th>
                                    <th>Model</th>
                                    <th>Action</th>
                                    <th>Details</th>
                                    <th class="text-right">Time</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($stockLogs as $log)
                                    @php
                                        $badge = $actionBadge($log);
                                        $details = $stockDetails((string) $log['action'], $log['details']);
                                        $condition = (string) ($log['condition'] ?? '');
                                    @endphp
                                    <tr>
                                        <td class="font-medium text-ink">{{ $log['staff_name'] ?? '—' }}</td>
                                        <td>
                                            <div class="text-ink">
                                                {{ $log['model_name'] ?? '—' }}{{ $condition !== '' ? ' ('.$condition.')' : '' }}
                                            </div>
                                            @if ($log['shop_name'] !== null && $log['shop_name'] !== '')
                                                <div class="text-xs text-mute">{{ $log['shop_name'] }}</div>
                                            @endif
                                        </td>
                                        <td><span class="{{ $badge['class'] }}">{{ $badge['label'] }}</span></td>
                                        <td class="text-mute">{{ $details !== '' ? $details : '—' }}</td>
                                        <td class="text-right text-mute tnum">{{ Format::dateTime($log['created_at']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <ul class="space-y-2 sm:hidden">
                        @foreach ($stockLogs as $log)
                            @php
                                $badge = $actionBadge($log);
                                $details = $stockDetails((string) $log['action'], $log['details']);
                                $modelLine = ($log['model_name'] ?? '—').(($log['condition'] ?? '') !== '' ? ' ('.$log['condition'].')' : '');
                            @endphp
                            <li class="rounded-lg border border-line bg-paper p-3">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="font-medium text-ink">{{ $log['staff_name'] ?? '—' }}</span>
                                            <span class="{{ $badge['class'] }}">{{ $badge['label'] }}</span>
                                        </div>
                                        <div class="mt-0.5 text-xs text-mute">
                                            {{ $modelLine }}{{ $details !== '' ? ' · '.$details : '' }}{{ $log['shop_name'] ? ' · '.$log['shop_name'] : '' }}
                                        </div>
                                    </div>
                                    <span class="shrink-0 text-xs text-mute tnum">{{ Format::dateTime($log['created_at']) }}</span>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-dash-card>
        </div>
    @endif
@endsection
