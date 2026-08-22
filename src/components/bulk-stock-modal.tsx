"use client";

import { useMemo, useState, useTransition } from "react";
import { useRouter } from "next/navigation";
import { bulkAdjustStock } from "@/lib/actions";
import type { PhoneModel } from "@/lib/data";
import {
  Badge,
  Button,
  ButtonSecondary,
  ErrorNote,
  Field,
  Input,
  Modal,
} from "@/components/ui";
import { useToast } from "@/components/feedback";

export function BulkStockModal({
  shopId,
  stock,
  canEditStock,
}: {
  shopId: string;
  stock: PhoneModel[];
  canEditStock: boolean;
}) {
  const router = useRouter();
  const toast = useToast();
  const [open, setOpen] = useState(false);
  const [targets, setTargets] = useState<Record<string, string>>({});
  const [reason, setReason] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [pending, startTransition] = useTransition();

  const openModal = () => {
    const t: Record<string, string> = {};
    for (const m of stock) t[m.id] = String(m.available);
    setTargets(t);
    setReason("");
    setError(null);
    setOpen(true);
  };

  const changes = useMemo(
    () =>
      stock
        .map((m) => {
          const target = Math.floor(Number(targets[m.id]));
          const delta = Number.isFinite(target) && target >= 0 ? target - m.available : 0;
          return { model: m, target, delta };
        })
        .filter((c) => Number.isFinite(c.target) && c.delta !== 0),
    [stock, targets],
  );

  const apply = () => {
    setError(null);
    if (!changes.length) {
      setError("No changes — adjust some quantities first.");
      return;
    }
    startTransition(async () => {
      const res = await bulkAdjustStock({
        shopId,
        reason,
        items: changes.map((c) => ({ modelId: c.model.id, targetQty: c.target })),
      });
      if (!res.ok) return setError(res.error ?? "Failed to apply changes.");
      toast.success(
        canEditStock
          ? `${res.changes ?? 0} stock change(s) applied.`
          : `${res.changes ?? 0} change(s) sent — awaiting owner approval.`,
      );
      setOpen(false);
      router.refresh();
    });
  };

  return (
    <>
      <ButtonSecondary onClick={openModal} className="h-8 px-2 text-xs">
        Bulk stock
      </ButtonSecondary>

      <Modal
        open={open}
        onClose={() => setOpen(false)}
        size="xl"
        title="Bulk stock edit"
        subtitle={
          canEditStock
            ? "Set target quantities — applied immediately"
            : "Set target quantities — sent to the owner for approval"
        }
      >
        {stock.length === 0 ? (
          <p className="py-4 text-sm text-mute">
            No models in this shop yet. Add one first.
          </p>
        ) : (
          <>
            <div className="mb-2 flex items-center justify-between text-xs text-mute">
              <span>{stock.length} model{stock.length === 1 ? "" : "s"}</span>
              <span className={changes.length ? "font-semibold text-ink/80" : ""}>
                {changes.length} change{changes.length === 1 ? "" : "s"}
              </span>
            </div>
            <div className="mb-2 grid grid-cols-[minmax(0,1fr)_120px] items-center gap-3 px-3 text-[11px] font-medium uppercase tracking-wide text-mute">
              <span>Model</span>
              <span className="text-right">Target</span>
            </div>
            <div className="space-y-1.5">
              {stock.map((m) => {
                const target = Math.floor(Number(targets[m.id]));
                const delta =
                  Number.isFinite(target) && target >= 0
                    ? target - m.available
                    : 0;
                const changed = delta !== 0;
                const low = m.available <= m.low_stock_threshold;
                return (
                  <div
                    key={m.id}
                    className={`grid grid-cols-[minmax(0,1fr)_120px] items-center gap-3 rounded-lg border px-3 py-2 ${
                      changed
                        ? "border-instock bg-instock-tint/60"
                        : "border-line bg-paper"
                    }`}
                  >
                    <div className="min-w-0">
                      <div className="truncate text-sm font-medium text-ink">
                        {m.model_name}
                      </div>
                      <div className="mt-0.5 flex flex-wrap items-center gap-x-2 text-xs text-mute">
                        <Badge tone={m.condition === "new" ? "blue" : "gray"}>
                          {m.condition}
                        </Badge>
                        <span className={low ? "text-lowstock" : ""}>
                          now {m.available}
                        </span>
                        {changed && (
                          <span
                            className={
                              delta > 0 ? "text-instock" : "text-lowstock"
                            }
                          >
                            → {target} ({delta > 0 ? "+" : ""}
                            {delta})
                          </span>
                        )}
                      </div>
                    </div>
                    <div className="flex items-center justify-end gap-1.5">
                      <span className="text-xs text-mute">→</span>
                      <Input
                        type="number"
                        min="0"
                        value={targets[m.id] ?? ""}
                        onChange={(e) =>
                          setTargets({ ...targets, [m.id]: e.target.value })
                        }
                        className="h-9 w-full text-right"
                      />
                    </div>
                  </div>
                );
              })}
            </div>
          </>
        )}

        {stock.length > 0 && (
          <>
            <div className="mt-4">
              <Field label="Reason (optional)">
                <Input
                  value={reason}
                  onChange={(e) => setReason(e.target.value)}
                  placeholder="e.g. new batch received"
                />
              </Field>
            </div>
            <div className="mt-3 flex items-center gap-2">
              <Button
                disabled={pending}
                onClick={apply}
                className="flex-1"
              >
                {pending
                  ? "Saving…"
                  : canEditStock
                    ? `Apply ${changes.length} change${changes.length === 1 ? "" : "s"}`
                    : `Request ${changes.length} change${changes.length === 1 ? "" : "s"}`}
              </Button>
              <ButtonSecondary onClick={() => setOpen(false)}>
                Cancel
              </ButtonSecondary>
            </div>
          </>
        )}

        <div className="mt-3">
          <ErrorNote>{error}</ErrorNote>
        </div>
      </Modal>
    </>
  );
}