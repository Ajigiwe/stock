"use client";

import { useState, useTransition } from "react";
import { useRouter } from "next/navigation";
import { adjustStock } from "@/lib/actions";
import { Button, ButtonSecondary, ErrorNote, Input, Label, Select } from "@/components/ui";

export function AdjustStockForm({
  shopId,
  phoneModelId,
  modelName,
}: {
  shopId: string;
  phoneModelId: string;
  modelName: string;
}) {
  const router = useRouter();
  const [open, setOpen] = useState(false);
  const [delta, setDelta] = useState("1");
  const [type, setType] = useState<"restock" | "correction">("restock");
  const [reason, setReason] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [pending, startTransition] = useTransition();

  if (!open) {
    return (
      <ButtonSecondary onClick={() => setOpen(true)} className="h-8 px-2 text-xs">
        Adjust
      </ButtonSecondary>
    );
  }

  return (
    <div className="flex w-56 flex-col gap-2 rounded-lg border border-zinc-200 bg-zinc-50 p-3">
      <div className="text-xs font-semibold text-zinc-700">{modelName}</div>
      <div>
        <Label>Qty</Label>
        <Input
          type="number"
          value={delta}
          onChange={(e) => setDelta(e.target.value)}
          className="h-8"
        />
      </div>
      <div>
        <Label>Type</Label>
        <Select
          value={type}
          onChange={(e) => setType(e.target.value as "restock" | "correction")}
          className="h-8"
        >
          <option value="restock">Restock (+)</option>
          <option value="correction">Correction (−)</option>
        </Select>
      </div>
      <div>
        <Label>Reason</Label>
        <Input
          value={reason}
          onChange={(e) => setReason(e.target.value)}
          placeholder="optional"
          className="h-8"
        />
      </div>
      <ErrorNote>{error}</ErrorNote>
      <div className="flex gap-2">
        <Button
          className="h-8 flex-1 text-xs"
          disabled={pending}
          onClick={() =>
            startTransition(async () => {
              setError(null);
              const res = await adjustStock({
                shopId,
                phoneModelId,
                delta: (type === "restock" ? "" : "-") + delta,
                reason,
              });
              if (!res.ok) setError(res.error ?? "Failed.");
              else {
                setOpen(false);
                router.refresh();
              }
            })
          }
        >
          Apply
        </Button>
        <ButtonSecondary className="h-8 text-xs" onClick={() => setOpen(false)}>
          Cancel
        </ButtonSecondary>
      </div>
    </div>
  );
}
