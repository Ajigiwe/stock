"use client";

import { useMemo, useState, useTransition } from "react";
import { useRouter } from "next/navigation";
import { recordTransaction } from "@/lib/actions";
import type { Shop, PhoneModel } from "@/lib/data";
import { todayISO } from "@/lib/format";
import { Badge, ErrorNote, Field, Input, Select } from "@/components/ui";
import { ModelPicker } from "@/components/model-picker";
import { useToast } from "@/components/feedback";

type TxType = "sale" | "swap" | "repair";

const PAYMENTS = [
  { value: "cash", label: "Cash" },
  { value: "mobile_money", label: "Mobile money" },
  { value: "card", label: "Card" },
  { value: "bank_transfer", label: "Bank transfer" },
  { value: "other", label: "Other" },
] as const;

const IPHONE_MODELS = [
  "iPhone 7","iPhone 7 Plus","iPhone 8","iPhone 8 Plus",
  "iPhone X","iPhone XR","iPhone XS","iPhone XS Max",
  "iPhone 11","iPhone 11 Pro","iPhone 11 Pro Max",
  "iPhone 12","iPhone 12 mini","iPhone 12 Pro","iPhone 12 Pro Max",
  "iPhone 13","iPhone 13 mini","iPhone 13 Pro","iPhone 13 Pro Max",
  "iPhone 14","iPhone 14 Plus","iPhone 14 Pro","iPhone 14 Pro Max",
  "iPhone 15","iPhone 15 Plus","iPhone 15 Pro","iPhone 15 Pro Max",
  "iPhone 16","iPhone 16 Plus","iPhone 16 Pro","iPhone 16 Pro Max",
  "iPhone SE (2nd gen)","iPhone SE (3rd gen)",
] as const;

type OutLine = { key: number; modelId: string; qty: string };
type SwapLine = { key: number; name: string };
let nextKey = 1;

function StepDots({ step, count }: { step: number; count: number }) {
  return (
    <div className="flex gap-1.5">
      {Array.from({ length: count }).map((_, i) => (
        <div
          key={i}
          className={`h-1 rounded-full transition-all duration-200 ${
            i === step ? "w-6 bg-brand" : i < step ? "w-2.5 bg-brand/40" : "w-2.5 bg-line"
          }`}
        />
      ))}
    </div>
  );
}

function QtyStepper({ value, onInc, onDec }: { value: string; onInc: () => void; onDec: () => void }) {
  const v = Math.max(1, Number(value) || 1);
  return (
    <div className="inline-flex items-center overflow-hidden rounded-[10px] border border-line">
      <button type="button" onClick={onDec}
        className="flex h-[38px] w-9 items-center justify-center bg-paper text-ink transition-colors hover:bg-line/50">−</button>
      <span className="w-9 text-center font-mono text-sm font-bold tabular-nums text-ink">{v}</span>
      <button type="button" onClick={onInc}
        className="flex h-[38px] w-9 items-center justify-center bg-paper text-ink transition-colors hover:bg-line/50">+</button>
    </div>
  );
}

function Section({ title, sub, tone, children, action }: {
  title: string; sub?: string; tone: "out" | "in" | "mid"; children: React.ReactNode; action?: React.ReactNode;
}) {
  const bar = tone === "out" ? "bg-lowstock" : tone === "in" ? "bg-instock" : "bg-brand";
  return (
    <div className="rounded-2xl border border-line bg-white">
      <div className="flex">
        <div className={`w-1 shrink-0 rounded-l-2xl ${bar}`} />
        <div className="flex-1 p-3.5">
          <div className="flex items-start justify-between gap-2">
            <div>
              <div className="text-[13.5px] font-bold text-ink">{title}</div>
              {sub && <div className="mt-0.5 text-[11px] text-mute">{sub}</div>}
            </div>
            {action}
          </div>
          <div className="mt-2.5">{children}</div>
        </div>
      </div>
    </div>
  );
}

