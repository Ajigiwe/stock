"use client";

import { useState, useTransition } from "react";
import { useRouter } from "next/navigation";
import { createStaff, removeStaff } from "@/lib/actions";
import type { Shop, UserProfile } from "@/lib/data";
import { Button, ButtonDanger, ButtonSecondary, ErrorNote, Field, Input, Select } from "@/components/ui";
import { useConfirm, useToast } from "@/components/feedback";

export function StaffManager({ shops, staff }: { shops: Shop[]; staff: UserProfile[] }) {
  const router = useRouter();
  const confirm = useConfirm();
  const toast = useToast();
  const [open, setOpen] = useState(false);
  const [name, setName] = useState("");
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [shopId, setShopId] = useState(shops[0]?.id ?? "");
  const [error, setError] = useState<string | null>(null);
  const [pending, startTransition] = useTransition();

  const submit = () =>
    startTransition(async () => {
      setError(null);
      const res = await createStaff({ name, email, password, shopId });
      if (!res.ok) return setError(res.error ?? "Failed.");
      toast.success(`Staff account for ${name} created.`);
      setName("");
      setEmail("");
      setPassword("");
      setOpen(false);
      router.refresh();
    });

  const onRemove = async (s: UserProfile) => {
    const ok = await confirm({
      title: `Remove ${s.name}?`,
      message: "Their login account will be permanently deleted.",
      confirmLabel: "Remove staff",
      danger: true,
    });
    if (!ok) return;
    startTransition(async () => {
      const res = await removeStaff(s.id);
      if (!res.ok) return toast.error(res.error ?? "Could not remove staff.");
      toast.success(`${s.name} removed.`);
      router.refresh();
    });
  };

  return (
    <div className="space-y-4">
      {!open ? (
        <ButtonSecondary onClick={() => setOpen(true)} className="h-8 text-xs">
          + Add staff
        </ButtonSecondary>
      ) : (
        <div className="grid gap-3 rounded-lg border border-zinc-200 bg-zinc-50 p-4 sm:grid-cols-2">
          <Field label="Full name">
            <Input value={name} onChange={(e) => setName(e.target.value)} />
          </Field>
          <Field label="Email">
            <Input type="email" value={email} onChange={(e) => setEmail(e.target.value)} />
          </Field>
          <Field label="Temporary password">
            <Input value={password} onChange={(e) => setPassword(e.target.value)} placeholder="min 6 characters" />
          </Field>
          <Field label="Shop">
            <Select value={shopId} onChange={(e) => setShopId(e.target.value)}>
              {shops.map((s) => (
                <option key={s.id} value={s.id}>
                  {s.name}
                </option>
              ))}
            </Select>
          </Field>
          <ErrorNote>{error}</ErrorNote>
          <div className="flex gap-2">
            <Button className="h-8 text-xs" disabled={pending} onClick={submit}>
              Create account
            </Button>
            <ButtonSecondary className="h-8 text-xs" onClick={() => setOpen(false)}>
              Cancel
            </ButtonSecondary>
          </div>
          <p className="text-xs text-zinc-500">
            The staff member signs in with this email and password, then can only
            see and record their own shop.
          </p>
        </div>
      )}

      <ul className="divide-y divide-zinc-100">
        {staff.map((s) => {
          const shopName = shops.find((x) => x.id === s.shop_id)?.name;
          return (
            <li key={s.id} className="flex items-center justify-between gap-3 py-2">
              <div>
                <div className="text-sm font-medium text-zinc-900">{s.name}</div>
                <div className="text-xs text-zinc-500">
                  {shopName ?? "No shop"} · {s.role}
                </div>
              </div>
              <ButtonDanger
                className="h-8 px-2 text-xs"
                onClick={() => onRemove(s)}
              >
                Remove
              </ButtonDanger>
            </li>
          );
        })}
      </ul>
    </div>
  );
}
