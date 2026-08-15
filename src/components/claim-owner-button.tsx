"use client";

import { useState, useTransition } from "react";
import { claimOwner } from "@/lib/actions";
import { Button } from "@/components/ui";

export function ClaimOwnerButton() {
  const [error, setError] = useState<string | null>(null);
  const [pending, startTransition] = useTransition();

  return (
    <div className="space-y-3">
      {error && (
        <div className="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
          {error}
        </div>
      )}
      <Button
        disabled={pending}
        onClick={() =>
          startTransition(async () => {
            const res = await claimOwner();
            if (!res.ok) setError(res.error ?? "Something went wrong.");
          })
        }
      >
        {pending ? "Claiming…" : "Claim owner role"}
      </Button>
    </div>
  );
}
