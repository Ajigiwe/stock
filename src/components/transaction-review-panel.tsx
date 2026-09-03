"use client";

import { useState, useTransition } from "react";
import { useRouter } from "next/navigation";
import type { TransactionWithDetails } from "@/lib/data";
import { reviewTransaction } from "@/lib/actions";
import { formatMoney, formatDateTime } from "@/lib/format";
import { Badge, Button, ButtonDanger, ButtonSecondary, EmptyState, Input } from "@/components/ui";
import { useToast } from "@/components/feedback";

export function TransactionReviewPanel({
  transactions,
  isOwner,
}: {
  transactions: TransactionWithDetails[];
  isOwner: boolean;
}) {
  const router = useRouter();
  const toast = useToast();
  const [busy, setBusy] = useState<string | null>(null);
  const [reasonFor, setReasonFor] = useState<string | null>(null);
  const [reason, setReason] = useState("");
  const [pending, startTransition] = useTransition();

  const decide = (tx: TransactionWithDetails, decision: "approve" | "reject") => {
    if (decision === "reject" && !reason.trim()) {
      setReasonFor(tx.id);
      return;
    }
    setBusy(tx.id);
    startTransition(async () => {
      const res = await reviewTransaction(tx.id, decision, reason);
      setBusy(null);
      if (!res.ok) return toast.error(res.error ?? "Could not review transaction.");
      toast.success(decision === "approve" ? "Discount approved." : "Transaction rejected and stock restored.");
      setReasonFor(null);
      setReason("");
      router.refresh();
    });
  };

  if (!isOwner || transactions.length === 0) return <EmptyState>No discounted transactions need review.</EmptyState>;

  return (
    <div className="space-y-2">
      {transactions.map((tx) => (
        <div key={tx.id} className="rounded-xl border border-brand bg-brand-tint/40 p-3">
          <div className="flex flex-wrap items-start justify-between gap-3">
            <div className="min-w-0">
              <div className="flex flex-wrap items-center gap-2">
                <Badge tone="amber">discount review</Badge>
                <span className="text-sm font-bold text-ink">{tx.customer_name || "Walk-in"}</span>
                <span className="text-xs text-mute">{tx.shop_name ?? "Shop"} · {tx.staff_name ?? "Staff"}</span>
              </div>
              <div className="mt-1 text-xs text-mute">
                {tx.items.filter((i) => i.direction === "out").map((i) => `${i.qty}× ${i.model_name}`).join(", ") || "No item details"}
                {" · "}{formatDateTime(tx.date)}
              </div>
              <div className="mt-1 text-sm font-semibold text-ink">
                Recorded {formatMoney(tx.amount)}{tx.discount_reason ? ` · ${tx.discount_reason}` : ""}
              </div>
            </div>
            <div className="flex shrink-0 gap-2">
              <Button disabled={pending || busy === tx.id} className="h-11 px-3 text-xs" onClick={() => decide(tx, "approve")}>
                Approve
              </Button>
              <ButtonDanger disabled={pending || busy === tx.id} className="h-11 px-3 text-xs" onClick={() => decide(tx, "reject")}>
                Reject
              </ButtonDanger>
            </div>
          </div>
          {reasonFor === tx.id && (
            <div className="mt-3 flex gap-2">
              <Input value={reason} onChange={(e) => setReason(e.target.value)} placeholder="Reason for rejecting" autoFocus />
              <ButtonSecondary className="shrink-0 px-3 text-xs" disabled={pending} onClick={() => decide(tx, "reject")}>Confirm reject</ButtonSecondary>
            </div>
          )}
        </div>
      ))}
    </div>
  );
}
