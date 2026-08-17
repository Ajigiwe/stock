"use client";

import { useMemo, useState } from "react";
import type { PhoneModel, StockAdjustment } from "@/lib/data";
import { formatMoney } from "@/lib/format";
import { Badge, EmptyState, Input } from "@/components/ui";
import { ProductEditModal } from "@/components/product-edit-modal";

type CondFilter = "all" | "new" | "used";

export function StockTable({
  stock,
  shopId,
  canEditStock,
  adjustments,
}: {
  stock: PhoneModel[];
  shopId: string;
  canEditStock: boolean;
  adjustments: StockAdjustment[];
}) {
  const [q, setQ] = useState("");
  const [cond, setCond] = useState<CondFilter>("all");
  const [lowOnly, setLowOnly] = useState(false);

  const filtered = useMemo(() => {
    const term = q.trim().toLowerCase();
    return stock.filter((m) => {
      if (cond !== "all" && m.condition !== cond) return false;
      if (lowOnly && !(m.available <= m.low_stock_threshold)) return false;
      if (term && !m.model_name.toLowerCase().includes(term)) return false;
      return true;
    });
  }, [stock, q, cond, lowOnly]);

  const totals = useMemo(() => {
    let units = 0;
    let cost = 0;
    let retail = 0;
    for (const m of filtered) {
      units += m.available;
      if (m.cost_price != null) cost += m.cost_price * m.available;
      if (m.sale_price != null) retail += m.sale_price * m.available;
    }
    return { units, cost, retail };
  }, [filtered]);

  if (stock.length === 0) {
    return <EmptyState>No models yet. Add the first one above.</EmptyState>;
  }

  const adjustmentsFor = (id: string) =>
    adjustments.filter((a) => a.phone_model_id === id);

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

      <div className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-zinc-500">
        <span>
          {filtered.length} of {stock.length} models
        </span>
        <span>{totals.units} units</span>
        {canEditStock && totals.cost > 0 && (
          <span>
            Stock value (cost):{" "}
            <b className="text-zinc-700">{formatMoney(totals.cost)}</b>
          </span>
        )}
        {canEditStock && totals.retail > 0 && (
          <span>
            Retail value:{" "}
            <b className="text-zinc-700">{formatMoney(totals.retail)}</b>
          </span>
        )}
      </div>

      {filtered.length === 0 ? (
        <EmptyState>No models match your search.</EmptyState>
      ) : (
        <>
          <div className="hidden overflow-x-auto sm:block">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-zinc-100 text-left text-xs uppercase tracking-wide text-zinc-400">
                  <th className="py-2 pr-4 font-medium">Model</th>
                  <th className="py-2 pr-4 font-medium">Condition</th>
                  <th className="py-2 pr-4 text-right font-medium">Cost</th>
                  <th className="py-2 pr-4 text-right font-medium">Sale</th>
                  <th className="py-2 pr-4 text-right font-medium">Opening</th>
                  <th className="py-2 pr-4 text-right font-medium">Bought</th>
                  <th className="py-2 pr-4 text-right font-medium">Available</th>
                  <th className="py-2 text-left font-medium">Actions</th>
                </tr>
              </thead>
              <tbody>
                {filtered.map((m) => {
                  const low = m.available <= m.low_stock_threshold;
                  return (
                    <tr key={m.id} className="border-b border-zinc-50">
                      <td className="py-2 pr-4 font-medium text-zinc-900">
                        {m.model_name}
                        {low && (
                          <span className="ml-2">
                            <Badge tone="amber">low</Badge>
                          </span>
                        )}
                      </td>
                      <td className="py-2 pr-4">
                        <Badge tone={m.condition === "new" ? "blue" : "gray"}>{m.condition}</Badge>
                      </td>
                      <td className="py-2 pr-4 text-right text-zinc-500">
                        {m.cost_price != null ? formatMoney(m.cost_price) : "—"}
                      </td>
                      <td className="py-2 pr-4 text-right text-zinc-500">
                        {m.sale_price != null ? formatMoney(m.sale_price) : "—"}
                      </td>
                      <td className="py-2 pr-4 text-right">{m.opening_stock}</td>
                      <td className="py-2 pr-4 text-right">{m.bought_in}</td>
                      <td className={`py-2 pr-4 text-right font-bold ${low ? "text-red-600" : "text-zinc-900"}`}>
                        {m.available}
                      </td>
                      <td className="py-2">
                        <ProductEditModal
                          model={m}
                          shopId={shopId}
                          canEditStock={canEditStock}
                          adjustments={adjustmentsFor(m.id)}
                        />
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
          <ul className="space-y-2 sm:hidden">
            {filtered.map((m) => {
              const low = m.available <= m.low_stock_threshold;
              return (
                <li key={m.id} className="rounded-lg border border-zinc-200 bg-zinc-50 p-3">
                  <div className="flex items-start justify-between gap-2">
                    <div className="min-w-0">
                      <div className="flex flex-wrap items-center gap-2">
                        <span className="truncate text-sm font-medium text-zinc-900">{m.model_name}</span>
                        {low && <Badge tone="amber">low</Badge>}
                      </div>
                      <div className="mt-1 flex flex-wrap items-center gap-2 text-xs text-zinc-500">
                        <Badge tone={m.condition === "new" ? "blue" : "gray"}>{m.condition}</Badge>
                        <span>Cost {m.cost_price != null ? formatMoney(m.cost_price) : "—"}</span>
                        <span>Sale {m.sale_price != null ? formatMoney(m.sale_price) : "—"}</span>
                      </div>
                    </div>
                    <ProductEditModal
                      model={m}
                      shopId={shopId}
                      canEditStock={canEditStock}
                      adjustments={adjustmentsFor(m.id)}
                    />
                  </div>
                  <div className="mt-2 grid grid-cols-3 gap-2 text-center">
                    <div className="rounded-lg bg-white px-2 py-1.5">
                      <div className="text-[11px] font-medium uppercase tracking-wide text-zinc-400">Opening</div>
                      <div className="text-sm font-semibold text-zinc-900">{m.opening_stock}</div>
                    </div>
                    <div className="rounded-lg bg-white px-2 py-1.5">
                      <div className="text-[11px] font-medium uppercase tracking-wide text-zinc-400">Bought</div>
                      <div className="text-sm font-semibold text-zinc-900">{m.bought_in}</div>
                    </div>
                    <div className={`rounded-lg bg-white px-2 py-1.5 ${low ? "ring-1 ring-red-200" : ""}`}>
                      <div className="text-[11px] font-medium uppercase tracking-wide text-zinc-400">Available</div>
                      <div className={`text-sm font-bold ${low ? "text-red-600" : "text-zinc-900"}`}>{m.available}</div>
                    </div>
                  </div>
                </li>
              );
            })}
          </ul>
        </>
      )}
    </div>
  );
}
