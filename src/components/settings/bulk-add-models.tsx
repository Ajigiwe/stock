"use client";

import { useState, useTransition } from "react";
import { useRouter } from "next/navigation";
import { bulkCreateModels } from "@/lib/actions";
import type { BulkModelRow } from "@/lib/actions";
import type { Shop } from "@/lib/data";
import {
  Button,
  ButtonSecondary,
  ErrorNote,
  Field,
  Select,
  Textarea,
} from "@/components/ui";

const HEADERS =
  "model_name,condition,cost_price,sale_price,opening_stock,low_stock_threshold";
const EXAMPLE =
  `iPhone 14 128GB,new,3500,4200,5,3\n` +
  `iPhone 13 128GB,used,2600,3200,3,3\n` +
  `Samsung Galaxy A15,new,1100,1450,8,3`;

function parseCsv(text: string): BulkModelRow[] {
  const lines = text
    .split(/\r?\n/)
    .map((l) => l.trim())
    .filter(Boolean);

  let start = 0;
  if (lines.length && /model/i.test(lines[0])) start = 1;

  const rows: BulkModelRow[] = [];
  for (let i = start; i < lines.length; i++) {
    const c = lines[i]
      .split(",")
      .map((x) => x.replace(/^"|"$/g, "").trim());
    if (!c[0]) continue;
    rows.push({
      model_name: c[0],
      condition: c[1]?.toLowerCase() === "used" ? "used" : "new",
      cost_price: c[2] ?? "",
      sale_price: c[3] ?? "",
      opening_stock: c[4] ?? "0",
      low_stock_threshold: c[5] ?? "5",
    });
  }
  return rows;
}

export function BulkAddModels({ shops }: { shops: Shop[] }) {
  const router = useRouter();
  const [shopId, setShopId] = useState("");
  const [text, setText] = useState("");
  const [preview, setPreview] = useState<BulkModelRow[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [info, setInfo] = useState<string | null>(null);
  const [pending, startTransition] = useTransition();

  const loadFile = (file: File | undefined) => {
    if (!file) return;
    const reader = new FileReader();
    reader.onload = () => {
      setText(String(reader.result ?? ""));
      setPreview(null);
    };
    reader.readAsText(file);
  };

  const onPreview = () => {
    setError(null);
    setInfo(null);
    const rows = parseCsv(text);
    if (!rows.length) return setError("No rows found. Check the format below.");
    setPreview(rows);
  };

  const onImport = () => {
    if (!shopId) return setError("Select a shop first.");
    if (!preview?.length) return setError("Preview the rows before importing.");
    startTransition(async () => {
      setError(null);
      setInfo(null);
      const res = await bulkCreateModels(shopId, preview);
      if (!res.ok) return setError(res.error ?? "Import failed.");

      const skippedText = res.skipped?.length
        ? ` Skipped ${res.skipped.length}: ${res.skipped
            .slice(0, 5)
            .map((s) => `${s.name} (${s.reason})`)
            .join(", ")}${res.skipped.length > 5 ? "…" : ""}.`
        : "";
      setInfo(`${res.added ?? 0} devices added.${skippedText}`);
      setText("");
      setPreview(null);
      router.refresh();
    });
  };

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-end gap-3">
        <div className="min-w-48 flex-1">
          <Field label="Shop">
            <Select value={shopId} onChange={(e) => setShopId(e.target.value)}>
              <option value="">Select a shop…</option>
              {shops.map((s) => (
                <option key={s.id} value={s.id}>
                  {s.name}
                </option>
              ))}
            </Select>
          </Field>
        </div>
        <ButtonSecondary
          className="h-10 text-xs"
          onClick={() => {
            const blob = new Blob([HEADERS + "\n" + EXAMPLE], {
              type: "text/csv;charset=utf-8",
            });
            const url = URL.createObjectURL(blob);
            const a = document.createElement("a");
            a.href = url;
            a.download = "devices-template.csv";
            a.click();
            URL.revokeObjectURL(url);
          }}
        >
          Download template
        </ButtonSecondary>
        <label className="inline-flex h-10 cursor-pointer items-center gap-2 rounded-lg border border-line bg-white px-3 text-xs font-medium text-ink/80 hover:bg-paper">
          Load CSV file
          <input
            type="file"
            accept=".csv,text/csv"
            className="hidden"
            onChange={(e) => loadFile(e.target.files?.[0])}
          />
        </label>
      </div>

      <Textarea
        rows={6}
        value={text}
        onChange={(e) => {
          setText(e.target.value);
          setPreview(null);
        }}
        placeholder={`Paste rows here, or load a CSV file.\n${HEADERS}\n${EXAMPLE}`}
      />

      <div className="flex flex-wrap items-center gap-2">
        <ButtonSecondary className="h-8 text-xs" onClick={onPreview}>
          Preview rows
        </ButtonSecondary>
        <Button className="h-8 text-xs" disabled={pending} onClick={onImport}>
          {pending ? "Importing…" : "Import devices"}
        </Button>
      </div>

      {preview && (
        <div className="rounded-lg border border-line bg-paper p-3">
          <p className="text-xs font-medium text-ink/80">
            {preview.length} row{preview.length === 1 ? "" : "s"} ready — will
            skip models that already exist in this shop.
          </p>
          <ul className="mt-2 max-h-40 space-y-1 overflow-y-auto text-xs text-mute">
            {preview.slice(0, 20).map((r, i) => (
              <li key={i}>
                {r.model_name} · {r.condition} · GHS {r.sale_price || "—"} · opening{" "}
                {r.opening_stock || "0"}
              </li>
            ))}
            {preview.length > 20 && (
              <li className="text-mute">…and {preview.length - 20} more</li>
            )}
          </ul>
        </div>
      )}

      <ErrorNote>{error}</ErrorNote>
      {info && (
        <div className="rounded-lg border border-instock bg-instock-tint px-3 py-2 text-xs text-instock">
          {info}
        </div>
      )}
    </div>
  );
}
