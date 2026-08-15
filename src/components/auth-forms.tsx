"use client";

import { useActionState } from "react";
import { login } from "@/lib/actions";
import { Button, ErrorNote, Field, Input } from "@/components/ui";

export function LoginForm({ next }: { next?: string }) {
  const [state, formAction, pending] = useActionState(login, { ok: true });

  return (
    <form action={formAction} className="space-y-4">
      <input type="hidden" name="next" value={next ?? "/"} />
      <Field label="Email">
        <Input name="email" type="email" required autoComplete="email" placeholder="you@example.com" />
      </Field>
      <Field label="Password">
        <Input name="password" type="password" required autoComplete="current-password" placeholder="••••••••" />
      </Field>
      <ErrorNote>{state.error}</ErrorNote>
      <Button type="submit" disabled={pending} className="w-full">
        {pending ? "Signing in…" : "Sign in"}
      </Button>
    </form>
  );
}
