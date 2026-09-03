"use client";

import { useState, useTransition } from "react";
import { useRouter } from "next/navigation";
import type { Database } from "@/lib/database.types";
import { submitDailyClose, lockDailyClose } from "@/lib/actions";
import { formatMoney } from "@/lib/format";
import { Badge, Button, ButtonSecondary, ErrorNote, Field, Input } from "@/components/ui";
import { useToast } from "@/components/feedback";

type Close = Database["public"]["Tables"]["daily_closes"]["Row"];

export function DailyClosePanel({
  shopId,
  date,
  close,
  isOwner,
}: {
  shopId: string;
  date: string;
  close: Close | null;
  isOwner: boolean;
}) {
  const router = useRouter();
  const toast = useToast();
  const [cash, setCash] = useState(close?.counted_cash == null ? "" : String(close.counted_cash));
  const [mobile, setMobile] = useState(close?.counted_mobile_money == null ? "" : String(close.counted_mobile_money));
  const [other, setOther] = useState(close?.counted_other == null ? "0" : String(close.counted_other));
  const [notes, setNotes] = useState(close?.notes ?? "");
  const [error, setError] = useState<string | null>(null);
  const [pending, startTransition] = useTransition();

  const variance = (expected: number, counted: string | number | null) =>
    counted == null || counted === "" ? null : Number(counted) - expected;
  const cashVariance = variance(close?.expected_cash ?? 0, cash);
  const mobileVariance = variance(close?.expected_mobile_money ?? 0, mobile);
  const otherVariance = variance(close?.expected_other ?? 0, other);
  const hasVariance = [cashVariance, mobileVariance, otherVariance].some((v) => v != null && Math.abs(v) > 0.009);

  const submit = () => startTransition(async () => {
    setError(null);
    const res = await submitDailyClose({ shopId, date, countedCash: cash, countedMobileMoney: mobile, countedOther: other, notes });
    if (!res.ok) return setError(res.error ?? "Could not submit close.");
    toast.success("Daily counts submitted.");
    router.refresh();
  });

  const lock = () => startTransition(async () => {
    setError(null);
    if (!close) return setError("Submit the counted amounts first.");
    const res = await lockDailyClose(close.id);
    if (!res.ok) return setError(res.error ?? "Could not lock close.");
    toast.success("Daily close locked.");
    router.refresh();
  });

  return (
    <div className="space-y-3">
      {close && (
        <div className="flex flex-wrap items-center gap-2 text-xs">
          <Badge tone={close.status === "locked" ? "green" : "amber"}>{close.status === "locked" ? "Locked" : "Open"}</Badge>
          {hasVariance && <Badge tone="red">Variance needs review</Badge>}
        </div>
      )}
      <div className="grid gap-3 sm:grid-cols-3">
        <CloseField label="Cash counted" value={cash} expected={close?.expected_cash ?? 0} disabled={close?.status === "locked"} onChange={setCash} />
        <CloseField label="Mobile money counted" value={mobile} expected={close?.expected_mobile_money ?? 0} disabled={close?.status === "locked"} onChange={setMobile} />
        <CloseField label="Other counted" value={other} expected={close?.expected_other ?? 0} disabled={close?.status === "locked"} onChange={setOther} />
      </div>
      <Field label="Notes">
        <Input value={notes} disabled={close?.status === "locked"} onChange={(e) => setNotes(e.target.value)} placeholder="Explain any variance or handover" />
      </Field>
      <ErrorNote>{error}</ErrorNote>
      <div className="flex flex-wrap gap-2">
        {close?.status !== "locked" && <Button disabled={pending} onClick={submit}>{pending ? "Saving…" : "Submit counts"}</Button>}
        {isOwner && close?.status === "open" && <ButtonSecondary disabled={pending} onClick={lock}>Lock close</ButtonSecondary>}
      </div>
      {close && <p className="text-xs text-mute">Expected totals are based only on completed transactions. Pending reviews and queued repairs are excluded.</p>}
    </div>
  );
}

function CloseField({ label, value, expected, disabled, onChange }: { label: string; value: string; expected: number; disabled?: boolean; onChange: (value: string) => void }) {
  const n = value === "" ? null : Number(value) - expected;
  return (
    <div>
      <Field label={label}><Input type="number" min="0" step="0.01" value={value} disabled={disabled} onChange={(e) => onChange(e.target.value)} /></Field>
      <div className={`mt-1 text-xs ${n != null && Math.abs(n) > 0.009 ? "font-semibold text-lowstock" : "text-mute"}`}>Expected {formatMoney(expected)}{n != null ? ` · ${n >= 0 ? "+" : ""}${formatMoney(n)}` : ""}</div>
    </div>
  );
}
