"use client";

import { useState, useTransition } from "react";
import { useRouter } from "next/navigation";
import { createShop, deleteShop } from "@/lib/actions";
import type { Shop } from "@/lib/data";
import { Button, ButtonDanger, ButtonSecondary, ErrorNote, Field, Input } from "@/components/ui";
import { useConfirm, useToast } from "@/components/feedback";

export function ShopManager({ shops }: { shops: Shop[] }) {
  const router = useRouter();
  const confirm = useConfirm();
  const toast = useToast();
  const [open, setOpen] = useState(false);
  const [name, setName] = useState("");
  const [location, setLocation] = useState("");
  const [phone, setPhone] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [pending, startTransition] = useTransition();

  const submit = () =>
    startTransition(async () => {
      setError(null);
      const res = await createShop({ name, location, phone });
      if (!res.ok) return setError(res.error ?? "Failed.");
      toast.success(`Shop "${name}" added.`);
      setName("");
      setLocation("");
      setPhone("");
      setOpen(false);
      router.refresh();
    });

  const onDelete = async (s: Shop) => {
    const ok = await confirm({
      title: `Delete "${s.name}"?`,
      message: "All its stock and history will be removed. This cannot be undone.",
      confirmLabel: "Delete shop",
      danger: true,
    });
    if (!ok) return;
    startTransition(async () => {
      const res = await deleteShop(s.id);
      if (!res.ok) return toast.error(res.error ?? "Could not delete shop.");
      toast.success(`Shop "${s.name}" deleted.`);
      router.refresh();
    });
  };

  return (
    <div className="space-y-4">
      {!open ? (
        <ButtonSecondary onClick={() => setOpen(true)} className="h-8 text-xs">
          + Add shop
        </ButtonSecondary>
      ) : (
        <div className="grid gap-3 rounded-lg border border-line bg-paper p-4 sm:grid-cols-3">
          <Field label="Name">
            <Input value={name} onChange={(e) => setName(e.target.value)} placeholder="e.g. Takoradi Market Circle" />
          </Field>
          <Field label="Location">
            <Input value={location} onChange={(e) => setLocation(e.target.value)} placeholder="optional" />
          </Field>
          <Field label="Phone">
            <Input value={phone} onChange={(e) => setPhone(e.target.value)} placeholder="optional" />
          </Field>
          <ErrorNote>{error}</ErrorNote>
          <div className="flex gap-2">
            <Button className="h-8 text-xs" disabled={pending} onClick={submit}>
              Save
            </Button>
            <ButtonSecondary className="h-8 text-xs" onClick={() => setOpen(false)}>
              Cancel
            </ButtonSecondary>
          </div>
        </div>
      )}

      <ul className="divide-y divide-paper">
        {shops.map((s) => (
          <li key={s.id} className="flex items-center justify-between gap-3 py-2">
            <div>
              <div className="text-sm font-medium text-ink">{s.name}</div>
              <div className="text-xs text-mute">
                {s.location ?? "No location"} · {s.phone ?? "No phone"}
              </div>
            </div>
            <ButtonDanger
              className="h-8 px-2 text-xs"
              onClick={() => onDelete(s)}
            >
              Delete
            </ButtonDanger>
          </li>
        ))}
      </ul>
    </div>
  );
}
