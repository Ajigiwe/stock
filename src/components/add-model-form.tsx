"use client";

import { useState, useTransition } from "react";
import { useRouter } from "next/navigation";
import { createModel } from "@/lib/actions";
import { Button, ButtonSecondary, ErrorNote, Field, Input, Select } from "@/components/ui";

export function AddModelForm({ shopId }: { shopId: string }) {
  const router = useRouter();
  const [open, setOpen] = useState(false);
  const [modelName, setModelName] = useState("");
  const [condition, setCondition] = useState<"new" | "used">("new");
  const [costPrice, setCostPrice] = useState("");
  const [salePrice, setSalePrice] = useState("");
  const [openingStock, setOpeningStock] = useState("0");
  const [lowStockThreshold, setLowStockThreshold] = useState("5");
  const [error, setError] = useState<string | null>(null);
  const [pending, startTransition] = useTransition();

  if (!open) {
    return (
      <ButtonSecondary onClick={() => setOpen(true)} className="h-8 px-2 text-xs">
        + Add model
      </ButtonSecondary>
    );
  }

  return (
    <div className="mt-3 rounded-lg border border-zinc-200 bg-zinc-50 p-4">
      <div className="grid gap-3 sm:grid-cols-2">
        <div className="sm:col-span-2">
          <Field label="Model name">
            <Input
              value={modelName}
              onChange={(e) => setModelName(e.target.value)}
              placeholder='e.g. "iPhone 13 128GB"'
            />
          </Field>
        </div>
        <Field label="Condition">
          <Select
            value={condition}
            onChange={(e) => setCondition(e.target.value as "new" | "used")}
          >
            <option value="new">New</option>
            <option value="used">Used</option>
          </Select>
        </Field>
        <Field label="Low-stock threshold">
          <Input
            type="number"
            value={lowStockThreshold}
            onChange={(e) => setLowStockThreshold(e.target.value)}
          />
        </Field>
        <Field label="Cost price (GHS)">
          <Input
            type="number"
            value={costPrice}
            onChange={(e) => setCostPrice(e.target.value)}
            placeholder="optional"
          />
        </Field>
        <Field label="Sale price (GHS)">
          <Input
            type="number"
            value={salePrice}
            onChange={(e) => setSalePrice(e.target.value)}
            placeholder="optional"
          />
        </Field>
        <Field label="Opening stock">
          <Input
            type="number"
            value={openingStock}
            onChange={(e) => setOpeningStock(e.target.value)}
          />
        </Field>
      </div>
      <ErrorNote>{error}</ErrorNote>
      <div className="mt-3 flex gap-2">
        <Button
          className="h-8 text-xs"
          disabled={pending}
          onClick={() =>
            startTransition(async () => {
              setError(null);
              const res = await createModel({
                shopId,
                modelName,
                condition,
                costPrice,
                salePrice,
                openingStock,
                lowStockThreshold,
              });
              if (!res.ok) setError(res.error ?? "Failed.");
              else {
                setOpen(false);
                router.refresh();
              }
            })
          }
        >
          Save model
        </Button>
        <ButtonSecondary className="h-8 text-xs" onClick={() => setOpen(false)}>
          Cancel
        </ButtonSecondary>
      </div>
    </div>
  );
}
