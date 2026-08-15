"use client";

import { useTransition } from "react";
import { useRouter } from "next/navigation";
import { deleteTransaction } from "@/lib/actions";

export function DeleteTransactionButton({ id }: { id: string }) {
  const router = useRouter();
  const [pending, startTransition] = useTransition();

  return (
    <button
      disabled={pending}
      className="text-xs font-medium text-red-600 hover:text-red-500 disabled:opacity-50"
      onClick={() =>
        startTransition(async () => {
          if (!confirm("Delete this transaction? Stock effects will be reversed.")) return;
          await deleteTransaction(id);
          router.refresh();
        })
      }
    >
      {pending ? "…" : "Delete"}
    </button>
  );
}
