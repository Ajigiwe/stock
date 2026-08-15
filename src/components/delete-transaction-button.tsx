"use client";

import { useTransition } from "react";
import { useRouter } from "next/navigation";
import { deleteTransaction } from "@/lib/actions";
import { useConfirm, useToast } from "@/components/feedback";

export function DeleteTransactionButton({ id }: { id: string }) {
  const router = useRouter();
  const confirm = useConfirm();
  const toast = useToast();
  const [pending, startTransition] = useTransition();

  const onDelete = async () => {
    const ok = await confirm({
      title: "Delete this transaction?",
      message: "Stock effects will be reversed. This cannot be undone.",
      confirmLabel: "Delete",
      danger: true,
    });
    if (!ok) return;
    startTransition(async () => {
      const res = await deleteTransaction(id);
      if (!res.ok) return toast.error(res.error ?? "Could not delete.");
      toast.success("Transaction deleted.");
      router.refresh();
    });
  };

  return (
    <button
      disabled={pending}
      className="text-xs font-medium text-red-600 hover:text-red-500 disabled:opacity-50"
      onClick={onDelete}
    >
      {pending ? "…" : "Delete"}
    </button>
  );
}
