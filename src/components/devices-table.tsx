"use client";

import { useMemo, useState } from "react";
import Link from "next/link";
import type { DeviceRow, Shop } from "@/lib/data";
import { formatMoney, formatDateTime } from "@/lib/format";
import { Badge, EmptyState, Input, Modal, Select } from "@/components/ui";

type CondFilter = "all" | "new" | "used";

type Level = "empty" | "low" | "medium" | "ok";

const level = (available: number, threshold: number): Level =>
  available <= 0
    ? "empty"
    : available <= threshold
      ? "low"
      : available <= threshold * 2
        ? "medium"
        : "ok";

const levelClass: Record<Level, string> = {
  empty: "text-line",
  low: "text-lowstock font-bold",
  medium: "text-brand font-semibold",
  ok: "text-instock font-semibold",
};

const chipClass: Record<Level, string> = {
  empty: "border-line bg-paper text-mute",
  low: "border-lowstock bg-lowstock-tint text-lowstock",
  medium: "border-brand bg-brand-tint text-brand",
  ok: "border-instock bg-instock-tint text-instock",
};

function SalesList({ row }: { row: DeviceRow }) {
  if (row.sales.length === 0) {
    return <EmptyState>No sales recorded for this model yet.</EmptyState>;
  }
  return (
    <div className="overflow-x-auto">
      <table className="w-full text-sm">
        <thead>
          <tr className="border-b border-line text-left text-xs uppercase tracking-wide text-mute">
            <th className="py-1.5 pr-4 font-medium">Sold by</th>
            <th className="py-1.5 pr-4 font-medium">To</th>
            <th className="py-1.5 pr-4 font-medium">Shop</th>
            <th className="py-1.5 pr-4 font-medium">Date</th>
            <th className="py-1.5 pr-4 text-right font-medium">Qty</th>
            <th className="py-1.5 text-right font-medium">Amount</th>
          </tr>
        </thead>
        <tbody>
          {row.sales.map((s) => (
            <tr key={s.transactionId + s.date + s.qty} className="border-b border-paper">
              <td className="py-1.5 pr-4 font-medium text-ink">
                {s.staffName ?? "—"}
              </td>
              <td className="py-1.5 pr-4 text-ink/70">
                {s.customerName ?? "Walk-in"}
                {s.customerPhone ? (
                  <span className="ml-1 text-xs text-mute">({s.customerPhone})</span>
                ) : null}
              </td>
              <td className="py-1.5 pr-4 text-mute">{s.shopName ?? "—"}</td>
              <td className="py-1.5 pr-4 text-mute">{formatDateTime(s.date)}</td>
              <td className="py-1.5 pr-4 text-right tabular-nums">{s.qty}</td>
              <td className="py-1.5 text-right font-semibold tabular-nums text-ink">
                {formatMoney(s.amount)}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function ModelDetail({ row, shops, shopId }: { row: DeviceRow; shops: Shop[]; shopId: string }) {
  const scopeLabel = shopId
    ? shops.find((s) => s.id === shopId)?.name
    : "all shops";
  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center gap-2">
        <Badge tone={row.condition === "new" ? "blue" : "gray"}>
          {row.condition}
        </Badge>
        <span className="text-sm text-mute">
          <span className="font-semibold tabular-nums text-ink">
            {row.total}
          </span>{" "}
          available ·{" "}
          <span className="font-semibold tabular-nums text-ink">
            {row.sold}
          </span>{" "}
          sold across {scopeLabel}
        </span>
      </div>

      <div>
        <h3 className="mb-1.5 text-xs font-semibold uppercase tracking-wide text-mute">
          Stock by shop
        </h3>
        <div className="flex flex-wrap gap-1.5">
          {row.perShop.filter((c) => c.available > 0).length === 0 ? (
            <p className="text-sm text-mute">No stock in this shop.</p>
          ) : (
            row.perShop
              .filter((c) => c.available > 0 && (!shopId || c.shopId === shopId))
              .map((c) => {
                const shop = shops.find((s) => s.id === c.shopId);
                return (
                  <Link
                    key={c.shopId}
                    href={`/shops/${c.shopId}`}
                    className={`inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs ${chipClass[level(c.available, c.threshold)]}`}
                  >
                    <span className="font-medium">{shop?.name}</span>
                    <span className="font-bold tabular-nums">{c.available}</span>
                  </Link>
                );
              })
          )}
        </div>
      </div>

      <div>
        <h3 className="mb-1.5 text-xs font-semibold uppercase tracking-wide text-mute">
          Sold by / to
        </h3>
        <SalesList row={row} />
      </div>
    </div>
  );
}

export function DevicesTable({ shops, rows }: { shops: Shop[]; rows: DeviceRow[] }) {
  const [q, setQ] = useState("");
  const [cond, setCond] = useState<CondFilter>("all");
  const [lowOnly, setLowOnly] = useState(false);
  const [shopId, setShopId] = useState("");
  const [selected, setSelected] = useState<DeviceRow | null>(null);

  const scoped = useMemo(() => {
    if (!shopId) return rows;
    return rows.map((r) => {
      const cell = r.perShop.find((c) => c.shopId === shopId);
      const sales = r.sales.filter((s) => s.shopId === shopId);
      return {
        ...r,
        total: cell?.available ?? 0,
        low: cell?.low ? 1 : 0,
        sold: sales.reduce((a, s) => a + s.qty, 0),
        sales,
      };
    });
  }, [rows, shopId]);

  const filtered = useMemo(() => {
    const term = q.trim().toLowerCase();
    return scoped.filter((r) => {
      if (cond !== "all" && r.condition !== cond) return false;
      if (lowOnly && r.low === 0) return false;
      if (term && !r.model_name.toLowerCase().includes(term)) return false;
      return true;
    });
  }, [scoped, q, cond, lowOnly]);

  const displayShops = shopId ? shops.filter((s) => s.id === shopId) : shops;
  const scopeLabel = shopId
    ? shops.find((s) => s.id === shopId)?.name
    : "All shops";

  const cellClass = (c: DeviceRow["perShop"][number]) =>
    levelClass[level(c.available, c.threshold)];

  const statusDot = (r: DeviceRow) =>
    r.total <= 0 ? "bg-line" : r.low > 0 ? "bg-lowstock" : "bg-instock";

  return (
    <div className="space-y-3">
      {/* Filters */}
      <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <div className="relative sm:max-w-xs sm:flex-1">
          <svg
            aria-hidden="true"
            className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-mute"
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
          <Select
            value={shopId}
            onChange={(e) => setShopId(e.target.value)}
            aria-label="Filter by shop"
            className="h-9 w-auto min-w-[9rem] text-xs"
          >
            <option value="">All shops</option>
            {shops.map((s) => (
              <option key={s.id} value={s.id}>{s.name}</option>
            ))}
          </Select>
          <div className="inline-flex rounded-lg border border-line p-0.5">
            {(["all", "new", "used"] as CondFilter[]).map((c) => (
              <button
                key={c}
                type="button"
                onClick={() => setCond(c)}
                className={`h-8 rounded-md px-3 text-xs font-medium capitalize transition-colors ${
                  cond === c
                    ? "bg-ink text-white"
                    : "text-mute hover:text-ink"
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
                ? "border-brand bg-brand-tint text-brand"
                : "border-line text-mute hover:text-ink"
            }`}
          >
            Low stock
          </button>
        </div>
      </div>

      <p className="text-xs text-mute">
        {filtered.length} of {rows.length} models · tap a row to see stock by
        shop and who sold each one
      </p>

      {/* Legend */}
      <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-mute">
        <span className="inline-flex items-center gap-1.5">
          <span className="h-2 w-2 rounded-full bg-instock" /> Healthy
        </span>
        <span className="inline-flex items-center gap-1.5">
          <span className="h-2 w-2 rounded-full bg-brand" /> Running low
        </span>
        <span className="inline-flex items-center gap-1.5">
          <span className="h-2 w-2 rounded-full bg-lowstock" /> Low stock
        </span>
        <span className="inline-flex items-center gap-1.5">
          <span className="font-bold text-instock">3</span> units sold
        </span>
      </div>

      {filtered.length === 0 ? (
        <EmptyState>No models match your search.</EmptyState>
      ) : (
        <>
          {/* Desktop matrix */}
          <div className="hidden overflow-x-auto rounded-xl border border-line bg-white shadow-sm sm:block">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-line text-left text-xs uppercase tracking-wide text-mute">
                  <th className="sticky left-0 bg-white py-2 pl-4 pr-2 font-medium">
                    Model
                  </th>
                  <th className="py-2 pr-2 font-medium">Condition</th>
                  {displayShops.map((s) => (
                    <th key={s.id} className="py-2 pr-3 text-right font-medium">
                      {s.name}
                    </th>
                  ))}
                  <th className="py-2 pr-3 text-right font-medium">Total</th>
                  <th className="py-2 pr-3 text-right font-medium">Sold</th>
                  <th className="py-2 pr-2 text-center font-medium">Low</th>
                </tr>
              </thead>
              <tbody>
                {filtered.map((r) => (
                  <tr
                    key={r.key}
                    className="cursor-pointer border-b border-paper hover:bg-paper/60"
                    onClick={() => setSelected(r)}
                  >
                    <td className="sticky left-0 bg-white py-2 pl-4 pr-2 font-medium text-ink hover:text-brand">
                      {r.model_name}
                    </td>
                    <td className="py-2 pr-2">
                      <Badge tone={r.condition === "new" ? "blue" : "gray"}>
                        {r.condition}
                      </Badge>
                    </td>
                    {displayShops.map((s) => {
                      const c = r.perShop.find((x) => x.shopId === s.id);
                      return (
                        <td key={s.id} className="py-2 pr-3 text-right">
                          {c && c.available > 0 ? (
                            <Link
                              href={`/shops/${s.id}`}
                              onClick={(e) => e.stopPropagation()}
                              className={`tabular-nums hover:underline ${cellClass(c)}`}
                            >
                              {c.available}
                            </Link>
                          ) : (
                            <span className="text-line">—</span>
                          )}
                        </td>
                      );
                    })}
                    <td className="py-2 pr-3 text-right font-bold tabular-nums text-ink">
                      {r.total}
                    </td>
                    <td
                      className={`py-2 pr-3 text-right font-bold tabular-nums ${
                        r.sold > 0 ? "text-instock" : "text-mute"
                      }`}
                    >
                      {r.sold}
                    </td>
                    <td className="py-2 pr-4 text-center">
                      {r.low > 0 ? (
                        <span className="inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-lowstock-tint px-1.5 text-xs font-semibold text-lowstock">
                          {r.low}
                        </span>
                      ) : (
                        <span className="text-line">—</span>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {/* Mobile compact rows */}
          <ul className="space-y-1.5 sm:hidden">
            {filtered.map((r) => (
              <li
                key={r.key}
                className="cursor-pointer rounded-xl border border-line bg-white px-3 py-2.5 transition-colors active:bg-paper"
                onClick={() => setSelected(r)}
              >
                <div className="flex items-center gap-2.5">
                  <span className={`h-2 w-2 shrink-0 rounded-full ${statusDot(r)}`} />
                  <span className="truncate text-[13.5px] font-bold text-ink">
                    {r.model_name}
                  </span>
                  <Badge tone={r.condition === "new" ? "blue" : "gray"}>
                    {r.condition}
                  </Badge>
                  <span className="flex-1" />
                  <span className="text-right text-[13px] tabular-nums">
                    <span className="font-mono font-bold text-ink">{r.total}</span>
                    <span className="text-mute"> avail</span>
                    <span className="mx-1 text-line">·</span>
                    <span className={`font-mono font-bold ${r.sold > 0 ? "text-instock" : "text-mute"}`}>{r.sold}</span>
                    <span className="text-mute"> sold</span>
                  </span>
                </div>
              </li>
            ))}
          </ul>
        </>
      )}

      {/* Detail modal */}
      <Modal
        open={selected !== null}
        onClose={() => setSelected(null)}
        title={selected?.model_name}
        subtitle={selected ? `Details · ${scopeLabel}` : undefined}
        size="xl"
      >
        {selected && (
          <ModelDetail row={selected} shops={shops} shopId={shopId} />
        )}
      </Modal>
    </div>
  );
}