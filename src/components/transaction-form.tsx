"use client";

import { useEffect, useMemo, useState, useTransition } from "react";
import { useRouter } from "next/navigation";
import { recordTransaction } from "@/lib/actions";
import type { Shop, PhoneModel } from "@/lib/data";
import { todayISO, formatMoney } from "@/lib/format";
import { Badge, ErrorNote, Field, Input, Select } from "@/components/ui";
import { ModelPicker } from "@/components/model-picker";
import { useToast } from "@/components/feedback";
import {
  enqueueTransaction,
  queueLength,
} from "@/lib/offline-queue";
import { notifyQueueChanged } from "@/components/offline-sync";

type TxType = "sale" | "swap" | "repair";

const PAYMENTS = [
  { value: "cash", label: "Cash" },
  { value: "mobile_money", label: "Mobile money" },
  { value: "card", label: "Card" },
  { value: "bank_transfer", label: "Bank transfer" },
  { value: "other", label: "Other" },
] as const;

const IPHONE_MODELS = [
  "iPhone 7", "iPhone 7 Plus", "iPhone 8", "iPhone 8 Plus",
  "iPhone X", "iPhone XR", "iPhone XS", "iPhone XS Max",
  "iPhone 11", "iPhone 11 Pro", "iPhone 11 Pro Max",
  "iPhone 12", "iPhone 12 mini", "iPhone 12 Pro", "iPhone 12 Pro Max",
  "iPhone 13", "iPhone 13 mini", "iPhone 13 Pro", "iPhone 13 Pro Max",
  "iPhone 14", "iPhone 14 Plus", "iPhone 14 Pro", "iPhone 14 Pro Max",
  "iPhone 15", "iPhone 15 Plus", "iPhone 15 Pro", "iPhone 15 Pro Max",
  "iPhone 16", "iPhone 16 Plus", "iPhone 16 Pro", "iPhone 16 Pro Max",
  "iPhone SE (2nd gen)", "iPhone SE (3rd gen)",
] as const;

type OutLine = { key: number; modelId: string; qty: string };
type SwapLine = { key: number; name: string };
let nextKey = 1;

// Count of meaningfully filled out-lines, used for the dirty check before the
// `validOut` memo exists (it runs during render of the same component).
function validOutDraft(lines: OutLine[]): number {
  return lines.filter((l) => l.modelId && Number(l.qty) > 0).length;
}

const ICON_SALE = (
  <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
    <path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z" />
    <path d="M3 6h18" />
    <path d="M16 10a4 4 0 0 1-8 0" />
  </svg>
);
const ICON_SWAP = (
  <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
    <path d="M17 1 21 5l-4 4" />
    <path d="M3 11V9a4 4 0 0 1 4-4h14" />
    <path d="M7 23 3 19l4-4" />
    <path d="M21 13v2a4 4 0 0 1-4 4H3" />
  </svg>
);
const ICON_REPAIR = (
  <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
    <path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z" />
  </svg>
);

// Quantity can now be typed directly ("5") as well as stepped — tapping + four
// times behind the counter was slower than typing.
function QtyStepper({ value, onChange }: { value: string; onChange: (v: string) => void }) {
  const v = Math.max(1, Number(value) || 1);
  return (
    <div className="inline-flex shrink-0 items-center overflow-hidden rounded-[10px] border border-line bg-white">
      <button type="button" aria-label="Decrease quantity" onClick={() => onChange(String(Math.max(1, v - 1)))}
        className="flex h-11 w-10 items-center justify-center bg-paper text-lg text-ink transition-colors hover:bg-line/50 active:bg-line">−</button>
      <input
        type="number"
        inputMode="numeric"
        min={1}
        value={v}
        onChange={(e) => {
          const n = Number(e.target.value);
          onChange(Number.isFinite(n) && n >= 1 ? String(Math.floor(n)) : "1");
        }}
        className="h-11 w-10 border-x border-line bg-white text-center font-mono text-sm font-bold tabular-nums text-ink [appearance:textfield] focus:outline-none [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none"
      />
      <button type="button" aria-label="Increase quantity" onClick={() => onChange(String(v + 1))}
        className="flex h-11 w-10 items-center justify-center bg-paper text-lg text-ink transition-colors hover:bg-line/50 active:bg-line">+</button>
    </div>
  );
}

