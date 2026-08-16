"use client";

import { useState, useTransition } from "react";
import { useRouter } from "next/navigation";
import { updateSwappedPhoneStatus } from "@/lib/actions";
import type { SwappedPhone } from "@/lib/data";
import { formatDateTime } from "@/lib/format";
import { Badge, EmptyState } from "@/components/ui";
import { useToast } from "@/components/feedback";

const STATUS_META: Record<
  SwappedPhone["status"],
  { label: string; tone: "blue" | "green" | "gray" }
> = {
  in_stock: { label: "In stock", tone: "blue" },
  sold: { label: "Sold", tone: "green" },
  returned: { label: "Returned", tone: "gray" },
};

export function SwappedPhonesList({
  phones,
  isOwner,
}: {
  phones: SwappedPhone[];
  isOwner: boolean;
}) {
  const router = useRouter();
  const toast = useToast();
  const [busy, setBusy] = useState<string | null>(null);
  const [pending, startTransition] = useTransition();

  if (phones.length === 0) {
    return <EmptyState>No trade-in phones yet. They appear here after a swap.</EmptyState>;
  }

  const setStatus = (id: string, status: SwappedPhone["status"]) => {
    setBusy(id);
    startTransition(async () => {
      const res = await updateSwappedPhoneStatus(id, status);
      setBusy(null);
      if (!res.ok) return toast.error(res.error ?? "Could not update.");
      toast.success("Trade-in updated.");
      router.refresh();
    });
  };

  return (
    <ul className="space-y-2">
      {phones.map((p) => {
        const meta = STATUS_META[p.status] ?? STATUS_META.in_stock;
        return (
          <li
            key={p.id}
            className="flex items-start justify-between gap-3 rounded-lg border border-zinc-200 bg-zinc-50 p-3"
          >
            <div className="min-w-0">
              <div className="flex flex-wrap items-center gap-2">
                <span className="truncate text-sm font-medium text-zinc-900">
                  {p.model_name}
                </span>
                <Badge tone={p.condition === "new" ? "blue" : "gray"}>
                  {p.condition}
                </Badge>
                <Badge tone={meta.tone}>{meta.label}</Badge>
              </div>
              <div className="mt-0.5 text-xs text-zinc-500">
                {p.customer_name ? `${p.customer_name} · ` : ""}
                {formatDateTime(p.created_at)}
              </div>
            </div>
            {isOwner && (
              <select
                value={p.status}
                disabled={busy === p.id || pending}
                onChange={(e) =>
                  setStatus(p.id, e.target.value as SwappedPhone["status"])
                }
                className="h-8 shrink-0 rounded-lg border border-zinc-300 bg-white px-2 text-xs text-zinc-700 focus:border-zinc-500 focus:outline-none disabled:opacity-50"
                aria-label="Update status"
              >
                <option value="in_stock">In stock</option>
                <option value="sold">Sold</option>
                <option value="returned">Returned</option>
              </select>
            )}
          </li>
        );
      })}
    </ul>
  );
}
