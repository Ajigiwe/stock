"use client";

import { useState, useTransition } from "react";
import { useRouter } from "next/navigation";
import { voidTransaction } from "@/lib/actions";
import { useConfirm, useToast } from "@/components/feedback";
import { Input } from "@/components/ui";

export function DeleteTransactionButton({ id }: { id: string }) {
  const router = useRouter();
  const confirm = useConfirm();
  const toast = useToast();
  const [reason, setReason] = useState("");
  const [pending, startTransition] = useTransition();

  const onVoid = async () => {
    const ok = await confirm({
      title: "Void this transaction?",
      message: "Stock effects will be reversed, but the original receipt and audit history will be preserved.",
      confirmLabel: "Void transaction",
      danger: true,
    });
    if (!ok) return;
    if (!reason.trim()) return toast.error("Enter a reason before voiding.");
    startTransition(async () => {
      const res = await voidTransaction(id, reason);
      if (!res.ok) return toast.error(res.error ?? "Could not void transaction.");
      toast.success("Transaction voided and audit history preserved.");
      setReason("");
      router.refresh();
    });
  };

  return (
    <div className="flex items-center gap-2">
      <Input
        aria-label="Reason for voiding"
        value={reason}
        onChange={(e) => setReason(e.target.value)}
        placeholder="Void reason"
        className="h-9 w-32 text-xs"
      />
      <button
        disabled={pending}
        className="text-xs font-medium text-lowstock hover:text-lowstock disabled:opacity-50"
        onClick={onVoid}
      >
        {pending ? "…" : "Void"}
      </button>
    </div>
  );
}
