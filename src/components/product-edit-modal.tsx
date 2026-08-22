"use client";

import { useState, useTransition } from "react";
import { useRouter } from "next/navigation";
import { updateModel, adjustStock } from "@/lib/actions";
import type { PhoneModel, StockAdjustment } from "@/lib/data";
import { formatDateTime, formatMoney } from "@/lib/format";
import {
  Badge,
  Button,
  ButtonSecondary,
  ErrorNote,
  Field,
  Input,
  Modal,
  Select,
} from "@/components/ui";
import { useToast } from "@/components/feedback";

export function ProductEditModal({
  model,
  adjustments,
  shopId,
  canEditStock = true,
}: {
  model: PhoneModel;
  adjustments: StockAdjustment[];
  shopId: string;
  canEditStock?: boolean;
}) {
  const router = useRouter();
  const toast = useToast();
  const [open, setOpen] = useState(false);
  const [pending, startTransition] = useTransition();
  const [error, setError] = useState<string | null>(null);

  const [modelName, setModelName] = useState(model.model_name);
  const [condition, setCondition] = useState<"new" | "used">(model.condition);
  const [costPrice, setCostPrice] = useState(
    model.cost_price != null ? String(model.cost_price) : "",
  );
  const [salePrice, setSalePrice] = useState(
    model.sale_price != null ? String(model.sale_price) : "",
  );
  const [lowThreshold, setLowThreshold] = useState(String(model.low_stock_threshold));

  const [delta, setDelta] = useState("1");
  const [adjustType, setAdjustType] = useState<"restock" | "correction">("restock");
  const [reason, setReason] = useState("");

  const low = model.available <= model.low_stock_threshold;

  const openModal = () => {
    setModelName(model.model_name);
    setCondition(model.condition);
    setCostPrice(model.cost_price != null ? String(model.cost_price) : "");
    setSalePrice(model.sale_price != null ? String(model.sale_price) : "");
    setLowThreshold(String(model.low_stock_threshold));
    setDelta("1");
    setAdjustType("restock");
    setReason("");
    setError(null);
    setOpen(true);
  };

  const saveProduct = () => {
    setError(null);
    startTransition(async () => {
      const res = await updateModel({
        shopId,
        modelId: model.id,
        modelName,
        condition,
        costPrice,
        salePrice,
        lowStockThreshold: lowThreshold,
      });
      if (!res.ok) return setError(res.error ?? "Could not save.");
      toast.success("Product details saved.");
      setOpen(false);
      router.refresh();
    });
  };

  const applyStock = () => {
    setError(null);
    startTransition(async () => {
      const res = await adjustStock({
        shopId,
        phoneModelId: model.id,
        delta: (adjustType === "restock" ? "" : "-") + delta,
        reason,
      });
      if (!res.ok) return setError(res.error ?? "Could not adjust stock.");
      toast.success(
        canEditStock
          ? "Stock updated."
          : "Stock change sent — awaiting owner approval.",
      );
      setOpen(false);
      router.refresh();
    });
  };

  return (
    <>
      <ButtonSecondary onClick={openModal} className="h-8 px-2 text-xs">
        Edit
      </ButtonSecondary>

      <Modal
        open={open}
        onClose={() => setOpen(false)}
        title={
          <span className="flex items-center gap-2">
            {model.model_name}
            <Badge tone={model.condition === "new" ? "blue" : "gray"}>
              {model.condition}
            </Badge>
          </span>
        }
        subtitle="Edit product details and adjust stock"
      >
        <div className="grid grid-cols-3 gap-2 rounded-xl border border-line bg-paper p-3 text-center">
          <div>
            <div className="text-[11px] font-medium uppercase tracking-wide text-mute">
              Opening
            </div>
            <div className="text-sm font-bold text-ink">{model.opening_stock}</div>
          </div>
          <div>
            <div className="text-[11px] font-medium uppercase tracking-wide text-mute">
              Bought in
            </div>
            <div className="text-sm font-bold text-ink">{model.bought_in}</div>
          </div>
          <div>
            <div className="text-[11px] font-medium uppercase tracking-wide text-mute">
              Available
            </div>
            <div className={`text-sm font-bold ${low ? "text-lowstock" : "text-ink"}`}>
              {model.available}
            </div>
          </div>
        </div>
        {low && (
          <p className="mt-2 text-xs font-medium text-lowstock">
            Low stock — at or below the threshold of {model.low_stock_threshold}.
          </p>
        )}
        {model.cost_price != null && model.sale_price != null && (
          <p className="mt-2 text-xs text-mute">
            Unit margin:{" "}
            <span className="font-semibold text-instock">
              {formatMoney(model.sale_price - model.cost_price)}
            </span>
            {model.sale_price > 0 &&
              ` (${Math.round(((model.sale_price - model.cost_price) / model.sale_price) * 100)}%)`}
            {" · "}stock value at cost{" "}
            <span className="font-medium text-ink/80">
              {formatMoney(model.cost_price * model.available)}
            </span>
          </p>
        )}

        <div className="mt-4">
          <h3 className="mb-2 text-xs font-semibold uppercase tracking-wide text-mute">
            Product details
          </h3>
          {!canEditStock && (
            <p className="mb-2 rounded-lg border border-brand bg-brand-tint px-3 py-2 text-xs text-brand">
              Editing product details requires stock privileges — only the owner
              can grant them.
            </p>
          )}
          <div className="space-y-3">
            <Field label="Model name">
              <Input
                value={modelName}
                onChange={(e) => setModelName(e.target.value)}
                placeholder='e.g. "iPhone 13 128GB"'
              />
            </Field>
            <div className="grid grid-cols-2 gap-3">
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
                  min="0"
                  value={lowThreshold}
                  onChange={(e) => setLowThreshold(e.target.value)}
                />
              </Field>
              <Field label="Cost price (GHS)">
                <Input
                  type="number"
                  min="0"
                  step="0.01"
                  value={costPrice}
                  onChange={(e) => setCostPrice(e.target.value)}
                  placeholder="optional"
                />
              </Field>
              <Field label="Sale price (GHS)">
                <Input
                  type="number"
                  min="0"
                  step="0.01"
                  value={salePrice}
                  onChange={(e) => setSalePrice(e.target.value)}
                  placeholder="optional"
                />
              </Field>
            </div>
            {canEditStock && (
              <Button
                type="button"
                disabled={pending}
                onClick={saveProduct}
                className="h-9 w-full"
              >
                Save product
              </Button>
            )}
          </div>
        </div>

        <div className="mt-5">
          <h3 className="mb-2 text-xs font-semibold uppercase tracking-wide text-mute">
            Adjust stock
          </h3>
          {!canEditStock && (
            <p className="mb-2 rounded-lg border border-brand bg-brand-tint px-3 py-2 text-xs text-brand">
              Stock changes are sent to the owner for approval.
            </p>
          )}
          <div className="flex flex-wrap items-end gap-2">
            <div className="flex-1 min-w-24">
              <Field label="Qty">
                <Input
                  type="number"
                  min="1"
                  value={delta}
                  onChange={(e) => setDelta(e.target.value)}
                />
              </Field>
            </div>
            <div className="flex-1 min-w-28">
              <Field label="Type">
                <Select
                  value={adjustType}
                  onChange={(e) =>
                    setAdjustType(e.target.value as "restock" | "correction")
                  }
                >
                  <option value="restock">Restock (+)</option>
                  <option value="correction">Correction (−)</option>
                </Select>
              </Field>
            </div>
            <div className="w-full">
              <Field label="Reason">
                <Input
                  value={reason}
                  onChange={(e) => setReason(e.target.value)}
                  placeholder="optional"
                />
              </Field>
            </div>
          </div>
          <Button
            type="button"
            disabled={pending}
            onClick={applyStock}
            className="mt-3 h-9 w-full"
          >
            {canEditStock ? "Apply stock change" : "Request stock change"}
          </Button>
        </div>

        <div className="mt-3">
          <ErrorNote>{error}</ErrorNote>
        </div>

        {adjustments.length > 0 && (
          <div className="mt-5">
            <h3 className="mb-2 text-xs font-semibold uppercase tracking-wide text-mute">
              Recent adjustments
            </h3>
            <ul className="space-y-1.5">
              {adjustments.slice(0, 6).map((a) => (
                <li
                  key={a.id}
                  className="flex items-center justify-between gap-2 rounded-lg bg-paper px-3 py-1.5 text-xs"
                >
                  <span>
                    <Badge tone={a.delta > 0 ? "green" : "red"}>
                      {a.delta > 0 ? `+${a.delta}` : a.delta}
                    </Badge>{" "}
                    <span className="text-mute">{a.type}</span>
                    {a.reason ? (
                      <span className="text-mute"> · {a.reason}</span>
                    ) : null}
                  </span>
                  <span className="text-mute">{formatDateTime(a.date)}</span>
                </li>
              ))}
            </ul>
          </div>
        )}
      </Modal>
    </>
  );
}