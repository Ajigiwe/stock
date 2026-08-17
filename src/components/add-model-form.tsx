"use client";

import { useState, useTransition } from "react";
import { useRouter } from "next/navigation";
import { createModel } from "@/lib/actions";
import {
  Button,
  ButtonSecondary,
  ErrorNote,
  Field,
  Input,
  Modal,
  Select,
} from "@/components/ui";
import { useToast } from "@/components/feedback";

export function AddModelForm({
  shopId,
  canEditStock = true,
}: {
  shopId: string;
  canEditStock?: boolean;
}) {
  const router = useRouter();
  const toast = useToast();
  const [open, setOpen] = useState(false);
  const [modelName, setModelName] = useState("");
  const [condition, setCondition] = useState<"new" | "used">("new");
  const [costPrice, setCostPrice] = useState("");
  const [salePrice, setSalePrice] = useState("");
  const [openingStock, setOpeningStock] = useState("0");
  const [lowStockThreshold, setLowStockThreshold] = useState("5");
  const [error, setError] = useState<string | null>(null);
  const [pending, startTransition] = useTransition();

  const openModal = () => {
    setModelName("");
    setCondition("new");
    setCostPrice("");
    setSalePrice("");
    setOpeningStock("0");
    setLowStockThreshold("5");
    setError(null);
    setOpen(true);
  };

  const submit = () =>
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
      if (!res.ok) return setError(res.error ?? "Failed.");
      toast.success(
        canEditStock
          ? `${modelName} added to stock.`
          : "Request sent — awaiting owner approval.",
      );
      setOpen(false);
      router.refresh();
    });

  return (
    <>
      <ButtonSecondary onClick={openModal} className="h-8 px-2 text-xs">
        + Add model
      </ButtonSecondary>

      <Modal
        open={open}
        onClose={() => setOpen(false)}
        title="Add model"
        subtitle={
          canEditStock
            ? "Add a new phone model to this shop"
            : "Sent to the owner for approval"
        }
      >
        {!canEditStock && (
          <p className="mb-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
            New models are sent to the owner for approval before they appear in stock.
          </p>
        )}
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

        <div className="mt-3">
          <ErrorNote>{error}</ErrorNote>
        </div>

        <div className="mt-3 flex gap-2">
          <Button disabled={pending} onClick={submit} className="flex-1">
            {pending ? "Saving…" : canEditStock ? "Save model" : "Request approval"}
          </Button>
          <ButtonSecondary onClick={() => setOpen(false)}>Cancel</ButtonSecondary>
        </div>
      </Modal>
    </>
  );
}
