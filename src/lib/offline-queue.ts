"use client";

// ---------------------------------------------------------------------------
// Offline transaction queue
//
// A tiny localStorage-backed FIFO of transactions recorded while offline (or
// while the server was unreachable). Each entry carries the client-generated
// idempotency key, so syncing is safe to retry: the DB dedupes on the key and
// returns the existing transaction id instead of deducting stock twice.
//
// Storage shape is versioned so a future format change can migrate or drop
// old entries cleanly.
// ---------------------------------------------------------------------------

const QUEUE_KEY = "offline-tx-queue-v1";

/** Matches RecordTransactionInput without importing the server module. */
export type QueuedInput = {
  shopId: string;
  customerName: string;
  customerPhone: string;
  type: "sale" | "swap" | "repair";
  paymentMethod:
    | "cash"
    | "mobile_money"
    | "card"
    | "bank_transfer"
    | "other";
  amount: string;
  date: string;
  outItems: { modelId: string; qty: number }[];
  swapIn: { name: string }[];
  idempotencyKey: string;
};

export type QueuedTransaction = {
  /** Client-generated idempotency key (uuid) — stable across sync retries. */
  idempotencyKey: string;
  /** Epoch ms when the transaction was recorded, shown in the queue UI. */
  recordedAt: number;
  /** Shop name at record time, for the queue UI. */
  shopName: string;
  /** Human summary, e.g. "2× iPhone 13 128GB — sale". */
  summary: string;
  /** The exact input for the recordTransaction server action. */
  input: QueuedInput;
};

function readQueue(): QueuedTransaction[] {
  try {
    const raw = localStorage.getItem(QUEUE_KEY);
    if (!raw) return [];
    const parsed = JSON.parse(raw);
    return Array.isArray(parsed) ? parsed : [];
  } catch {
    return [];
  }
}

function writeQueue(queue: QueuedTransaction[]) {
  try {
    localStorage.setItem(QUEUE_KEY, JSON.stringify(queue));
  } catch {
    // Storage full or unavailable — the entry is lost, but recording must
    // still surface an error rather than pretend it was queued.
    throw new Error("Could not save the offline queue.");
  }
}

// External store so components subscribe without prop drilling. Mirrors the
// sidebar-collapse pattern in app-shell.tsx: the initial client snapshot is
// read lazily after mount via a version counter to avoid hydration mismatch.
const listeners = new Set<() => void>();
let version = 0;

function notify() {
  version++;
  listeners.forEach((l) => l());
}

export function subscribeQueue(cb: () => void) {
  listeners.add(cb);
  return () => {
    listeners.delete(cb);
  };
}

/** Monotonic counter bumped on every queue mutation — safe pre-mount snapshot. */
export function getQueueVersion() {
  return version;
}

export function getQueue(): QueuedTransaction[] {
  return readQueue();
}

export function queueLength(): number {
  return readQueue().length;
}

export function enqueueTransaction(tx: QueuedTransaction) {
  const queue = readQueue();
  // Drop any entry with the same idempotency key (paranoia — shouldn't happen
  // since the key is regenerated per form).
  const next = queue.filter((q) => q.idempotencyKey !== tx.idempotencyKey);
  next.push(tx);
  writeQueue(next);
  notify();
}

export function dequeueTransaction(idempotencyKey: string) {
  const queue = readQueue();
  const next = queue.filter((q) => q.idempotencyKey !== idempotencyKey);
  const changed = next.length !== queue.length;
  if (changed) {
    writeQueue(next);
    notify();
  }
  return changed;
}

/** Remove everything (used after a successful full sync or explicit clear). */
export function clearQueue() {
  writeQueue([]);
  notify();
}
