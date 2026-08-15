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

type OutLine = { key: number; modelId: string; qty: string };
type InLine = {
  key: number;
  mode: "existing" | "new";
  modelId: string;
  name: string;
  costPrice: string;
  salePrice: string;
  qty: string;
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
  const [inLines, setInLines] = useState<InLine[]>([
    { key: nextKey++, mode: "new", modelId: "", name: "", costPrice: "", salePrice: "", qty: "1" },
  ]);

  const shopModels = useMemo(
    () => stock.filter((m) => m.shop_id === shopId),
    [stock, shopId],
  );

  const validOut = outLines.filter(
    (l) => l.modelId && Number(l.qty) > 0,
  );
  const validIn = inLines.filter((l) =>
    l.mode === "existing"
      ? l.modelId && Number(l.qty) > 0
      : l.name.trim() && Number(l.qty) > 0,
  );
  const unitsOut = validOut.reduce((a, l) => a + Number(l.qty), 0);
  const unitsIn = validIn.reduce((a, l) => a + Number(l.qty), 0);

  const suggested = useMemo(() => {
    if (type !== "sale") return null;
    return validOut.reduce((sum, l) => {
      const m = shopModels.find((x) => x.id === l.modelId);
      return sum + (m?.sale_price != null ? m.sale_price * Number(l.qty) : 0);
    }, 0);
  }, [type, validOut, shopModels]);

  const amountLabel =
    type === "sale"
      ? "Total sale amount (GHS)"
      : type === "swap"
        ? "Top-up cash (GHS)"
        : "Repair charge (GHS)";

  const switchShop = (id: string) => {
    setShopId(id);
    setOutLines([{ key: nextKey++, modelId: "", qty: "1" }]);
    setInLines([{ key: nextKey++, mode: "new", modelId: "", name: "", costPrice: "", salePrice: "", qty: "1" }]);
  };

  const submit = () => {
    setError(null);

    if (type !== "repair" && validOut.length === 0) {
      return setError("Add at least one phone going out.");
    }
    if (type === "swap" && validIn.length === 0) {
      return setError("Add at least one phone coming in for the swap.");
    }
    if (!Number.isFinite(Number(amount)) || Number(amount) < 0) {
      return setError("Enter a valid amount.");
    }

    const outItems = validOut.map((l) => ({
      modelId: l.modelId,
      qty: Number(l.qty),
    }));
    const inItems = validIn.map((l) =>
      l.mode === "existing"
        ? { mode: "existing" as const, modelId: l.modelId, qty: Number(l.qty) }
        : {
            mode: "new" as const,
            name: l.name,
            costPrice: l.costPrice,
            salePrice: l.salePrice,
            qty: Number(l.qty),
          },
    );

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
        inItems,
      });
      if (!res.ok) {
        setError(res.error ?? "Failed to record transaction.");
        return;
      }
      toast.success("Transaction recorded.");
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
        </Card>
      )}

      {type === "swap" && (
        <Card
          title="Phones coming in"
          subtitle="These enter the shop's stock"
          actions={
            <Button
              type="button"
              onClick={() =>
                setInLines((ls) => [
                  ...ls,
                  { key: nextKey++, mode: "new", modelId: "", name: "", costPrice: "", salePrice: "", qty: "1" },
                ])
              }
              className="h-8 px-3 text-xs"
            >
              + Add phone
            </Button>
          }
        >
          <div className="space-y-3">
            {inLines.map((line) => (
              <div
                key={line.key}
                className="rounded-lg border border-zinc-200 bg-zinc-50 p-3"
              >
                <div className="mb-2 flex items-center justify-between">
                  <span className="text-xs font-medium text-zinc-500">Trade-in phone</span>
                  <div className="flex gap-1">
                    <button
                      type="button"
                      onClick={() =>
                        setInLines((ls) =>
                          ls.map((l) =>
                            l.key === line.key ? { ...l, mode: "new" as const } : l,
                          ),
                        )
                      }
                      className={`rounded-md px-2 py-1 text-xs font-medium ${
                        line.mode === "new"
                          ? "bg-zinc-900 text-white"
                          : "bg-zinc-200 text-zinc-700"
                      }`}
                    >
                      New model
                    </button>
                    <button
                      type="button"
                      onClick={() =>
                        setInLines((ls) =>
                          ls.map((l) =>
                            l.key === line.key ? { ...l, mode: "existing" as const } : l,
                          ),
                        )
                      }
                      className={`rounded-md px-2 py-1 text-xs font-medium ${
                        line.mode === "existing"
                          ? "bg-zinc-900 text-white"
                          : "bg-zinc-200 text-zinc-700"
                      }`}
                    >
                      Already stocked
                    </button>
                  </div>
                </div>

                {line.mode === "existing" ? (
                  <div className="flex items-end gap-2">
                    <div className="flex-1">
                      <Field label="Phone model">
                        <ModelPicker
                          models={shopModels}
                          value={line.modelId}
                          showStock={false}
                          onChange={(id) =>
                            setInLines((ls) =>
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
                            setInLines((ls) =>
                              ls.map((l) =>
                                l.key === line.key ? { ...l, qty: e.target.value } : l,
                              ),
                            )
                          }
                        />
                      </Field>
                    </div>
                  </div>
                ) : (
                  <div className="space-y-3">
                    <div className="flex items-end gap-2">
                      <div className="flex-1">
                        <Field label="Model name">
                          <Input
                            value={line.name}
                            onChange={(e) =>
                              setInLines((ls) =>
                                ls.map((l) =>
                                  l.key === line.key ? { ...l, name: e.target.value } : l,
                                ),
                              )
                            }
                            placeholder='e.g. "iPhone 11 64GB"'
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
                              setInLines((ls) =>
                                ls.map((l) =>
                                  l.key === line.key ? { ...l, qty: e.target.value } : l,
                                ),
                              )
                            }
                          />
                        </Field>
                      </div>
                    </div>
                    <div className="grid grid-cols-2 gap-3">
                      <Field label="Trade-in value (GHS)">
                        <Input
                          type="number"
                          value={line.costPrice}
                          onChange={(e) =>
                            setInLines((ls) =>
                              ls.map((l) =>
                                l.key === line.key
                                  ? { ...l, costPrice: e.target.value }
                                  : l,
                              ),
                            )
                          }
                          placeholder="what you valued it at"
                        />
                      </Field>
                      <Field label="Asking price (GHS)">
                        <Input
                          type="number"
                          value={line.salePrice}
                          onChange={(e) =>
                            setInLines((ls) =>
                              ls.map((l) =>
                                l.key === line.key
                                  ? { ...l, salePrice: e.target.value }
                                  : l,
                              ),
                            )
                          }
                          placeholder="optional"
                        />
                      </Field>
                    </div>
                  </div>
                )}

                <div className="mt-2 flex justify-end">
                  <button
                    type="button"
                    aria-label="Remove phone"
                    onClick={() =>
                      setInLines((ls) =>
                        ls.length > 1
                          ? ls.filter((l) => l.key !== line.key)
                          : [{ key: nextKey++, mode: "new", modelId: "", name: "", costPrice: "", salePrice: "", qty: "1" }],
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
          <Field label="Name">
            <Input
              value={customerName}
              onChange={(e) => setCustomerName(e.target.value)}
              placeholder="optional"
            />
          </Field>
          <Field label="Phone">
            <Input
              value={customerPhone}
              onChange={(e) => setCustomerPhone(e.target.value)}
              placeholder="optional"
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
              In: <b className="text-zinc-900">{unitsIn}</b>
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