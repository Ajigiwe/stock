"use client";

import { useMemo, useState, useTransition } from "react";
import { useRouter } from "next/navigation";
import { recordTransaction } from "@/lib/actions";
import type { Shop, PhoneModel } from "@/lib/data";
import { todayISO } from "@/lib/format";
import { Button, Card, ErrorNote, Field, Input, Label, Select } from "@/components/ui";

type FormProps = {
  shops: Shop[];
  stock: PhoneModel[];
  defaultShopId?: string;
  isOwner: boolean;
};

export function TransactionForm({ shops, stock, defaultShopId, isOwner }: FormProps) {
  const router = useRouter();
  const [pending, startTransition] = useTransition();
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState(false);

  const [type, setType] = useState<"sale" | "swap" | "repair">("sale");
  const [shopId, setShopId] = useState(defaultShopId ?? shops[0]?.id ?? "");
  const [customerName, setCustomerName] = useState("");
  const [customerPhone, setCustomerPhone] = useState("");
  const [paymentMethod, setPaymentMethod] = useState<"cash" | "mobile_money" | "card" | "bank_transfer" | "other">("cash");
  const [amount, setAmount] = useState("");
  const [date, setDate] = useState(todayISO());

  const [outModelId, setOutModelId] = useState("");
  const [outQty, setOutQty] = useState("1");

  const [inMode, setInMode] = useState<"existing" | "new">("new");
  const [inModelId, setInModelId] = useState("");
  const [inModelName, setInModelName] = useState("");
  const [inCostPrice, setInCostPrice] = useState("");
  const [inSalePrice, setInSalePrice] = useState("");
  const [inQty, setInQty] = useState("1");

  const shopModels = useMemo(() => stock.filter((m) => m.shop_id === shopId), [stock, shopId]);

  const amountLabel =
    type === "sale"
      ? "Sale price (GHS)"
      : type === "swap"
        ? "Top-up cash (GHS)"
        : "Repair charge (GHS)";

  const submit = () => {
    startTransition(async () => {
      setError(null);
      const res = await recordTransaction({
        shopId,
        customerName,
        customerPhone,
        type,
        paymentMethod,
        amount,
        date,
        outModelId: type !== "repair" ? outModelId : undefined,
        outQty: type !== "repair" ? Number(outQty) : undefined,
        inModelId: type === "swap" && inMode === "existing" ? inModelId : undefined,
        inModelName: type === "swap" && inMode === "new" ? inModelName : undefined,
        inCostPrice: inMode === "new" ? inCostPrice : undefined,
        inSalePrice: inMode === "new" ? inSalePrice : undefined,
        inQty: type === "swap" ? Number(inQty) : undefined,
      });
      if (!res.ok) {
        setError(res.error ?? "Failed to record transaction.");
        return;
      }
      setSuccess(true);
      router.push(`/shops/${shopId}`);
      router.refresh();
    });
  };

  return (
    <div className="mx-auto max-w-2xl">
      <Card title="Record transaction" subtitle="Sales, swaps and repairs">
        <div className="space-y-4">
          <div className="grid gap-3 sm:grid-cols-2">
            <Field label="Type">
              <Select value={type} onChange={(e) => setType(e.target.value as typeof type)}>
                <option value="sale">Sale</option>
                <option value="swap">Swap</option>
                <option value="repair">Repair</option>
              </Select>
            </Field>
            {isOwner ? (
              <Field label="Shop">
                <Select value={shopId} onChange={(e) => setShopId(e.target.value)}>
                  {shops.map((s) => (
                    <option key={s.id} value={s.id}>
                      {s.name}
                    </option>
                  ))}
                </Select>
              </Field>
            ) : (
              <div className="hidden" aria-hidden>
                <Input value={shopId} readOnly />
              </div>
            )}
          </div>

          <div className="grid gap-3 sm:grid-cols-2">
            <Field label="Customer name">
              <Input value={customerName} onChange={(e) => setCustomerName(e.target.value)} placeholder="optional" />
            </Field>
            <Field label="Customer phone">
              <Input value={customerPhone} onChange={(e) => setCustomerPhone(e.target.value)} placeholder="optional" />
            </Field>
          </div>

          <div className="grid gap-3 sm:grid-cols-3">
            <Field label={amountLabel}>
              <Input type="number" min="0" value={amount} onChange={(e) => setAmount(e.target.value)} placeholder="0.00" />
            </Field>
            <Field label="Payment method">
              <Select
                value={paymentMethod}
                onChange={(e) => setPaymentMethod(e.target.value as typeof paymentMethod)}
              >
                <option value="cash">Cash</option>
                <option value="mobile_money">Mobile money</option>
                <option value="card">Card</option>
                <option value="bank_transfer">Bank transfer</option>
                <option value="other">Other</option>
              </Select>
            </Field>
            <Field label="Date">
              <Input type="date" value={date} onChange={(e) => setDate(e.target.value)} />
            </Field>
          </div>

          {type !== "repair" && (
            <div className="rounded-lg border border-zinc-200 bg-zinc-50 p-3">
              <Label>Phone going out (leaves shop stock)</Label>
              <div className="mt-1 grid gap-3 sm:grid-cols-2">
                <Select value={outModelId} onChange={(e) => setOutModelId(e.target.value)}>
                  <option value="">Select model…</option>
                  {shopModels.map((m) => (
                    <option key={m.id} value={m.id}>
                      {m.model_name} ({m.condition}, {m.available} in stock)
                    </option>
                  ))}
                </Select>
                <Input type="number" min="1" value={outQty} onChange={(e) => setOutQty(e.target.value)} placeholder="Qty" />
              </div>
            </div>
          )}

          {type === "swap" && (
            <div className="rounded-lg border border-zinc-200 bg-zinc-50 p-3">
              <div className="mb-2 flex items-center justify-between">
                <Label>Phone coming in (enters shop stock)</Label>
                <div className="flex gap-1">
                  <button
                    type="button"
                    onClick={() => setInMode("new")}
                    className={`rounded-md px-2 py-1 text-xs font-medium ${
                      inMode === "new" ? "bg-zinc-900 text-white" : "bg-zinc-200 text-zinc-700"
                    }`}
                  >
                    New model
                  </button>
                  <button
                    type="button"
                    onClick={() => setInMode("existing")}
                    className={`rounded-md px-2 py-1 text-xs font-medium ${
                      inMode === "existing" ? "bg-zinc-900 text-white" : "bg-zinc-200 text-zinc-700"
                    }`}
                  >
                    Existing
                  </button>
                </div>
              </div>

              {inMode === "existing" ? (
                <div className="grid gap-3 sm:grid-cols-2">
                  <Select value={inModelId} onChange={(e) => setInModelId(e.target.value)}>
                    <option value="">Select model…</option>
                    {shopModels.map((m) => (
                      <option key={m.id} value={m.id}>
                        {m.model_name} ({m.condition})
                      </option>
                    ))}
                  </Select>
                  <Input type="number" min="1" value={inQty} onChange={(e) => setInQty(e.target.value)} placeholder="Qty" />
                </div>
              ) : (
                <div className="space-y-3">
                  <div className="grid gap-3 sm:grid-cols-2">
                    <Field label="Model name">
                      <Input
                        value={inModelName}
                        onChange={(e) => setInModelName(e.target.value)}
                        placeholder='e.g. "iPhone 11 64GB" (used)'
                      />
                    </Field>
                    <Field label="Qty">
                      <Input type="number" min="1" value={inQty} onChange={(e) => setInQty(e.target.value)} />
                    </Field>
                  </div>
                  <div className="grid gap-3 sm:grid-cols-2">
                    <Field label="Trade-in value (GHS)">
                      <Input type="number" value={inCostPrice} onChange={(e) => setInCostPrice(e.target.value)} placeholder="what you valued it at" />
                    </Field>
                    <Field label="Asking price (GHS)">
                      <Input type="number" value={inSalePrice} onChange={(e) => setInSalePrice(e.target.value)} placeholder="optional" />
                    </Field>
                  </div>
                </div>
              )}
            </div>
          )}

          {type === "repair" && (
            <p className="text-xs text-zinc-500">
              Repairs are service-only: the phone comes in and goes back with the
              customer. Only the charge is recorded.
            </p>
          )}

          <ErrorNote>{error}</ErrorNote>

          <Button type="button" className="w-full" disabled={pending} onClick={submit}>
            {pending ? "Saving…" : success ? "Saved ✓" : "Save transaction"}
          </Button>
        </div>
      </Card>
    </div>
  );
}