function Section({ title, sub, tone, children, action }: {
  title: string; sub?: string; tone: "out" | "in" | "mid"; children: React.ReactNode; action?: React.ReactNode;
}) {
  // The accent is a colored left border (not an overflow-clipped strip):
  // sections must never clip absolutely-positioned dropdowns such as the
  // model picker, and border-l still follows the card's border radius.
  const edge =
    tone === "out" ? "border-l-lowstock"
    : tone === "in" ? "border-l-instock"
    : "border-l-brand";
  return (
    <section className={`rounded-2xl border border-line bg-white shadow-[0_1px_2px_rgba(20,22,43,0.04)] ${edge} border-l-4`}>
      <div className="w-full p-4 sm:p-5">
        <div className="mb-3.5 flex items-start justify-between gap-3">
          <div className="min-w-0">
            <h2 className="text-[15px] font-bold tracking-tight text-ink">{title}</h2>
            {sub && <p className="mt-0.5 text-[12.5px] text-mute">{sub}</p>}
          </div>
          {action}
        </div>
        <div>{children}</div>
      </div>
    </section>
  );
}

function TypeCard({ icon, label, sub, active, onClick }: {
  icon: React.ReactNode; label: string; sub: string; active: boolean; onClick: () => void;
}) {
  return (
    <button type="button" onClick={onClick}
      className={`group flex min-h-[84px] flex-col items-center justify-center gap-1.5 rounded-2xl border-[1.5px] px-3 py-4 text-center transition-all ${
        active
          ? "border-brand bg-brand-tint shadow-[0_4px_14px_rgba(67,56,202,0.18)]"
          : "border-line bg-white hover:border-brand/40 hover:bg-brand-tint/40"
      }`}>
      <span className={`flex h-9 w-9 items-center justify-center rounded-xl transition-colors ${
        active ? "bg-brand text-white" : "bg-paper text-mute group-hover:bg-brand/10 group-hover:text-brand"
      }`}>
        {icon}
      </span>
      <span className="text-[13.5px] font-extrabold tracking-tight text-ink">{label}</span>
      <span className="text-[11px] leading-tight text-mute">{sub}</span>
    </button>
  );
}

