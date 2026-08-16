"use client";

import { useMemo, useState, useTransition } from "react";
import { useRouter } from "next/navigation";
import { recordTransaction } from "@/lib/actions";
import type { Shop, PhoneModel } from "@/lib/data";
import { todayISO } from "@/lib/format";
import {
  Badge,
  Button,
  Card,
  ErrorNote,
  Field,
  Input,
  Select,
} from "@/components/ui";
import { ModelPicker } from "@/components/model-picker";
import { useToast } from "@/components/feedback";

const TYPES = ["sale", "swap", "repair"] as const;
type TxType = (typeof TYPES)[number];
const TYPE_META: Record<TxType, { label: string; hint: string }> = {
  sale: { label: "Sale", hint: "Phone leaves the shop" },
  swap: { label: "Swap", hint: "Phone out, another in + top-up" },
  repair: { label: "Repair", hint: "Service only — stock unchanged" },
};

const PAYMENTS = [
  { value: "cash", label: "Cash" },
  { value: "mobile_money", label: "Mobile money" },
  { value: "card", label: "Card" },
  { value: "bank_transfer", label: "Bank transfer" },
  { value: "other", label: "Other" },
] as const;

// Trade-ins are iPhones only — picked from this list, not free text.
const IPHONE_MODELS = [
  "iPhone 7",
  "iPhone 7 Plus",
  "iPhone 8",
  "iPhone 8 Plus",
  "iPhone X",
  "iPhone XR",
  "iPhone XS",
  "iPhone XS Max",
  "iPhone 11",
  "iPhone 11 Pro",
  "iPhone 11 Pro Max",
  "iPhone 12",
  "iPhone 12 mini",
  "iPhone 12 Pro",
  "iPhone 12 Pro Max",
  "iPhone 13",
  "iPhone 13 mini",
  "iPhone 13 Pro",
  "iPhone 13 Pro Max",
  "iPhone 14",
  "iPhone 14 Plus",
  "iPhone 14 Pro",
  "iPhone 14 Pro Max",
  "iPhone 15",
  "iPhone 15 Plus",
  "iPhone 15 Pro",
  "iPhone 15 Pro Max",
  "iPhone 16",
  "iPhone 16 Plus",
  "iPhone 16 Pro",
  "iPhone 16 Pro Max",
  "iPhone SE (2nd gen)",
  "iPhone SE (3rd gen)",
] as const;

type OutLine = { key: number; modelId: string; qty: string };
type SwapLine = {
  key: number;
  name: string;
};

let nextKey = 1;