function TypeCard({ label, sub, active, onClick }: {
  label: string; sub: string; active: boolean; onClick: () => void;
}) {
  return (
    <button type="button" onClick={onClick}
      className={`flex-1 rounded-xl border-[1.5px] px-3 py-3 text-left transition-colors ${
        active ? "border-brand bg-brand-tint" : "border-line bg-white hover:border-brand/30"
      }`}>
      <div className="text-[13.5px] font-extrabold text-ink">{label}</div>
      <div className="mt-0.5 text-[10.5px] leading-snug text-mute">{sub}</div>
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
  const [rawStep, setRawStep] = useState(0);

  const [type, setType] = useState<TxType>("sale");
  const [shopId, setShopId] = useState(defaultShopId ?? shops[0]?.id ?? "");
  const [customerName, setCustomerName] = useState("");
  const [customerPhone, setCustomerPhone] = useState("");
  const [paymentMethod, setPaymentMethod] = useState<(typeof PAYMENTS)[number]["value"]>("cash");
  const [amount, setAmount] = useState("");
  const [date, setDate] = useState(todayISO());
  const [outLines, setOutLines] = useState<OutLine[]>([{ key: nextKey++, modelId: "", qty: "1" }]);
  const [swapLines, setSwapLines] = useState<SwapLine[]>([{ key: nextKey++, name: "" }]);
  const [savedId, setSavedId] = useState<string | null>(null);

  const shopModels = useMemo(() => stock.filter((m) => m.shop_id === shopId), [stock, shopId]);
  const shopName = shops.find((s) => s.id === shopId)?.name ?? "";
  const validOut = outLines.filter((l) => l.modelId && Number(l.qty) > 0);
  const validSwap = swapLines.filter((l) => l.name.trim());
  const unitsOut = validOut.reduce((a, l) => a + Number(l.qty), 0);

  const suggested = useMemo(() => {
    if (type !== "sale") return null;
    return validOut.reduce((sum, l) => {
      const m = shopModels.find((x) => x.id === l.modelId);
      return sum + (m?.sale_price != null ? m.sale_price * Number(l.qty) : 0);
    }, 0);
  }, [type, validOut, shopModels]);

  const outTotal = useMemo(() =>
    validOut.reduce((sum, l) => {
      const m = shopModels.find((x) => x.id === l.modelId);
      return sum + (m?.sale_price != null ? m.sale_price * Number(l.qty) : 0);
    }, 0), [validOut, shopModels]);

  const steps = useMemo(() => {
    const arr = ["Type"];
    if (type !== "repair") arr.push("Phones");
    arr.push("Customer & pay");
    return arr;
  }, [type]);

  const step = Math.min(rawStep, steps.length - 1);

  const switchShop = (id: string) => {
    setShopId(id);
    setOutLines([{ key: nextKey++, modelId: "", qty: "1" }]);
    setSwapLines([{ key: nextKey++, name: "" }]);
  };

  const resetForm = () => {
    setRawStep(0);
    setType("sale");
    setShopId(defaultShopId ?? shops[0]?.id ?? "");
    setCustomerName("");
    setCustomerPhone("");
    setPaymentMethod("cash");
    setAmount("");
    setDate(todayISO());
    setOutLines([{ key: nextKey++, modelId: "", qty: "1" }]);
    setSwapLines([{ key: nextKey++, name: "" }]);
    setSavedId(null);
    setError(null);
  };

  const stepValid = () => {
    if (step === 0) return true;
    if (step === 1 && type !== "repair") {
      if (validOut.length === 0) return false;
      if (type === "swap" && validSwap.length === 0) return false;
    }
    return true;
  };

  const goNext = () => {
    setError(null);
    if (step === steps.length - 1) return submit();
    if (!stepValid()) {
      if (validOut.length === 0) return setError("Add at least one phone going out.");
      if (type === "swap" && validSwap.length === 0) return setError("Add the old phone the customer is trading in.");
      return;
    }
    setRawStep((s) => s + 1);
  };

  const goBack = () => { setError(null); setRawStep((s) => Math.max(0, s - 1)); };

  const submit = () => {
    setError(null);
    if (type !== "repair" && validOut.length === 0) return setError("Add at least one phone going out.");
    if (type === "swap" && validSwap.length === 0) return setError("Add the old phone the customer is trading in.");
    if (!Number.isFinite(Number(amount)) || Number(amount) < 0) return setError("Enter a valid amount.");
    if (!customerName.trim() || !customerPhone.trim()) return setError("Customer name and phone are required.");
    if (type === "sale" && suggested != null && suggested > 0 && Number(amount) < suggested) {
      return setError(`Sale amount can't be less than the phone price (${suggested.toLocaleString()} GHS).`);
    }

    const outItems = validOut.map((l) => ({ modelId: l.modelId, qty: Number(l.qty) }));
    const swapIn = type === "swap" ? validSwap.map((l) => ({ name: l.name })) : [];

    startTransition(async () => {
      const res = await recordTransaction({ shopId, customerName, customerPhone, type, paymentMethod, amount, date, outItems, swapIn });
      if (!res.ok) { setError(res.error ?? "Failed to record transaction."); return; }
      if (res.warning) toast.error(res.warning);
      else toast.success("Transaction recorded.");
      setSavedId(res.id ?? null);
      if (res.id) router.push(`/transactions/${res.id}`);
      else router.push(`/shops/${shopId}`);
      router.refresh();
    });
  };

  // Success screen
  if (savedId) {
    return (
      <div className="mx-auto flex max-w-2xl flex-col items-center gap-3.5 pb-16 pt-20 text-center">
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

  return (
    <div className="mx-auto flex max-w-2xl flex-col gap-4 pb-24">
      {/* Header + step dots */}
      <div>
        <h1 className="text-xl font-extrabold tracking-tight text-ink">Record transaction</h1>
        <div className="mt-2 flex items-center justify-between">
          <StepDots step={step} count={steps.length} />
          <span className="text-[11px] font-semibold text-mute">
            Step {step + 1} of {steps.length} · {steps[step]}
          </span>
        </div>
      </div>

      {/* Step 0: Type & shop */}
      {step === 0 && (
        <div className="flex flex-col gap-3.5">
          <div className="flex gap-2">
            <TypeCard label="Sale" sub="Phone leaves the shop" active={type === "sale"} onClick={() => setType("sale")} />
            <TypeCard label="Swap" sub="Out + trade-in + top-up" active={type === "swap"} onClick={() => setType("swap")} />
            <TypeCard label="Repair" sub="Logged for service" active={type === "repair"} onClick={() => setType("repair")} />
          </div>
          {isOwner && (
            <div>
              <Field label="Shop">
                <Select value={shopId} onChange={(e) => switchShop(e.target.value)}>
                  {shops.map((s) => (<option key={s.id} value={s.id}>{s.name}</option>))}
                </Select>
              </Field>
            </div>
          )}
        </div>
      )}

      {/* Step 1: Phones */}
      {step === 1 && type !== "repair" && (
        <div className="flex flex-col gap-3">
          <Section
            title="Phones going out"
            sub="These leave the shop's stock"
            tone="out"
            action={
              <button type="button" onClick={() => setOutLines((ls) => [...ls, { key: nextKey++, modelId: "", qty: "1" }])}
                className="rounded-lg bg-brand-tint px-2.5 py-1 text-xs font-bold text-brand transition-colors hover:bg-brand/10">
                + Add
              </button>
            }
          >
            <div className="space-y-2.5">
              {outLines.map((line) => {
                const model = shopModels.find((m) => m.id === line.modelId);
                const low = model != null && model.available <= model.low_stock_threshold;
                return (
                  <div key={line.key} className="rounded-xl border border-line bg-paper p-3">
                    <div className="flex items-end gap-2">
                      <div className="flex-1">
                        <Field label="Phone model">
                          <ModelPicker models={shopModels} value={line.modelId} showStock
                            onChange={(id) => setOutLines((ls) => ls.map((l) => l.key === line.key ? { ...l, modelId: id } : l))} />
                        </Field>
                      </div>
                      <QtyStepper value={line.qty}
                        onInc={() => setOutLines((ls) => ls.map((l) => l.key === line.key ? { ...l, qty: String(Number(l.qty) + 1) } : l))}
                        onDec={() => setOutLines((ls) => ls.map((l) => l.key === line.key ? { ...l, qty: String(Math.max(1, Number(l.qty) - 1)) } : l))} />
                      <button type="button" aria-label="Remove phone"
                        onClick={() => setOutLines((ls) => ls.length > 1 ? ls.filter((l) => l.key !== line.key) : [{ key: nextKey++, modelId: "", qty: "1" }])}
                        className="mb-0.5 inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-lg text-mute transition-colors hover:bg-lowstock-tint hover:text-lowstock">
                        ✕
                      </button>
                    </div>
                    {model && (
                      <p className="mt-2 text-xs">
                        <Badge tone={model.condition === "new" ? "blue" : "gray"}>{model.condition}</Badge>{" "}
                        {model.sale_price != null && <span className="text-mute">Sale {model.sale_price.toLocaleString()} GHS · </span>}
                        <span className={low ? "text-lowstock" : "text-mute"}>{model.available} in stock</span>
                        {low && <span className="text-lowstock"> · low!</span>}
                      </p>
                    )}
                  </div>
                );
              })}
            </div>
            {validOut.length > 0 && outTotal > 0 && (
              <div className="mt-3 flex items-center justify-between rounded-xl border border-line bg-white px-3 py-2 text-[13px]">
                <span className="text-mute">Total value going out</span>
                <span className="font-mono font-semibold tabular-nums text-ink">{outTotal.toLocaleString()} GHS</span>
              </div>
            )}
          </Section>

          {type === "swap" && (
            <Section
              title="Old iPhone received (trade-in)"
              sub="Pick the model — no need to enter its details"
              tone="in"
              action={
                <button type="button" onClick={() => setSwapLines((ls) => [...ls, { key: nextKey++, name: "" }])}
                  className="rounded-lg bg-instock-tint px-2.5 py-1 text-xs font-bold text-instock transition-colors hover:bg-instock/10">
                  + Add
                </button>
              }
            >
              <div className="space-y-2.5">
                {swapLines.map((line) => (
                  <div key={line.key} className="rounded-xl border border-line bg-paper p-3">
                    <div className="flex items-end gap-2">
                      <div className="flex-1">
                        <Field label="iPhone model">
                          <Select value={line.name} onChange={(e) => setSwapLines((ls) => ls.map((l) => l.key === line.key ? { ...l, name: e.target.value } : l))}>
                            <option value="">Select iPhone model…</option>
                            {IPHONE_MODELS.map((m) => (<option key={m} value={m}>{m}</option>))}
                          </Select>
                        </Field>
                      </div>
                      <button type="button" aria-label="Remove"
                        onClick={() => setSwapLines((ls) => ls.length > 1 ? ls.filter((l) => l.key !== line.key) : [{ key: nextKey++, name: "" }])}
                        className="mb-0.5 inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-lg text-mute transition-colors hover:bg-lowstock-tint hover:text-lowstock">
                        ✕
                      </button>
                    </div>
                  </div>
                ))}
              </div>
            </Section>
          )}
        </div>
      )}

      {step === 1 && type === "repair" && (
        <div className="rounded-2xl border border-line bg-white p-4 text-sm text-mute">
          Repairs are service-only: the phone comes in and goes back with the customer. No stock moves — only the charge is recorded.
        </div>
      )}

      {/* Step 2: Customer & pay */}
      {step === steps.length - 1 && (
        <div className="flex flex-col gap-3">
          <Section title="Customer" tone="mid">
            <div className="flex flex-col gap-2.5 sm:flex-row">
              <div className="flex-1">
                <Field label="Name" required>
                  <Input value={customerName} onChange={(e) => setCustomerName(e.target.value)} placeholder="Customer name" />
                </Field>
              </div>
              <div className="flex-1">
                <Field label="Phone" required>
                  <Input value={customerPhone} onChange={(e) => setCustomerPhone(e.target.value)} placeholder="Customer phone" />
                </Field>
              </div>
            </div>
          </Section>

          <Section title="Payment" sub={type === "swap" ? "Top-up cash (GHS)" : type === "repair" ? "Repair charge (GHS)" : "Total sale amount (GHS)"} tone="mid">
            <div className="flex flex-col gap-2.5">
              <div className="relative">
                <span className="absolute left-3 top-1/2 -translate-y-1/2 font-mono text-[13px] font-bold text-ledger">GHS</span>
                <Input type="number" min="0" step="0.01" placeholder="0.00" value={amount}
                  onChange={(e) => setAmount(e.target.value)}
                  className="!pl-11 font-mono font-bold text-ink" />
              </div>
              {suggested != null && suggested > 0 && (
                <button type="button" onClick={() => setAmount(String(suggested))}
                  className="self-start text-xs font-bold text-brand underline">
                  Suggested {suggested.toLocaleString()} GHS — use
                </button>
              )}
              <div className="grid gap-2.5 sm:grid-cols-2">
                <Field label="Payment method">
                  <Select value={paymentMethod} onChange={(e) => setPaymentMethod(e.target.value as typeof paymentMethod)}>
                    {PAYMENTS.map((p) => (<option key={p.value} value={p.value}>{p.label}</option>))}
                  </Select>
                </Field>
                <Field label="Date">
                  <Input type="date" value={date} onChange={(e) => setDate(e.target.value)} />
                </Field>
              </div>
            </div>
          </Section>

          {/* Summary */}
          <div className="rounded-2xl border border-dashed border-line bg-paper px-3.5 py-2.5 text-[11.5px] text-mute">
            <span>Out: <b className="text-ink">{unitsOut}</b> × {validOut.map((l) => shopModels.find((m) => m.id === l.modelId)?.model_name).filter(Boolean).join(", ") || "—"}</span>
            {type === "swap" && <span className="ml-3">Trade-in: <b className="text-ink">{validSwap.length}</b></span>}
          </div>
        </div>
      )}

      <ErrorNote>{error}</ErrorNote>

      {/* Navigation */}
      <div className="flex gap-2.5">
        {step > 0 && (
          <button type="button" onClick={goBack}
            className="flex h-11 w-11 shrink-0 items-center justify-center rounded-[10px] border border-line bg-white text-ink transition-colors hover:bg-paper">
            ←
          </button>
        )}
        <button type="button" disabled={pending} onClick={goNext}
          className={`flex h-11 flex-1 items-center justify-center gap-1.5 rounded-[10px] text-[13.5px] font-bold transition-colors ${
            pending ? "cursor-wait bg-line text-mute" : "bg-brand text-white hover:bg-brand-deep"
          }`}>
          {step === steps.length - 1 ? (pending ? "Saving…" : "Save transaction") : "Continue →"}
        </button>
      </div>
    </div>
  );
}