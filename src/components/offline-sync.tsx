"use client";

import { useCallback, useEffect, useRef, useState, useSyncExternalStore } from "react";
import {
  getQueue,
  subscribeQueue,
  dequeueTransaction,
} from "@/lib/offline-queue";
import { recordTransaction } from "@/lib/actions";
import { useToast } from "@/components/feedback";

const QUEUE_CHANGE_EVENT = "offline-queue-changed";

// Fake external store that flips on after hydration — lets us render nothing
// on the server and immediately on the client without setState-in-effect.
let hydrated = false;
const mountListeners = new Set<() => void>();
function subscribeMount(cb: () => void) {
  if (!hydrated) {
    hydrated = true;
    // Flip after the current render pass, not synchronously in an effect body.
    setTimeout(() => mountListeners.forEach((l) => l()), 0);
  }
  mountListeners.add(cb);
  return () => {
    mountListeners.delete(cb);
  };
}

/** Fire after enqueueing so the sync banner reacts immediately. */
export function notifyQueueChanged() {
  window.dispatchEvent(new Event(QUEUE_CHANGE_EVENT));
}

export function OfflineSync() {
  const toast = useToast();
  const [online, setOnline] = useState(true);
  const [syncing, setSyncing] = useState(false);

  // Queue length comes from the external store; the server snapshot returns 0
  // so SSR and the first client render match (no hydration mismatch).
  const queueLen = useSyncExternalStore(
    subscribeQueue,
    () => getQueue().length,
    () => 0,
  );
  const mounted = useSyncExternalStore(
    subscribeMount,
    () => true,
    () => false,
  );

  // Latest sync function, readable from stable event listeners. Updated in an
  // effect (not during render) to satisfy the react-hooks/refs rule.
  const syncFnRef = useRef<() => void>(() => {});
  const syncingRef = useRef(false);

  const sync = useCallback(async () => {
    if (syncingRef.current || !navigator.onLine) return;
    const queue = getQueue();
    if (queue.length === 0) return;

    syncingRef.current = true;
    setSyncing(true);
    let synced = 0;
    let lastError: string | null = null;

    // Oldest-first; stop at the first failure so order is preserved — a later
    // transaction must never land on the server before an earlier one. The
    // idempotency key makes each attempt safe to repeat.
    for (const tx of queue) {
      try {
        if (tx.input.type !== "repair") {
          dequeueTransaction(tx.idempotencyKey);
          lastError = "Only repair charges may remain in the offline queue.";
          continue;
        }
        const res = await recordTransaction(tx.input);
        if (res.ok) {
          dequeueTransaction(tx.idempotencyKey);
          synced++;
        } else {
          lastError = res.error ?? "Unknown error";
          break;
        }
      } catch {
        // Network-level failure — stop and retry on the next trigger.
        lastError = "No connection.";
        break;
      }
    }

    syncingRef.current = false;
    setSyncing(false);
    if (synced > 0) {
      toast.success(
        synced === 1
          ? "1 offline transaction synced."
          : `${synced} offline transactions synced.`,
      );
      try {
        navigator.vibrate?.(30);
      } catch {
        /* unsupported */
      }
    }
    if (lastError && synced < queue.length) {
      toast.info(`Still queued: ${lastError}`);
    }
  }, [toast]);

  useEffect(() => {
    // Effect body — satisfies the refs lint rule for keeping a latest-value ref.
    syncFnRef.current = () => {
      void sync();
    };
  }, [sync]);

  useEffect(() => {
    const media = window.matchMedia("(offline)");
    const updateOnline = () => setOnline(navigator.onLine);
    updateOnline();
    media.addEventListener("change", updateOnline);

    const onOnline = () => {
      setOnline(true);
      syncFnRef.current();
    };
    const onOffline = () => setOnline(false);
    const onQueueChange = () => {
      if (navigator.onLine) syncFnRef.current();
    };

    window.addEventListener("online", onOnline);
    window.addEventListener("offline", onOffline);
    window.addEventListener(QUEUE_CHANGE_EVENT, onQueueChange);
    // Fallback sync sweep for devices that never fire "online" reliably.
    const interval = setInterval(() => syncFnRef.current(), 60_000);
    return () => {
      media.removeEventListener("change", updateOnline);
      window.removeEventListener("online", onOnline);
      window.removeEventListener("offline", onOffline);
      window.removeEventListener(QUEUE_CHANGE_EVENT, onQueueChange);
      clearInterval(interval);
    };
  }, []);

  const showBanner = mounted && (!online || queueLen > 0);
  if (!showBanner) return null;

  return (
    <div className="pointer-events-none fixed inset-x-0 bottom-[70px] z-40 flex justify-center px-3 md:bottom-4">
      <div
        role="status"
        className="pointer-events-auto flex w-full max-w-sm items-center gap-2.5 rounded-full border border-line bg-ink px-4 py-2.5 text-white shadow-lg"
      >
        <span
          aria-hidden="true"
          className={`h-2 w-2 shrink-0 rounded-full ${
            online ? "bg-ledger" : "bg-lowstock"
          } ${syncing ? "animate-pulse" : ""}`}
        />
        <span className="min-w-0 flex-1 truncate text-xs font-medium">
          {!online
            ? "Offline — only repair charges can be saved on this device"
            : syncing
              ? "Syncing queued transactions…"
              : `${queueLen} repair${queueLen === 1 ? "" : "s"} awaiting sync`}
        </span>
        {online && queueLen > 0 && !syncing && (
          <button
            type="button"
            onClick={() => void sync()}
            className="shrink-0 text-xs font-bold text-white underline underline-offset-2"
          >
            Sync now
          </button>
        )}
      </div>
    </div>
  );
}
