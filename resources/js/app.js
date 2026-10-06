import Alpine from 'alpinejs';

import { installFormHook, offlineQueueUI } from './offline-queue.js';

// Alpine powers the interactive bits (menu sheet, modals, multi-step POS
// form, tabs, offline banner). Everything that mutates data is a plain
// <form method="POST">; the offline hook only fires when the device is
// offline and the form opted in with data-offline-queue="repair".
window.Alpine = Alpine;

Alpine.data('offlineQueueUI', offlineQueueUI);

Alpine.start();

installFormHook();

// PWA registration — port of src/components/pwa-register.tsx: register once
// the page has loaded, ignore failures (private mode, unsupported browser).
if ('serviceWorker' in navigator) {
    const register = () => {
        navigator.serviceWorker.register('/sw.js').catch(() => {});
    };
    if (document.readyState === 'complete') register();
    else window.addEventListener('load', register);
}
