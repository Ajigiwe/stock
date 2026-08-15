"use client";

import { useActionState } from "react";
import { login, signup } from "@/lib/actions";
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

export function SignupForm() {
  const [state, formAction, pending] = useActionState(signup, { ok: true });

  if (state.ok) {
    return (
      <div className="space-y-3 text-sm text-zinc-700">
        <div className="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-emerald-800">
          Account created. You can now sign in.
        </div>
        <p>
          If you are the owner, after your first sign-in run{" "}
          <code className="rounded bg-zinc-100 px-1 py-0.5 font-mono text-xs">
            select public.claim_owner();
          </code>{" "}
          in the Supabase SQL editor (or use the &quot;Claim owner&quot; button on
          the dashboard) to activate the owner role.
        </p>
      </div>
    );
  }

  return (
    <form action={formAction} className="space-y-4">
      <Field label="Full name">
        <Input name="name" required placeholder="e.g. Jeff" />
      </Field>
      <Field label="Email">
        <Input name="email" type="email" required autoComplete="email" placeholder="you@example.com" />
      </Field>
      <Field label="Password">
        <Input name="password" type="password" required minLength={6} autoComplete="new-password" placeholder="At least 6 characters" />
      </Field>
      <ErrorNote>{state.error}</ErrorNote>
      <Button type="submit" disabled={pending} className="w-full">
        {pending ? "Creating account…" : "Create account"}
      </Button>
    </form>
  );
}
