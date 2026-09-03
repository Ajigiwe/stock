"use client";

import { useState, useTransition } from "react";
import { useRouter } from "next/navigation";
import type { PhoneModel } from "@/lib/data";
import type { StockCountWithItems } from "@/lib/data";
import { submitStockCount, approveStockCount, applyStockCountCorrection } from "@/lib/actions";
import { Badge, Button, ButtonSecondary, ErrorNote, Field, Input } from "@/components/ui";
import { useToast } from "@/components/feedback";

export function StockCountPanel({
  shopId,
  stock,
  latest,
  date,
  isOwner,
}: {
  shopId: string;
  stock: PhoneModel[];
  latest: StockCountWithItems | null;
  date: string;
  isOwner: boolean;
}) {
  const router = useRouter();
  const toast = useToast();
  const [values, setValues] = useState<Record<string, string>>(() =>
    Object.fromEntries(stock.map((m) => [m.id, String(m.available)])),
  );
  const [notes, setNotes] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [pending, startTransition] = useTransition();

  const submit = () => startTransition(async () => {
    setError(null);
    const items = stock.map((m) => ({ modelId: m.id, countedQty: Math.max(0, Math.floor(Number(values[m.id] ?? 0))) }));
    const res = await submitStockCount({ shopId, date, items, notes });
    if (!res.ok) return setError(res.error ?? "Could not submit stock count.");
    toast.success("Physical stock count submitted for review.");
    router.refresh();
  });

  return (
    <div className="space-y-4">
      <p className="text-xs text-mute">Count what is physically present. Submitting evidence never changes inventory; an owner must review any variance.</p>
      <div className="space-y-2">
        {stock.map((m) => (
          <div key={m.id} className="grid grid-cols-[minmax(0,1fr)_5rem] items-center gap-3 rounded-lg border border-line bg-paper px-3 py-2">
            <div className="min-w-0">
              <div className="truncate text-sm font-medium text-ink">{m.model_name}</div>
              <div className="text-xs text-mute">Expected {m.available}</div>
            </div>
            <Input aria-label={`Counted ${m.model_name}`} type="number" min="0" value={values[m.id] ?? ""} onChange={(e) => setValues({ ...values, [m.id]: e.target.value })} className="h-11 text-center" />
          </div>
        ))}
      </div>
      <Field label="Notes"><Input value={notes} onChange={(e) => setNotes(e.target.value)} placeholder="Explain missing or extra stock" /></Field>
      <ErrorNote>{error}</ErrorNote>
      <Button disabled={pending || stock.length === 0} onClick={submit}>{pending ? "Submitting…" : "Submit physical count"}</Button>

      {latest && (
        <div className="rounded-xl border border-line bg-paper p-3">
          <div className="flex items-center justify-between gap-2">
            <span className="text-sm font-semibold text-ink">Latest count</span>
            <Badge tone={latest.status === "submitted" ? "amber" : latest.status === "applied" ? "green" : "blue"}>{latest.status}</Badge>
          </div>
          <div className="mt-2 space-y-1 text-xs text-mute">
            {latest.items.filter((i) => i.expected_qty !== i.counted_qty).map((i) => (
              <div key={i.id} className="flex justify-between gap-2"><span>{i.model_name}</span><span className="font-semibold text-lowstock">Expected {i.expected_qty} · Counted {i.counted_qty}</span></div>
            ))}
            {latest.items.every((i) => i.expected_qty === i.counted_qty) && <span>No variance recorded.</span>}
          </div>
          {isOwner && latest.status === "submitted" && (
            <div className="mt-3 flex gap-2">
              <ButtonSecondary disabled={pending} onClick={() => startTransition(async () => {
                const res = await approveStockCount(latest.id);
                if (!res.ok) return setError(res.error ?? "Could not approve count.");
                toast.success("Stock count approved.");
                router.refresh();
              })}>Approve count</ButtonSecondary>
            </div>
          )}
          {isOwner && latest.status === "approved" && latest.items.some((i) => i.expected_qty !== i.counted_qty) && (
            <div className="mt-3 space-y-2">
              <Input id="count-correction-reason" placeholder="Reason for applying variance correction" />
              <Button disabled={pending} onClick={() => startTransition(async () => {
                const el = document.getElementById("count-correction-reason") as HTMLInputElement | null;
                const res = await applyStockCountCorrection(latest.id, el?.value ?? "");
                if (!res.ok) return setError(res.error ?? "Could not apply correction.");
                toast.success("Stock correction applied and logged.");
                router.refresh();
              })}>Apply variance correction</Button>
            </div>
          )}
        </div>
      )}
    </div>
  );
}
