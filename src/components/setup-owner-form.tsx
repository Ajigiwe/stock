"use client";

import { useActionState } from "react";
import Link from "next/link";
import { setupOwner } from "@/lib/actions";
import { Button, ErrorNote, Field, Input } from "@/components/ui";

export function SetupOwnerForm() {
  const [state, formAction, pending] = useActionState(setupOwner, { ok: false });

  if (state.ok) {
    return (
      <div className="space-y-3 text-sm text-zinc-700">
        <div className="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-emerald-800">
          Owner account created. You can now sign in.
        </div>
        <p>
          Add your shops and staff from <strong>Settings</strong> after signing
          in. Staff accounts are created there and cannot sign up on their own.
        </p>
        <Link
          href="/login"
          className="inline-block rounded-lg bg-zinc-900 px-4 py-2 text-sm font-medium text-white hover:bg-zinc-700"
        >
          Go to sign in
        </Link>
      </div>
    );
  }

  return (
    <form action={formAction} className="space-y-4">
      <Field label="Setup secret">
        <Input name="secret" type="password" required autoComplete="off" placeholder="OWNER_SETUP_SECRET value" />
      </Field>
      <Field label="Owner full name">
        <Input name="name" required placeholder="e.g. Jeff" />
      </Field>
      <Field label="Owner email">
        <Input name="email" type="email" required autoComplete="email" placeholder="you@example.com" />
      </Field>
      <Field label="Password">
        <Input name="password" type="password" required minLength={6} autoComplete="new-password" placeholder="At least 6 characters" />
      </Field>
      <ErrorNote>{state.error}</ErrorNote>
      <Button type="submit" disabled={pending} className="w-full">
        {pending ? "Creating owner…" : "Create owner account"}
      </Button>
    </form>
  );
}
