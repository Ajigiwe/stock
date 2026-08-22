"use client";

import { useState, useTransition } from "react";
import { useRouter } from "next/navigation";
import {
  approveStockRequest,
  rejectStockRequest,
  approveAllStockRequests,
} from "@/lib/actions";
import type { StockRequestWithDetails } from "@/lib/data";
import { formatDateTime } from "@/lib/format";
import { Badge, Button, ButtonDanger, EmptyState } from "@/components/ui";
import { useToast } from "@/components/feedback";

export function StockRequestsPanel({
  requests,
  isOwner,
  showShop = false,
  shopId,
}: {
  requests: StockRequestWithDetails[];
  isOwner: boolean;
  showShop?: boolean;
  shopId?: string;
}) {
  const router = useRouter();
  const toast = useToast();
  const [busy, setBusy] = useState<string | null>(null);
  const [pending, startTransition] = useTransition();

  const decide = (id: string, approve: boolean) => {
    setBusy(id);
    startTransition(async () => {
      const res = approve
        ? await approveStockRequest(id)
        : await rejectStockRequest(id);
      setBusy(null);
      if (!res.ok) return toast.error(res.error ?? "Action failed.");
      toast.success(approve ? "Change approved." : "Change rejected.");
      router.refresh();
    });
  };

  const approveAll = () => {
    startTransition(async () => {
      const res = await approveAllStockRequests(shopId);
      if (!res.ok) return toast.error(res.error ?? "Could not approve all.");
      if ((res.failed ?? 0) > 0) {
        toast.error(
          `${res.approved ?? 0} approved, ${res.failed} failed (kept pending — see notes below).`,
        );
      } else {
        toast.success(`${res.approved ?? 0} stock change(s) approved.`);
      }
      router.refresh();
    });
  };

  return (
    <div className="space-y-2">
      {isOwner && requests.length > 0 && (
        <div className="flex items-center justify-between gap-3">
          <span className="text-xs text-mute">
            {requests.length} pending change{requests.length === 1 ? "" : "s"}
          </span>
          <Button
            className="h-8 px-3 text-xs"
            disabled={pending}
            onClick={approveAll}
          >
            Approve all
          </Button>
        </div>
      )}

      {requests.length === 0 ? (
        <EmptyState>No pending stock changes.</EmptyState>
      ) : (
        requests.map((r) => {
          const isCreate = r.type === "create_model";
          const d = r.delta ?? 0;
          const title = isCreate
            ? `New model: ${r.model_name_display ?? "—"}`
            : `${d > 0 ? "Restock" : "Correction"} ${d > 0 ? "+" : ""}${d} · ${r.model_name_display ?? "—"}`;
          return (
            <div
              key={r.id}
              className="rounded-lg border border-line bg-paper p-3"
            >
              <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                  <div className="flex flex-wrap items-center gap-2 text-sm">
                    <span className="font-medium text-ink">{title}</span>
                    {isCreate && r.condition && (
                      <Badge tone={r.condition === "new" ? "blue" : "gray"}>
                        {r.condition}
                      </Badge>
                    )}
                    <Badge tone="amber">pending</Badge>
                  </div>
                  <div className="mt-1 text-xs text-mute">
                    {r.staff_name ?? "Staff"}
                    {showShop && r.shop_name ? ` · ${r.shop_name}` : ""}
                    {r.opening_stock != null ? ` · opening ${r.opening_stock}` : ""}
                    {r.cost_price != null ? ` · cost ${r.cost_price} GHS` : ""}
                    {r.sale_price != null ? ` · sale ${r.sale_price} GHS` : ""}
                    {r.reason ? ` · "${r.reason}"` : ""}
                    {" · "}
                    {formatDateTime(r.created_at)}
                  </div>
                  {r.error_note && (
                    <div className="mt-1 text-xs text-lowstock">
                      Failed to apply: {r.error_note}
                    </div>
                  )}
                </div>
                {isOwner ? (
                  <div className="flex shrink-0 gap-2">
                    <Button
                      className="h-8 px-3 text-xs"
                      disabled={busy === r.id || pending}
                      onClick={() => decide(r.id, true)}
                    >
                      Approve
                    </Button>
                    <ButtonDanger
                      className="h-8 px-3 text-xs"
                      disabled={busy === r.id || pending}
                      onClick={() => decide(r.id, false)}
                    >
                      Reject
                    </ButtonDanger>
                  </div>
                ) : (
                  <Badge tone="amber">awaiting owner</Badge>
                )}
              </div>
            </div>
          );
        })
      )}
    </div>
  );
}