export function TransactionForm({
  shops,
  stock,
  defaultShopId,
  isOwner,
}: {
  shops: Shop[];
  stock: PhoneModel[];
  defaultShopId?: string;
  isOwner: boolean;
}) {
  const router = useRouter();
  const toast = useToast();
  const [pending, startTransition] = useTransition();
  const [error, setError] = useState<string | null>(null);

  const [type, setType] = useState<TxType>("sale");
  const [shopId, setShopId] = useState(defaultShopId ?? shops[0]?.id ?? "");
  const [customerName, setCustomerName] = useState("");
  const [customerPhone, setCustomerPhone] = useState("");
  const [paymentMethod, setPaymentMethod] =
    useState<(typeof PAYMENTS)[number]["value"]>("cash");
  const [amount, setAmount] = useState("");
  const [date, setDate] = useState(todayISO());

  const [outLines, setOutLines] = useState<OutLine[]>([
    { key: nextKey++, modelId: "", qty: "1" },
  ]);
  const [swapLines, setSwapLines] = useState<SwapLine[]>([
    { key: nextKey++, name: "" },
  ]);

  const shopModels = useMemo(
    () => stock.filter((m) => m.shop_id === shopId),
    [stock, shopId],
  );

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

  // Total sticker value of the phones going out — shown on sales and swaps.
  const outTotal = useMemo(() => {
    return validOut.reduce((sum, l) => {
      const m = shopModels.find((x) => x.id === l.modelId);
      return sum + (m?.sale_price != null ? m.sale_price * Number(l.qty) : 0);
    }, 0);
  }, [validOut, shopModels]);

  const amountLabel =
    type === "sale"
      ? "Total sale amount (GHS)"
      : type === "swap"
        ? "Top-up cash (GHS)"
        : "Repair charge (GHS)";

  const switchShop = (id: string) => {
    setShopId(id);
    setOutLines([{ key: nextKey++, modelId: "", qty: "1" }]);
    setSwapLines([{ key: nextKey++, name: "" }]);
  };

  const submit = () => {
    setError(null);

    if (type !== "repair" && validOut.length === 0) {
      return setError("Add at least one phone going out.");
    }
    if (type === "swap" && validSwap.length === 0) {
      return setError("Add the old phone the customer is trading in.");
    }
    if (!Number.isFinite(Number(amount)) || Number(amount) < 0) {
      return setError("Enter a valid amount.");
    }
    if (!customerName.trim() || !customerPhone.trim()) {
      return setError("Customer name and phone are required.");
    }
    if (type === "sale" && suggested != null && suggested > 0 && Number(amount) < suggested) {
      return setError(
        `Sale amount can't be less than the phone price (${suggested.toLocaleString()} GHS).`,
      );
    }

    const outItems = validOut.map((l) => ({
      modelId: l.modelId,
      qty: Number(l.qty),
    }));
    const swapIn =
      type === "swap"
        ? validSwap.map((l) => ({
            name: l.name,
          }))
        : [];

    startTransition(async () => {
      const res = await recordTransaction({
        shopId,
        customerName,
        customerPhone,
        type,
        paymentMethod,
        amount,
        date,
        outItems,
        swapIn,
      });
      if (!res.ok) {
        setError(res.error ?? "Failed to record transaction.");
        return;
      }
      if (res.warning) toast.error(res.warning);
      else toast.success("Transaction recorded.");
      if (res.id) router.push(`/transactions/${res.id}`);
      else router.push(`/shops/${shopId}`);
      router.refresh();
    });
  };

  return (
    <div className="mx-auto max-w-2xl space-y-4">
      <div>
        <h1 className="text-xl font-bold text-zinc-900">Record transaction</h1>
        <p className="text-sm text-zinc-500">Sales, swaps and repairs</p>
      </div>

      <Card title="What are you recording?">
        <div className="grid grid-cols-3 gap-1 rounded-xl bg-zinc-100 p-1">
          {TYPES.map((t) => (
            <button
              key={t}
              type="button"
              onClick={() => setType(t)}
              className={`rounded-lg py-2 text-sm font-semibold transition-colors ${
                type === t
                  ? "bg-white text-zinc-900 shadow-sm"
                  : "text-zinc-500 hover:text-zinc-700"
              }`}
            >
              {TYPE_META[t].label}
            </button>
          ))}
        </div>
        <p className="mt-2 text-xs text-zinc-500">{TYPE_META[type].hint}</p>

        {isOwner && (
          <div className="mt-3">
            <Field label="Shop">
              <Select value={shopId} onChange={(e) => switchShop(e.target.value)}>
                {shops.map((s) => (
                  <option key={s.id} value={s.id}>
                    {s.name}
                  </option>
                ))}
              </Select>
            </Field>
          </div>
        )}
      </Card>

      {type !== "repair" && (
        <Card
          title="Phones going out"
          subtitle="These leave the shop's stock"
          actions={
            <Button
              type="button"
              onClick={() =>
                setOutLines((ls) => [
                  ...ls,
                  { key: nextKey++, modelId: "", qty: "1" },
                ])
              }
              className="h-8 px-3 text-xs"
            >
              + Add phone
            </Button>
          }
        >
          <div className="space-y-3">
            {outLines.map((line) => {
              const model = shopModels.find((m) => m.id === line.modelId);
              const low = model != null && model.available <= model.low_stock_threshold;
              return (
                <div
                  key={line.key}
                  className="rounded-lg border border-zinc-200 bg-zinc-50 p-3"
                >
                  <div className="flex items-end gap-2">
                    <div className="flex-1">
                      <Field label="Phone model">
                        <ModelPicker
                          models={shopModels}
                          value={line.modelId}
                          showStock
                          onChange={(id) =>
                            setOutLines((ls) =>
                              ls.map((l) =>
                                l.key === line.key ? { ...l, modelId: id } : l,
                              ),
                            )
                          }
                        />
                      </Field>
                    </div>
                    <div className="w-20">
                      <Field label="Qty">
                        <Input
                          type="number"
                          min="1"
                          value={line.qty}
                          onChange={(e) =>
                            setOutLines((ls) =>
                              ls.map((l) =>
                                l.key === line.key ? { ...l, qty: e.target.value } : l,
                              ),
                            )
                          }
                        />
                      </Field>
                    </div>
                    <button
                      type="button"
                      aria-label="Remove phone"
                      onClick={() =>
                        setOutLines((ls) =>
                          ls.length > 1
                            ? ls.filter((l) => l.key !== line.key)
                            : [{ key: nextKey++, modelId: "", qty: "1" }],
                        )
                      }
                      className="mb-0.5 inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-lg text-zinc-400 transition-colors hover:bg-red-50 hover:text-red-600"
                    >
                      ✕
                    </button>
                  </div>
                  {model && (
                    <p className="mt-2 text-xs">
                      <Badge tone={model.condition === "new" ? "blue" : "gray"}>
                        {model.condition}
                      </Badge>{" "}
                      {model.sale_price != null && (
                        <span className="text-zinc-600">
                          Sale {model.sale_price.toLocaleString()} GHS ·{" "}
                        </span>
                      )}
                      <span className={low ? "text-red-600" : "text-zinc-500"}>
                        {model.available} in stock
                      </span>
                      {low && <span className="text-red-600"> · low stock!</span>}
                    </p>
                  )}
                </div>
              );
            })}
          </div>
          {validOut.length > 0 && outTotal > 0 && (
            <div className="flex items-center justify-between rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm">
              <span className="text-zinc-500">Total value going out</span>
              <span className="font-semibold text-zinc-900">
                {outTotal.toLocaleString()} GHS
              </span>
            </div>
          )}
        </Card>
      )}

{type === "swap" && (
        <Card
          title="Old iPhone received (trade-in)"
          subtitle="Pick the model — no need to enter its details"
          actions={
            <Button
              type="button"
              onClick={() =>
                setSwapLines((ls) => [...ls, { key: nextKey++, name: "" }])
              }
              className="h-8 px-3 text-xs"
            >
              + Add iPhone
            </Button>
          }
        >
          <div className="space-y-3">
            {swapLines.map((line) => (
              <div
                key={line.key}
                className="rounded-lg border border-zinc-200 bg-zinc-50 p-3"
              >
                <Field label="iPhone model">
                  <Select
                    value={line.name}
                    onChange={(e) =>
                      setSwapLines((ls) =>
                        ls.map((l) =>
                          l.key === line.key ? { ...l, name: e.target.value } : l,
                        ),
                      )
                    }
                  >
                    <option value="">Select iPhone model…</option>
                    {IPHONE_MODELS.map((m) => (
                      <option key={m} value={m}>
                        {m}
                      </option>
                    ))}
                  </Select>
                </Field>

                <div className="mt-2 flex justify-end">
                  <button
                    type="button"
                    aria-label="Remove phone"
                    onClick={() =>
                      setSwapLines((ls) =>
                        ls.length > 1
                          ? ls.filter((l) => l.key !== line.key)
                          : [{ key: nextKey++, name: "" }],
                      )
                    }
                    className="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-medium text-zinc-400 transition-colors hover:bg-red-50 hover:text-red-600"
                  >
                    ✕ Remove
                  </button>
                </div>
              </div>
            ))}
          </div>
          <p className="mt-3 text-xs text-zinc-500">
            The new iPhone the customer takes goes under{" "}
            <span className="font-medium text-zinc-700">Phones going out</span>{" "}
            above; the top-up cash goes under{" "}
            <span className="font-medium text-zinc-700">Payment</span>.
          </p>
        </Card>
      )}

      {type === "repair" && (
        <div className="rounded-xl border border-sky-200 bg-sky-50 p-4 text-sm text-sky-800">
          Repairs are service-only: the phone comes in and goes back with the
          customer. No stock moves — only the charge is recorded.
        </div>
      )}

      <Card title="Customer">
        <div className="grid gap-3 sm:grid-cols-2">
          <Field label="Name" required>
            <Input
              value={customerName}
              onChange={(e) => setCustomerName(e.target.value)}
              placeholder="Customer name"
            />
          </Field>
          <Field label="Phone" required>
            <Input
              value={customerPhone}
              onChange={(e) => setCustomerPhone(e.target.value)}
              placeholder="Customer phone"
            />
          </Field>
        </div>
      </Card>

      <Card title="Payment">
        <div className="grid gap-3 sm:grid-cols-3">
          <Field label={amountLabel}>
            <Input
              type="number"
              min="0"
              step="0.01"
              value={amount}
              onChange={(e) => setAmount(e.target.value)}
              placeholder="0.00"
            />
            {suggested != null && suggested > 0 && (
              <button
                type="button"
                onClick={() => setAmount(String(suggested))}
                className="mt-1 text-xs font-medium text-zinc-900 underline"
              >
                Σ suggested {suggested.toLocaleString()} GHS — use
              </button>
            )}
          </Field>
          <Field label="Payment method">
            <Select
              value={paymentMethod}
              onChange={(e) =>
                setPaymentMethod(e.target.value as typeof paymentMethod)
              }
            >
              {PAYMENTS.map((p) => (
                <option key={p.value} value={p.value}>
                  {p.label}
                </option>
              ))}
            </Select>
          </Field>
          <Field label="Date">
            <Input type="date" value={date} onChange={(e) => setDate(e.target.value)} />
          </Field>
        </div>
      </Card>

      <ErrorNote>{error}</ErrorNote>

      <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-zinc-200 bg-white p-4 shadow-sm">
        <div className="text-sm text-zinc-600">
          {type !== "repair" && (
            <span className="mr-3">
              Out: <b className="text-zinc-900">{unitsOut}</b>
            </span>
          )}
          {type === "swap" && (
            <span>
              Trade-in:{" "}
              <b className="text-zinc-900">
                {validSwap.length} phone{validSwap.length === 1 ? "" : "s"}
              </b>
            </span>
          )}
          {unitsOut === 0 && type !== "repair" && (
            <span className="text-zinc-400">Nothing selected yet.</span>
          )}
        </div>
        <Button
          type="button"
          disabled={pending}
          onClick={submit}
          className="min-w-40"
        >
          {pending ? "Saving…" : "Save transaction"}
        </Button>
      </div>
    </div>
  );
}