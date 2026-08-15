"use client";

import { useState, useTransition } from "react";
import { changePassword } from "@/lib/actions";
import { Button, ErrorNote, Field, Input } from "@/components/ui";
import { useToast } from "@/components/feedback";

export function ChangePasswordForm() {
  const toast = useToast();
  const [error, setError] = useState<string | null>(null);
  const [pending, startTransition] = useTransition();

  const onSubmit = (e: React.FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    const form = e.currentTarget;
    const fd = new FormData(form);
    startTransition(async () => {
      setError(null);
      const res = await changePassword({ ok: true }, fd);
      if (!res.ok) return setError(res.error ?? "Could not update password.");
      toast.success("Password updated.");
      form.reset();
    });
  };

  return (
    <form onSubmit={onSubmit} className="space-y-3">
      <div className="grid gap-3 sm:grid-cols-2">
        <Field label="New password">
          <Input
            name="password"
            type="password"
            required
            autoComplete="new-password"
            placeholder="min 6 characters"
          />
        </Field>
        <Field label="Confirm new password">
          <Input
            name="confirm"
            type="password"
            required
            autoComplete="new-password"
            placeholder="re-enter password"
          />
        </Field>
      </div>
      <ErrorNote>{error}</ErrorNote>
      <Button type="submit" disabled={pending} className="h-9 text-sm">
        {pending ? "Updating…" : "Update password"}
      </Button>
    </form>
  );
}
