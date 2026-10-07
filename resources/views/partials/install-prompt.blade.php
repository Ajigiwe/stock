{{--
  Custom install prompt — an in-app "Install" card driven by
  `beforeinstallprompt`, so staff never have to hunt through browser menus.
  iPhones never fire that event, so they get the Share-menu instructions
  instead. Hidden forever once installed or dismissed.
--}}
<div x-data="pwaInstallUI" x-init="init()" x-show="visible" x-cloak
     class="fixed inset-x-0 bottom-[132px] z-40 flex justify-center px-3 md:bottom-[76px]">
    <div class="flex w-full max-w-sm items-center gap-3 rounded-2xl border border-line bg-white px-4 py-3 shadow-xl">
        <img src="/icon-192.png" alt="" class="h-10 w-10 shrink-0 rounded-xl border border-line object-cover">
        <div class="min-w-0 flex-1">
            <p class="text-[13px] font-bold text-ink">Install Mr Jeff Stock</p>
            <p class="truncate text-xs text-mute"
               x-text="isIos ? 'iPhone: Share, then Add to Home Screen' : 'Faster access, works on the shop floor'"></p>
        </div>
        <button type="button" x-show="!isIos" @click="install()"
                class="btn-primary btn-sm h-8 shrink-0">Install</button>
        <button type="button" @click="dismiss()" aria-label="Dismiss install prompt"
                class="shrink-0 rounded-lg px-2 py-1 text-sm text-mute transition-colors hover:bg-paper hover:text-ink">&#10005;</button>
    </div>
</div>

@push('scripts')
    <script>
        function pwaInstallUI() {
            return {
                visible: false,
                isIos: false,
                deferred: null,
                init() {
                    try {
                        if (localStorage.getItem('mrjeff-install-dismissed') === '1') return;
                    } catch (e) { /* private mode: prompt every visit */ }
                    if (window.matchMedia('(display-mode: standalone)').matches
                        || window.navigator.standalone === true) return;

                    this.isIos = /iphone|ipad|ipod/i.test(window.navigator.userAgent || '');
                    if (this.isIos) {
                        this.visible = true;
                        return;
                    }

                    if (window.__mrjeffInstallEvent) {
                        this.deferred = window.__mrjeffInstallEvent;
                        this.visible = true;
                    }

                    window.addEventListener('beforeinstallprompt', (e) => {
                        e.preventDefault();
                        window.__mrjeffInstallEvent = e;
                        this.deferred = e;
                        this.visible = true;
                    });
                    window.addEventListener('appinstalled', () => {
                        window.__mrjeffInstallEvent = null;
                        this.visible = false;
                    });
                },
                async install() {
                    if (! this.deferred) return;
                    this.deferred.prompt();
                    const choice = await this.deferred.userChoice;
                    if (choice && choice.outcome === 'accepted') {
                        window.__mrjeffInstallEvent = null;
                        this.visible = false;
                    } else {
                        this.dismiss();
                    }
                },
                dismiss() {
                    try {
                        localStorage.setItem('mrjeff-install-dismissed', '1');
                    } catch (e) { /* private mode: prompt again next visit */ }
                    this.visible = false;
                },
            };
        }

        // Capture an event that fires before Alpine components initialise.
        window.addEventListener('beforeinstallprompt', (e) => {
            e.preventDefault();
            window.__mrjeffInstallEvent = window.__mrjeffInstallEvent || e;
        });
    </script>
@endpush
