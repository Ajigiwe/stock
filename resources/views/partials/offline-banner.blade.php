{{--
  Offline banner + queued-offline confirmation — port of
  src/components/offline-sync.tsx (banner, states, wording) and the
  "savedOffline" screen of src/components/transaction-form.tsx.

  Alpine expressions use attribute syntax (x-text/x-show) only: Blade owns
  the {{ }} delimiter.
--}}
<div x-data="offlineQueueUI" x-init="init()" x-cloak>
    {{-- Queued-offline screen: distinct from the online success screen so the
         attendant knows it will sync automatically, not that it's saved. --}}
    <template x-if="queuedScreen">
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-ink/50 px-4">
            <div class="mx-auto flex w-full max-w-md flex-col items-center gap-3.5 rounded-2xl bg-white px-6 py-12 text-center shadow-xl">
                <div class="flex h-14 w-14 items-center justify-center rounded-full bg-brand-tint">
                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                         stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="text-brand">
                        <path d="M12 5v14M5 12l7 7 7-7" />
                    </svg>
                </div>
                <div class="text-lg font-extrabold text-ink">Saved on this device</div>
                <div class="max-w-xs text-[12.5px] text-mute">
                    You&rsquo;re offline — the transaction is stored safely and will sync
                    automatically when the connection returns.
                    <span x-show="length > 1"
                          x-text="' ' + (length - 1) + ' other transaction' + (length - 1 === 1 ? ' is' : 's are') + ' also waiting.'"></span>
                </div>
                <div class="mt-2">
                    <button type="button" @click="resetQueuedScreen(); window.location.reload()"
                            class="h-11 rounded-[10px] border border-line bg-white px-5 text-[13px] font-bold text-ink transition-colors hover:bg-paper">
                        Record another
                    </button>
                </div>
            </div>
        </div>
    </template>

    {{-- Offline / queued-sync pill --}}
    <div x-show="(!online || length > 0 || notice) && !queuedScreen"
         class="pointer-events-none fixed inset-x-0 bottom-[70px] z-40 flex justify-center px-3 md:bottom-4">
        <div role="status"
             class="pointer-events-auto flex w-full max-w-sm items-center gap-2.5 rounded-full border border-line bg-ink px-4 py-2.5 text-white shadow-lg">
            <span aria-hidden="true"
                  :class="(online ? 'bg-ledger' : 'bg-lowstock') + (syncing ? ' animate-pulse' : '')"
                  class="h-2 w-2 shrink-0 rounded-full"></span>
            <span class="min-w-0 flex-1 truncate text-xs font-medium"
                  x-text="notice
                      ? notice
                      : (!online
                          ? 'Offline — only repair charges can be saved on this device'
                          : (syncing
                              ? 'Syncing queued transactions…'
                              : length + ' repair' + (length === 1 ? '' : 's') + ' awaiting sync'))"></span>
            <button type="button" x-show="online && length > 0 && !syncing"
                    @click="syncNow()"
                    class="shrink-0 text-xs font-bold text-white underline underline-offset-2">
                Sync now
            </button>
        </div>
    </div>
</div>
