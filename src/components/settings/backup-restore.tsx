"use client";

import { useRef, useState, useTransition } from "react";
import { useRouter } from "next/navigation";
import { restoreBackup } from "@/lib/actions";
import { ButtonSecondary, ErrorNote } from "@/components/ui";
import { useConfirm, useToast } from "@/components/feedback";

export function BackupRestore() {
  const router = useRouter();
  const confirm = useConfirm();
  const toast = useToast();
  const fileRef = useRef<HTMLInputElement>(null);
  const [pending, startTransition] = useTransition();
  const [error, setError] = useState<string | null>(null);
  const [fileName, setFileName] = useState<string | null>(null);

  const onRestore = async () => {
    const file = fileRef.current?.files?.[0];
    if (!file) return setError("Choose a backup file first.");

    const ok = await confirm({
      title: "Restore this backup?",
      message:
        "This REPLACES all current shops, devices, transactions, and adjustments with the backup. This cannot be undone.",
      confirmLabel: "Replace everything",
      danger: true,
    });
    if (!ok) return;

    const raw = await file.text();
    startTransition(async () => {
      setError(null);
      const res = await restoreBackup(raw);
      if (!res.ok) return setError(res.error ?? "Restore failed.");
      toast.success(
        `Backup restored (${res.transactions ?? 0} transactions loaded).`,
      );
      setFileName(null);
      if (fileRef.current) fileRef.current.value = "";
      router.refresh();
    });
  };

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center gap-3">
        <a
          href="/settings/backup"
          className="inline-flex h-8 items-center justify-center rounded-lg bg-ink px-3 text-xs font-medium text-white transition-colors hover:bg-ink/70"
        >
          Download backup
        </a>
        <input
          ref={fileRef}
          type="file"
          accept=".json,application/json"
          className="max-w-full text-xs text-mute"
          onChange={(e) => {
            setFileName(e.target.files?.[0]?.name ?? null);
            setError(null);
          }}
        />
        <ButtonSecondary className="h-8 text-xs" disabled={pending || !fileName} onClick={onRestore}>
          {pending ? "Restoring…" : "Restore backup"}
        </ButtonSecondary>
      </div>

      <p className="text-xs text-mute">
        Backup downloads a JSON file with all shops, devices, transactions, and
        adjustments. Restoring <span className="font-medium">replaces everything</span>{" "}
        with the selected backup file. Staff login accounts are kept where the
        account still exists.
      </p>

      <ErrorNote>{error}</ErrorNote>
    </div>
  );
}