export function TransactionForm({ shops, stock, defaultShopId, isOwner }: {
  shops: Shop[]; stock: PhoneModel[]; defaultShopId?: string; isOwner: boolean;
}) {
  const router = useRouter();
  const toast = useToast();
  const [pending, startTransition] = useTransition();
  const [error, setError] = useState<string | null>(null);

  const [type, setType] = useState<TxType>("sale");
  const [shopId, setShopId] = useState(defaultShopId ?? shops[0]?.id ?? "");
  const [customerName, setCustomerName] = useState("");
  const [customerPhone, setCustomerPhone] = useState("");
  const [paymentMethod, setPaymentMethod] = useState<(typeof PAYMENTS)[number]["value"]>("cash");
  const [amount, setAmount] = useState("");
  const [discountReason, setDiscountReason] = useState("");
  const [paymentReference, setPaymentReference] = useState("");
  const [date, setDate] = useState(todayISO());
  const [outLines, setOutLines] = useState<OutLine[]>([{ key: nextKey++, modelId: "", qty: "1" }]);
  const [swapLines, setSwapLines] = useState<SwapLine[]>([{ key: nextKey++, name: "" }]);
  const [savedId, setSavedId] = useState<string | null>(null);
  const [savedOffline, setSavedOffline] = useState(false);
  const [pendingQueueLen, setPendingQueueLen] = useState(0);
  const [online, setOnline] = useState(true);

  useEffect(() => {
    const update = () => setOnline(navigator.onLine);
    update();
    window.addEventListener("online", update);
    window.addEventListener("offline", update);
    return () => {
      window.removeEventListener("online", update);
      window.removeEventListener("offline", update);
    };
  }, []);

  // Warn before leaving with a half-filled form. Back-swipe on mobile and
  // refresh both route through beforeunload; in-app nav is covered in
  // app-shell navigation handlers where the form is mounted.
  const dirty =
    savedId == null &&
    (customerName.trim() !== "" ||
      customerPhone.trim() !== "" ||
      amount !== "" ||
      validOutDraft(outLines) > 0);
  useEffect(() => {
    if (!dirty) return;
    const onBeforeUnload = (e: BeforeUnloadEvent) => {
      e.preventDefault();
    };
    window.addEventListener("beforeunload", onBeforeUnload);
    return () => window.removeEventListener("beforeunload", onBeforeUnload);
  }, [dirty]);

  // One key per filled-in form, generated on the CLIENT. The DB dedupes on it,
  // so a double-tap, a Server Action retry, or a dropped mobile connection that
  // resends the request all resolve to the same transaction instead of deducting
  // stock twice. resetForm() issues a fresh key.
  const [idempotencyKey, setIdempotencyKey] = useState<string>(() =>
    crypto.randomUUID(),
  );

  const shopModels = useMemo(() => stock.filter((m) => m.shop_id === shopId), [stock, shopId]);
  const shopName = shops.find((s) => s.id === shopId)?.name ?? "";
  const validOut = outLines.filter((l) => l.modelId && Number(l.qty) > 0);
  const validSwap = swapLines.filter((l) => l.name.trim());
  const unitsOut = validOut.reduce((a, l) => a + Number(l.qty), 0);
  const dateLabel = new Date(date + "T12:00:00").toLocaleDateString("en-GB", {
    weekday: "short", day: "numeric", month: "short",
  });

  const suggested =
    type === "sale"
      ? validOut.reduce((sum, l) => {
          const m = shopModels.find((x) => x.id === l.modelId);
          return sum + (m?.sale_price != null ? m.sale_price * Number(l.qty) : 0);
        }, 0)
      : null;

  const enteredAmount = Number(amount);
  const amountValid = Number.isFinite(enteredAmount) && enteredAmount >= 0;
  const belowList = suggested != null && suggested > 0 && enteredAmount < suggested;

  const switchShop = (id: string) => {
    setShopId(id);
    setOutLines([{ key: nextKey++, modelId: "", qty: "1" }]);
    setSwapLines([{ key: nextKey++, name: "" }]);
  };

  const resetForm = () => {
    setType("sale");
    setShopId(defaultShopId ?? shops[0]?.id ?? "");
    setCustomerName("");
    setCustomerPhone("");
    setPaymentMethod("cash");
    setAmount("");
    setDiscountReason("");
    setPaymentReference("");
    setDate(todayISO());
    setOutLines([{ key: nextKey++, modelId: "", qty: "1" }]);
    setSwapLines([{ key: nextKey++, name: "" }]);
    setSavedId(null);
    setSavedOffline(false);
    setPendingQueueLen(0);
    setError(null);
    // A new form is a new transaction, so it needs a new key.
    setIdempotencyKey(crypto.randomUUID());
    window.scrollTo({ top: 0, behavior: "smooth" });
  };

  const submit = () => {
    if (pending) return;
    setError(null);
    if (type !== "repair" && validOut.length === 0) return setError("Add at least one phone going out.");
    if (type === "swap" && validSwap.length === 0) return setError("Add the old phone the customer is trading in.");
    if (!amountValid) return setError("Enter a valid amount.");
    if (!customerName.trim() || !customerPhone.trim()) return setError("Customer name and phone are required.");
    if (type === "sale" && belowList && !discountReason.trim()) {
      return setError("Add a reason for the discount before saving.");
    }

    const outItems = validOut.map((l) => ({ modelId: l.modelId, qty: Number(l.qty) }));
    const swapIn = type === "swap" ? validSwap.map((l) => ({ name: l.name })) : [];

    const submitInput = {
      shopId,
      customerName,
      customerPhone,
      type,
      paymentMethod,
      amount,
      date,
      outItems,
      swapIn,
      idempotencyKey,
      discountReason,
      paymentReference,
    };

    // Offline (or the request never left): queue the transaction locally and
    // confirm to the user. The idempotency key stays with the queued entry, so
    // syncing later is dedupe-safe even if the server also received the call.
    const goOfflineQueue = () => {
      if (type !== "repair") {
        setError("Sales and swaps need a live connection. Reconnect before saving.");
        return;
      }
      try {
        enqueueTransaction({
          idempotencyKey,
          recordedAt: Date.now(),
          shopName,
          summary:
            (outItems.length > 0
              ? outItems.reduce((a, i) => `${a}${a ? ", " : ""}${i.qty}× ${shopModels.find((m) => m.id === i.modelId)?.model_name ?? "phone"}`, "")
              : type) + ` — ${type}`,
          input: submitInput,
        });
      } catch (e) {
        setError((e as Error).message);
        return;
      }
      notifyQueueChanged();
      try { navigator.vibrate?.(30); } catch { /* unsupported */ }
      setPendingQueueLen(queueLength());
      setSavedOffline(true);
    };

    startTransition(async () => {
      if (!online) {
        goOfflineQueue();
        return;
      }
      let res;
      try {
        res = await recordTransaction(submitInput);
      } catch {
        // Server Action network failure (dropped connection mid-request).
        goOfflineQueue();
        return;
      }
      if (!res.ok) { setError(res.error ?? "Failed to record transaction."); return; }
      if (res.warning) toast.error(res.warning);
      else toast.success("Transaction recorded.");
      // Light haptic tick on success — the phone is often in a pocket or the
      // user is looking at the customer, not the screen.
      try { navigator.vibrate?.(30); } catch { /* unsupported */ }
      setSavedId(res.id ?? null);
      if (res.id) router.push(`/transactions/${res.id}`);
      else router.push(`/shops/${shopId}`);
      router.refresh();
    });
  };

  const paymentSub =
    type === "swap" ? "Top-up cash (GHS)"
    : type === "repair" ? "Repair charge (GHS)"
    : "Total sale amount (GHS)";

  const saveLabel =
    pending ? "Saving…"
    : type === "swap" ? "Record swap"
    : type === "repair" ? "Record repair charge"
    : "Record sale";

  // Queued-offline screen: distinct from the online success screen so the
  // attendant knows it will sync automatically, not that it's already saved.
  if (savedOffline) {
    return (
      <div className="mx-auto flex max-w-md flex-col items-center gap-3.5 px-4 pb-16 pt-20 text-center">
        <div className="flex h-14 w-14 items-center justify-center rounded-full bg-brand-tint">
          <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round" className="text-brand">
            <path d="M12 5v14M5 12l7 7 7-7" />
          </svg>
        </div>
        <div className="text-lg font-extrabold text-ink">Saved on this device</div>
        <div className="max-w-xs text-[12.5px] text-mute">
          You&rsquo;re offline — the transaction is stored safely and will sync
          automatically when the connection returns.
          {pendingQueueLen > 1
            ? ` ${pendingQueueLen - 1} other transaction${pendingQueueLen - 1 === 1 ? " is" : "s are"} also waiting.`
            : ""}
        </div>
        <div className="mt-2 flex gap-2.5">
          <button onClick={resetForm} className="h-11 rounded-[10px] border border-line bg-white px-5 text-[13px] font-bold text-ink transition-colors hover:bg-paper">
            Record another
          </button>
        </div>
      </div>
    );
  }

  // Success screen
  if (savedId) {
    return (
      <div className="mx-auto flex max-w-md flex-col items-center gap-3.5 px-4 pb-16 pt-20 text-center">
        <div className="flex h-14 w-14 items-center justify-center rounded-full bg-instock-tint">
          <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="3" strokeLinecap="round" strokeLinejoin="round" className="text-instock"><path d="M20 6L9 17l-5-5" /></svg>
        </div>
        <div className="text-lg font-extrabold text-ink">Transaction saved</div>
        <div className="max-w-xs text-[12.5px] text-mute">
          {unitsOut > 0 ? `${unitsOut}× ${validOut[0] ? shopModels.find((m) => m.id === validOut[0].modelId)?.model_name ?? "phone" : "phone"}` : type}
          {" "}recorded as a {type} at {shopName}.
        </div>
        <div className="mt-2 flex gap-2.5">
          <button onClick={resetForm} className="h-11 rounded-[10px] border border-line bg-white px-5 text-[13px] font-bold text-ink transition-colors hover:bg-paper">
            Record another
          </button>
        </div>
      </div>
    );
  }

  const selectedOut = validOut.map((l) => {
    const m = shopModels.find((x) => x.id === l.modelId);
    return { name: m?.model_name ?? "Phone", qty: Number(l.qty), price: m?.sale_price ?? 0 };
  });
  const hasOut = selectedOut.length > 0;

  return (
    <div className="mx-auto w-full max-w-5xl px-4 pb-24 sm:px-6 lg:px-8">
      {/* Header */}
      <div className="mb-5 flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-2xl font-extrabold tracking-tight text-ink">Record transaction</h1>
          <p className="mt-1 text-[13px] text-mute">
            Pick a type, add the phones, then take payment — all on one page.
          </p>
        </div>
        {shopName && (
          <div className="flex items-center gap-2 rounded-full border border-line bg-white px-3.5 py-2 text-xs font-semibold text-ink/80">
            <span className="h-1.5 w-1.5 rounded-full bg-instock" aria-hidden="true" />
            {shopName}
            <span className="text-line">|</span>
            <span className="font-medium text-mute">{dateLabel}</span>
          </div>
        )}
      </div>

      {/* Type */}
      <div className="grid grid-cols-3 gap-2.5">
        <TypeCard icon={ICON_SALE} label="Sale" sub="Phone leaves the shop" active={type === "sale"} onClick={() => setType("sale")} />
        <TypeCard icon={ICON_SWAP} label="Swap" sub="Out + trade-in + top-up" active={type === "swap"} onClick={() => setType("swap")} />
        <TypeCard icon={ICON_REPAIR} label="Repair" sub="Logged for service" active={type === "repair"} onClick={() => setType("repair")} />
      </div>
      {isOwner && (
        <div className="mt-3 sm:max-w-xs">
          <Field label="Shop">
            <Select value={shopId} onChange={(e) => switchShop(e.target.value)}>
              {shops.map((s) => (<option key={s.id} value={s.id}>{s.name}</option>))}
            </Select>
          </Field>
        </div>
      )}

      {/* Main two-column layout: pick on the left, settle on the right */}
      <div className="mt-5 grid items-start gap-4 lg:grid-cols-[minmax(0,1fr)_380px]">
        {/* ---- Left: phones ---- */}
        <div className="min-w-0 space-y-4">
          {type !== "repair" ? (
            <Section
              title="Phones going out"
              sub="These leave the shop's stock"
              tone="out"
              action={
                <button type="button" onClick={() => setOutLines((ls) => [...ls, { key: nextKey++, modelId: "", qty: "1" }])}
                  className="shrink-0 rounded-lg bg-brand-tint px-3 py-1.5 text-xs font-bold text-brand transition-colors hover:bg-brand/10">
                  + Add phone
                </button>
              }
            >
              <div className="space-y-2.5">
                {outLines.map((line) => {
                  const model = shopModels.find((m) => m.id === line.modelId);
                  const low = model != null && model.available <= model.low_stock_threshold;
                  return (
                    <div key={line.key} className="rounded-xl border border-line bg-paper p-3">
                      <div className="flex items-end gap-2.5">
                        <div className="min-w-0 flex-1">
                          <Field label="Phone model">
                            <ModelPicker models={shopModels} value={line.modelId} showStock
                              onChange={(id) => setOutLines((ls) => ls.map((l) => l.key === line.key ? { ...l, modelId: id } : l))} />
                          </Field>
                        </div>
                        <QtyStepper value={line.qty}
                          onChange={(qty) => setOutLines((ls) => ls.map((l) => l.key === line.key ? { ...l, qty } : l))} />
                        <button type="button" aria-label="Remove phone"
                          onClick={() => setOutLines((ls) => ls.length > 1 ? ls.filter((l) => l.key !== line.key) : [{ key: nextKey++, modelId: "", qty: "1" }])}
                          className="mb-0.5 inline-flex h-11 w-10 shrink-0 items-center justify-center rounded-lg text-mute transition-colors hover:bg-lowstock-tint hover:text-lowstock">
                          ✕
                        </button>
                      </div>
                      {model && (
                        <p className="mt-2 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs">
                          <Badge tone={model.condition === "new" ? "blue" : "gray"}>{model.condition}</Badge>
                          {model.sale_price != null && <span className="text-mute">Listed {formatMoney(model.sale_price)}</span>}
                          <span className={low ? "text-lowstock" : "text-mute"}>
                            {model.available} in stock{low ? " · low!" : ""}
                          </span>
                        </p>
                      )}
                    </div>
                  );
                })}
              </div>
            </Section>
          ) : (
            <div className="rounded-2xl border border-line bg-white p-5 shadow-[0_1px_2px_rgba(20,22,43,0.04)]">
              <div className="flex items-start gap-3">
                <span className="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-brand-tint text-brand">{ICON_REPAIR}</span>
                <div>
                  <h2 className="text-[15px] font-bold tracking-tight text-ink">Service-only repair</h2>
                  <p className="mt-1 text-[13px] leading-relaxed text-mute">
                    The customer&rsquo;s phone comes in and goes back with them — no stock moves.
                    Only the repair charge below is recorded, and an offline repair stays
                    <span className="font-semibold text-brand"> awaiting sync </span> until the server confirms it.
                  </p>
                </div>
              </div>
            </div>
          )}

          {type === "swap" && (
            <Section
              title="Old iPhone received"
              sub="The trade-in model — no need to enter its details"
              tone="in"
              action={
                <button type="button" onClick={() => setSwapLines((ls) => [...ls, { key: nextKey++, name: "" }])}
                  className="shrink-0 rounded-lg bg-instock-tint px-3 py-1.5 text-xs font-bold text-instock transition-colors hover:bg-instock/10">
                  + Add phone
                </button>
              }
            >
              <div className="space-y-2.5">
                {swapLines.map((line) => (
                  <div key={line.key} className="flex items-end gap-2.5 rounded-xl border border-line bg-paper p-3">
                    <div className="min-w-0 flex-1">
                      <Field label="iPhone model">
                        <Select value={line.name} onChange={(e) => setSwapLines((ls) => ls.map((l) => l.key === line.key ? { ...l, name: e.target.value } : l))}>
                          <option value="">Select iPhone model…</option>
                          {IPHONE_MODELS.map((m) => (<option key={m} value={m}>{m}</option>))}
                        </Select>
                      </Field>
                    </div>
                    <button type="button" aria-label="Remove"
                      onClick={() => setSwapLines((ls) => ls.length > 1 ? ls.filter((l) => l.key !== line.key) : [{ key: nextKey++, name: "" }])}
                      className="mb-0.5 inline-flex h-11 w-10 shrink-0 items-center justify-center rounded-lg text-mute transition-colors hover:bg-lowstock-tint hover:text-lowstock">
                      ✕
                    </button>
                  </div>
                ))}
              </div>
            </Section>
          )}
        </div>

        {/* ---- Right: customer, payment, save ---- */}
        <div className="min-w-0 space-y-4 lg:sticky lg:top-6">
          <Section title="Customer" tone="mid">
            <div className="flex flex-col gap-3">
              <div>
                <Field label="Name" required>
                  <Input value={customerName} onChange={(e) => setCustomerName(e.target.value)} placeholder="Customer name" />
                </Field>
              </div>
              <div>
                <Field label="Phone" required>
                  <Input value={customerPhone} onChange={(e) => setCustomerPhone(e.target.value)} placeholder="Customer phone" />
                </Field>
              </div>
            </div>
          </Section>

          <Section title="Payment" sub={paymentSub} tone="mid">
            <div className="space-y-3">
              <div className="relative">
                <span className="absolute left-3.5 top-1/2 -translate-y-1/2 text-[13px] font-bold text-mute">GHS</span>
                <Input type="number" min="0" step="0.01" placeholder="0.00" value={amount}
                  onChange={(e) => setAmount(e.target.value)}
                  className="!h-14 !pl-12 font-mono text-lg font-bold text-ink" />
              </div>

              {suggested != null && suggested > 0 && (
                <button type="button" onClick={() => setAmount(String(suggested))}
                  className="text-xs font-bold text-brand underline underline-offset-2 hover:text-brand-deep">
                  Use suggested amount — {formatMoney(suggested)}
                </button>
              )}

              <div className="grid gap-3 sm:grid-cols-2">
                <Field label="Payment method">
                  <Select value={paymentMethod} onChange={(e) => setPaymentMethod(e.target.value as typeof paymentMethod)}>
                    {PAYMENTS.map((p) => (<option key={p.value} value={p.value}>{p.label}</option>))}
                  </Select>
                </Field>
                <Field label="Payment reference">
                  <Input value={paymentReference} onChange={(e) => setPaymentReference(e.target.value)} placeholder={paymentMethod === "mobile_money" ? "MoMo reference" : "optional"} />
                </Field>
                <Field label="Date">
                  <Input type="date" value={date} onChange={(e) => setDate(e.target.value)} />
                </Field>
              </div>

              {type === "sale" && belowList && (
                <Field label="Discount reason" required>
                  <Input value={discountReason} onChange={(e) => setDiscountReason(e.target.value)} placeholder="Why is this below the listed price?" />
                </Field>
              )}

              {type === "repair" && !online && (
                <p className="rounded-lg border border-brand bg-brand-tint px-3 py-2 text-xs text-brand">
                  Offline repair charges are saved as awaiting sync and do not count as completed revenue until the server receives them.
                </p>
              )}

              {/* Order summary */}
              <div className="overflow-hidden rounded-xl border border-line">
                <div className="bg-paper px-3.5 py-2 text-[10.5px] font-bold uppercase tracking-wider text-mute">
                  {type === "swap" ? "Swap summary" : type === "repair" ? "Repair summary" : "Order summary"}
                </div>
                <div className="divide-y divide-line/70">
                  {!hasOut && type !== "repair" && (
                    <p className="px-3.5 py-2.5 text-xs text-mute">No phones added yet.</p>
                  )}
                  {hasOut && selectedOut.map((o) => (
                    <div key={o.name} className="flex items-center justify-between gap-3 px-3.5 py-2 text-[12.5px]">
                      <span className="min-w-0 truncate text-ink/90">
                        <b className="font-mono font-semibold text-mute">{o.qty}×</b> {o.name}
                      </span>
                      <span className="shrink-0 font-mono font-semibold tabular-nums text-ink">{formatMoney(o.price * o.qty)}</span>
                    </div>
                  ))}
                  {type === "swap" && validSwap.map((s) => (
                    <div key={s.key} className="flex items-center justify-between gap-3 px-3.5 py-2 text-[12.5px]">
                      <span className="truncate text-ink/90">
                        <b className="font-mono font-semibold text-mute">IN</b> {s.name} (trade-in)
                      </span>
                      <span className="shrink-0 text-[11px] font-semibold uppercase tracking-wide text-instock">+valued</span>
                    </div>
                  ))}
                  {type === "repair" && (
                    <p className="px-3.5 py-2.5 text-xs text-mute">Repair charge — no stock movement.</p>
                  )}
                  <div className="flex items-center justify-between gap-3 bg-white px-3.5 py-2.5">
                    <span className="text-[12px] font-bold uppercase tracking-wide text-mute">Total {type === "swap" ? "top-up" : "due"}</span>
                    <span className="font-mono text-base font-bold tabular-nums text-ink">
                      {amountValid ? formatMoney(enteredAmount) : formatMoney(0)}
                    </span>
                  </div>
                </div>
              </div>

              {type === "sale" && belowList && (
                <p className="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-800">
                  Below list price — this sale will be saved for the owner&rsquo;s review before it counts as revenue.
                </p>
              )}
            </div>
          </Section>

          <ErrorNote>{error}</ErrorNote>

          <button type="button" disabled={pending} onClick={submit}
            className={`flex h-12 w-full items-center justify-center gap-2 rounded-xl text-[14px] font-bold tracking-tight transition-all ${
              pending
                ? "cursor-wait bg-line text-mute"
                : "bg-brand text-white shadow-[0_6px_16px_rgba(67,56,202,0.3)] hover:bg-brand-deep hover:shadow-[0_8px_20px_rgba(67,56,202,0.35)] active:translate-y-px"
            }`}>
            {pending ? (
              "Saving…"
            ) : (
              <>
                {saveLabel}
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round">
                  <path d="M5 12h14M12 5l7 7-7 7" />
                </svg>
              </>
            )}
          </button>
        </div>
      </div>
    </div>
  );
}
