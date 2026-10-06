// ---------------------------------------------------------------------------
// Offline repair queue — port of src/lib/offline-queue.ts +
// src/components/offline-sync.tsx.
//
// A localStorage-backed FIFO of *repair* transactions recorded while offline.
// Sales and swaps need live stock, so they are refused offline (exactly as the
// original refused them); only repair charges — which never move stock — may
// wait on the device. Each entry keeps its client-generated idempotency key,
// so syncing is safe to retry: the DB dedupes on the key instead of recording
// the charge twice.
//
// Storage shape is versioned so a future format change can migrate or drop old
// entries cleanly.
// ---------------------------------------------------------------------------

const QUEUE_KEY = 'offline-tx-queue-v1';
const CHANGED_EVENT = 'mrjeff:offline-queue-changed';
const QUEUED_EVENT = 'mrjeff:offline-queued';
const RESULT_EVENT = 'mrjeff:offline-sync-result';
const ERROR_EVENT = 'mrjeff:offline-error';

function readQueue() {
    try {
        const raw = localStorage.getItem(QUEUE_KEY);
        if (!raw) return [];
        const parsed = JSON.parse(raw);
        return Array.isArray(parsed) ? parsed : [];
    } catch {
        return [];
    }
}

function writeQueue(queue) {
    try {
        localStorage.setItem(QUEUE_KEY, JSON.stringify(queue));
    } catch {
        // Storage full or unavailable — the entry is lost, but recording must
        // still surface an error rather than pretend it was queued.
        throw new Error('Could not save the offline queue.');
    }
}

function emit(name, detail) {
    window.dispatchEvent(new CustomEvent(name, { detail }));
}

export function getQueue() {
    return readQueue();
}

export function queueLength() {
    return readQueue().length;
}

/** Drop any entry with the same key, then push — matches enqueueTransaction(). */
export function enqueueTransaction(tx) {
    const queue = readQueue().filter((q) => q.idempotencyKey !== tx.idempotencyKey);
    queue.push(tx);
    writeQueue(queue);
    emit(CHANGED_EVENT, { length: queue.length });
}

export function dequeueTransaction(idempotencyKey) {
    const queue = readQueue();
    const next = queue.filter((q) => q.idempotencyKey !== idempotencyKey);
    const changed = next.length !== queue.length;
    if (changed) {
        writeQueue(next);
        emit(CHANGED_EVENT, { length: next.length });
    }
    return changed;
}

export function clearQueue() {
    writeQueue([]);
    emit(CHANGED_EVENT, { length: 0 });
}

let syncing = false;

/**
 * Oldest-first sync; stops at the first failure so a later repair never lands
 * before an earlier one. Repairs with the wrong type are dropped with the
 * original's message. Reports through RESULT_EVENT for the banner.
 */
export async function syncQueue() {
    if (syncing || !navigator.onLine) return;

    const queue = readQueue();
    if (queue.length === 0) return;

    syncing = true;
    emit(CHANGED_EVENT, { length: queue.length, syncing: true });

    let synced = 0;
    let lastError = null;
    const total = queue.length;

    for (const tx of queue) {
        try {
            if (tx.input?.type !== 'repair') {
                dequeueTransaction(tx.idempotencyKey);
                lastError = 'Only repair charges may remain in the offline queue.';
                continue;
            }

            const response = await fetch('/transactions', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify(tx.input),
            });

            if (response.ok) {
                dequeueTransaction(tx.idempotencyKey);
                synced++;
                continue;
            }

            let payload = null;
            try {
                payload = await response.json();
            } catch {
                // Non-JSON error body — treat as a server-side refusal.
            }
            lastError = payload?.error ?? 'Unknown error';
            break;
        } catch {
            // Network-level failure — stop and retry on the next trigger.
            lastError = 'No connection.';
            break;
        }
    }

    syncing = false;
    emit(CHANGED_EVENT, { length: queueLength() });

    const remaining = queueLength();
    emit(RESULT_EVENT, { synced, error: lastError, remaining });

    if (synced > 0) {
        try {
            navigator.vibrate?.(30);
        } catch {
            /* unsupported */
        }
    }
}

