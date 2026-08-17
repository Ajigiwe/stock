"use client";

import { useMemo, useState } from "react";
import Link from "next/link";
import type { DeviceRow, Shop } from "@/lib/data";
import { Badge, EmptyState, Input } from "@/components/ui";

type CondFilter = "all" | "new" | "used";

export function DevicesTable({
  shops,
  rows,
}: {
  shops: Shop[];
  rows: DeviceRow[];
}) {
  const [q, setQ] = useState("");
  const [cond, setCond] = useState<CondFilter>("all");
  const [lowOnly, setLowOnly] = useState(false);

  const filtered = useMemo(() => {
    const term = q.trim().toLowerCase();
    return rows.filter((r) => {
      if (cond !== "all" && r.condition !== cond) return false;
      if (lowOnly && r.low === 0) return false;
      if (term && !r.model_name.toLowerCase().includes(term)) return false;
      return true;
    });
  }, [rows, q, cond, lowOnly]);

  const cellClass = (low: boolean) =>
    low
      ? "text-red-600 font-bold"
      : "text-zinc-700 font-medium";

  return (
    <div className="space-y-3">
      <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <div className="relative sm:max-w-xs sm:flex-1">
          <svg
            aria-hidden="true"
            className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-zinc-400"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
          >
            <circle cx="11" cy="11" r="7" />
            <path d="M21 21l-4.3-4.3" />
          </svg>
          <Input
            value={q}
            onChange={(e) => setQ(e.target.value)}
            placeholder="Search models…"
            className="pl-9"
          />
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <div className="inline-flex rounded-lg border border-zinc-200 p-0.5">
            {(["all", "new", "used"] as CondFilter[]).map((c) => (
              <button
                key={c}
                type="button"
                onClick={() => setCond(c)}
                className={`h-8 rounded-md px-3 text-xs font-medium capitalize transition-colors ${
                  cond === c
                    ? "bg-zinc-900 text-white"
                    : "text-zinc-500 hover:text-zinc-800"
                }`}
              >
                {c}
              </button>
            ))}
          </div>
          <button
            type="button"
            onClick={() => setLowOnly((v) => !v)}
            className={`h-8 rounded-lg border px-3 text-xs font-medium transition-colors ${
              lowOnly
                ? "border-amber-300 bg-amber-50 text-amber-800"
                : "border-zinc-200 text-zinc-500 hover:text-zinc-800"
            }`}
          >
            Low stock
          </button>
        </div>
      </div>

      <p className="text-xs text-zinc-500">
        {filtered.length} of {rows.length} models · totals across {shops.length}{" "}
        shop{shops.length === 1 ? "" : "s"}
      </p>

      {filtered.length === 0 ? (
        <EmptyState>No models match your search.</EmptyState>
      ) : (
        <>
          <div className="hidden overflow-x-auto rounded-xl border border-zinc-200 bg-white shadow-sm sm:block">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-zinc-100 text-left text-xs uppercase tracking-wide text-zinc-400">
                  <th className="sticky left-0 bg-white py-2 pl-4 pr-4 font-medium">
                    Model
                  </th>
                  <th className="py-2 pr-4 font-medium">Condition</th>
                  {shops.map((s) => (
                    <th key={s.id} className="py-2 pr-4 text-right font-medium">
                      {s.name}
                    </th>
                  ))}
                  <th className="py-2 pr-4 text-right font-medium">Total</th>
                  <th className="py-2 pr-4 text-center font-medium">Low</th>
                </tr>
              </thead>
              <tbody>
                {filtered.map((r) => (
                  <tr key={r.key} className="border-b border-zinc-50">
                    <td className="sticky left-0 bg-white py-2 pl-4 pr-4 font-medium text-zinc-900">
                      {r.model_name}
                    </td>
                    <td className="py-2 pr-4">
                      <Badge tone={r.condition === "new" ? "blue" : "gray"}>
                        {r.condition}
                      </Badge>
                    </td>
                    {r.perShop.map((c) => (
                      <td key={c.shopId} className="py-2 pr-4 text-right">
                        {c.available > 0 ? (
                          <Link
                            href={`/shops/${c.shopId}`}
                            className={`tabular-nums hover:underline ${cellClass(c.low)}`}
                          >
                            {c.available}
                          </Link>
                        ) : (
                          <span className="text-zinc-300">—</span>
                        )}
                      </td>
                    ))}
                    <td className="py-2 pr-4 text-right font-bold tabular-nums text-zinc-900">
                      {r.total}
                    </td>
                    <td className="py-2 pr-4 text-center">
                      {r.low > 0 ? (
                        <span className="inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-red-100 px-1.5 text-xs font-semibold text-red-700">
                          {r.low}
                        </span>
                      ) : (
                        <span className="text-zinc-300">—</span>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          <ul className="space-y-2 sm:hidden">
            {filtered.map((r) => (
              <li
                key={r.key}
                className="rounded-xl border border-zinc-200 bg-white p-3 shadow-sm"
              >
                <div className="flex items-center justify-between gap-2">
                  <div className="flex flex-wrap items-center gap-2">
                    <span className="text-sm font-medium text-zinc-900">
                      {r.model_name}
                    </span>
                    <Badge tone={r.condition === "new" ? "blue" : "gray"}>
                      {r.condition}
                    </Badge>
                  </div>
                  <span className="text-sm font-bold tabular-nums text-zinc-900">
                    {r.total}
                  </span>
                </div>
                <div className="mt-2 flex flex-wrap gap-1.5">
                  {r.perShop
                    .filter((c) => c.available > 0)
                    .map((c) => {
                      const shop = shops.find((s) => s.id === c.shopId);
                      return (
                        <Link
                          key={c.shopId}
                          href={`/shops/${c.shopId}`}
                          className={`inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs ${
                            c.low
                              ? "border-red-200 bg-red-50 text-red-700"
                              : "border-zinc-200 bg-zinc-50 text-zinc-700"
                          }`}
                        >
                          <span className="font-medium">{shop?.name}</span>
                          <span className="font-bold tabular-nums">
                            {c.available}
                          </span>
                        </Link>
                      );
                    })}
                </div>
              </li>
            ))}
          </ul>
        </>
      )}
    </div>
  );
}