/**
 * Turn a submitted form into the JSON payload the record action expects:
 * `outItems[0][qty]` → `outItems[0].qty`, `_token` skipped (sent as a header).
 */
export function formToObject(form) {
    const out = {};
    for (const [rawKey, value] of new FormData(form).entries()) {
        if (rawKey === '_token' || rawKey === '_method') continue;
        const path = rawKey.replaceAll(']', '').split('[');
        let node = out;
        for (let i = 0; i < path.length - 1; i++) {
            const segment = path[i];
            if (node[segment] === undefined) {
                node[segment] = /^\d+$/.test(path[i + 1]) ? [] : {};
            }
            node = node[segment];
        }
        node[path[path.length - 1]] = value;
    }
    return out;
}

/**
 * Form hook: forms carrying `data-offline-queue="repair"` queue themselves when
 * the device is offline instead of firing a request that would never leave.
 * Online submissions are left to the browser (a plain POST), so the page works
 * with JavaScript disabled.
 */
export function installFormHook() {
    document.addEventListener('submit', (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement)) return;
        if (form.dataset.offlineQueue !== 'repair') return;
        if (navigator.onLine) return;

        event.preventDefault();

        const input = formToObject(form);

        if (input.type !== 'repair') {
            emit(ERROR_EVENT, {
                message: 'Sales and swaps need a live connection. Reconnect before saving.',
            });
            return;
        }

        try {
            enqueueTransaction({
                idempotencyKey:
                    input.idempotencyKey ||
                    (crypto.randomUUID ? crypto.randomUUID() : String(Date.now())),
                recordedAt: Date.now(),
                shopName: form.dataset.shopName ?? '',
                summary: `${input.type}`,
                input,
            });
        } catch (error) {
            emit(ERROR_EVENT, { message: error instanceof Error ? error.message : String(error) });
            return;
        }

        try {
            navigator.vibrate?.(30);
        } catch {
            /* unsupported */
        }

        emit(QUEUED_EVENT, { length: queueLength() });
    }, true);
}

/**
 * Banner state for the Blade partial. Mirrors OfflineSync(): listens to
 * online/offline, queue mutations and the 60-second fallback sweep, and shows
 * the original's wording.
 */
export function offlineQueueUI() {
    return {
        online: navigator.onLine,
        syncing: false,
        length: 0,
        notice: '',
        noticeKind: 'info',
        queuedScreen: false,

        init() {
            this.length = queueLength();

            const refresh = () => {
                this.online = navigator.onLine;
                this.length = queueLength();
            };

            window.addEventListener('online', () => {
                this.online = true;
                void syncQueue();
            });
            window.addEventListener('offline', () => {
                this.online = false;
            });
            window.addEventListener(CHANGED_EVENT, (event) => {
                this.length = event.detail?.length ?? queueLength();
                this.syncing = Boolean(event.detail?.syncing);
                if (this.online) void syncQueue();
            });
            window.addEventListener(RESULT_EVENT, (event) => {
                const { synced, error } = event.detail ?? {};
                if (synced > 0) {
                    this.showNotice(
                        synced === 1
                            ? '1 offline transaction synced.'
                            : `${synced} offline transactions synced.`,
                        'ok',
                    );
                }
                if (error) this.showNotice(`Still queued: ${error}`, 'info');
            });
            window.addEventListener(ERROR_EVENT, (event) => {
                this.showNotice(event.detail?.message ?? 'Could not save.', 'error');
            });
            window.addEventListener(QUEUED_EVENT, () => {
                this.queuedScreen = true;
                this.length = queueLength();
            });

            // Fallback sync sweep for devices that never fire "online" reliably.
            setInterval(() => void syncQueue(), 60000);
            if (this.online) void syncQueue();
        },

        showNotice(message, kind) {
            this.notice = message;
            this.noticeKind = kind;
            clearTimeout(this._noticeTimer);
            this._noticeTimer = setTimeout(() => {
                this.notice = '';
            }, 8000);
        },

        async syncNow() {
            await syncQueue();
        },

        resetQueuedScreen() {
            this.queuedScreen = false;
        },
    };
}
