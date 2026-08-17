"use client";

import { useMemo, useState } from "react";
import Link from "next/link";
import type { DeviceRow, Shop } from "@/lib/data";
import { formatMoney, formatDateTime } from "@/lib/format";
import { Badge, EmptyState, Input, Modal, Select } from "@/components/ui";

type CondFilter = "all" | "new" | "used";

function SalesList({ row }: { row: DeviceRow }) {
  if (row.sales.length === 0) {
    return <EmptyState>No sales recorded for this model yet.</EmptyState>;
  }
  return (
    <div className="overflow-x-auto">
      <table className="w-full text-sm">
        <thead>
          <tr className="border-b border-zinc-100 text-left text-xs uppercase tracking-wide text-zinc-400">
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
            <tr key={s.transactionId + s.date + s.qty} className="border-b border-zinc-50">
              <td className="py-1.5 pr-4 font-medium text-zinc-900">
                {s.staffName ?? "—"}
              </td>
              <td className="py-1.5 pr-4 text-zinc-700">
                {s.customerName ?? "Walk-in"}
                {s.customerPhone ? (
                  <span className="ml-1 text-xs text-zinc-400">({s.customerPhone})</span>
                ) : null}
              </td>
              <td className="py-1.5 pr-4 text-zinc-500">{s.shopName ?? "—"}</td>
              <td className="py-1.5 pr-4 text-zinc-500">{formatDateTime(s.date)}</td>
              <td className="py-1.5 pr-4 text-right tabular-nums">{s.qty}</td>
              <td className="py-1.5 text-right font-semibold tabular-nums text-zinc-900">
                {formatMoney(s.amount)}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function ModelDetail({
  row,
  shops,
  shopId,
}: {
  row: DeviceRow;
  shops: Shop[];
  shopId: string;
}) {
  const scopeLabel = shopId
    ? shops.find((s) => s.id === shopId)?.name
    : "all shops";
  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center gap-2">
        <Badge tone={row.condition === "new" ? "blue" : "gray"}>
          {row.condition}
        </Badge>
        <span className="text-sm text-zinc-500">
          <span className="font-semibold tabular-nums text-zinc-900">
            {row.total}
          </span>{" "}
          available ·{" "}
          <span className="font-semibold tabular-nums text-zinc-900">
            {row.sold}
          </span>{" "}
          sold across {scopeLabel}
        </span>
      </div>

      <div>
        <h3 className="mb-1.5 text-xs font-semibold uppercase tracking-wide text-zinc-400">
          Stock by shop
        </h3>
        <div className="flex flex-wrap gap-1.5">
          {row.perShop.filter((c) => c.available > 0).length === 0 ? (
            <p className="text-sm text-zinc-400">No stock in this shop.</p>
          ) : (
            row.perShop
              .filter((c) => c.available > 0 && (!shopId || c.shopId === shopId))
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
                    <span className="font-bold tabular-nums">{c.available}</span>
                  </Link>
                );
              })
          )}
        </div>
      </div>

      <div>
        <h3 className="mb-1.5 text-xs font-semibold uppercase tracking-wide text-zinc-400">
          Sold by / to
        </h3>
        <SalesList row={row} />
      </div>
    </div>
  );
}

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
  const [shopId, setShopId] = useState("");
  const [selected, setSelected] = useState<DeviceRow | null>(null);

  // When a shop is selected, narrow totals / sold / sales to that shop only.
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

  const cellClass = (low: boolean) =>
    low ? "text-red-600 font-bold" : "text-zinc-700 font-medium";

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
          <Select
            value={shopId}
            onChange={(e) => setShopId(e.target.value)}
            aria-label="Filter by shop"
            className="h-9 w-auto min-w-[9rem] text-xs"
          >
            <option value="">All shops</option>
            {shops.map((s) => (
              <option key={s.id} value={s.id}>
                {s.name}
              </option>
            ))}
          </Select>
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
        {filtered.length} of {rows.length} models · tap a row to see stock by
        shop and who sold each one
      </p>

      {filtered.length === 0 ? (
        <EmptyState>No models match your search.</EmptyState>
      ) : (
        <>
          <div className="hidden overflow-x-auto rounded-xl border border-zinc-200 bg-white shadow-sm sm:block">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-zinc-100 text-left text-xs uppercase tracking-wide text-zinc-400">
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
                    className="cursor-pointer border-b border-zinc-50 hover:bg-zinc-50/60"
                    onClick={() => setSelected(r)}
                  >
                    <td className="sticky left-0 bg-white py-2 pl-4 pr-2 font-medium text-zinc-900 hover:text-indigo-600">
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
                              className={`tabular-nums hover:underline ${cellClass(c.low)}`}
                            >
                              {c.available}
                            </Link>
                          ) : (
                            <span className="text-zinc-300">—</span>
                          )}
                        </td>
                      );
                    })}
                    <td className="py-2 pr-3 text-right font-bold tabular-nums text-zinc-900">
                      {r.total}
                    </td>
                    <td className="py-2 pr-3 text-right font-bold tabular-nums text-zinc-900">
                      {r.sold}
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
                className="cursor-pointer rounded-xl border border-zinc-200 bg-white p-3 shadow-sm"
                onClick={() => setSelected(r)}
              >
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <div className="flex flex-wrap items-center gap-2">
                    <span className="text-sm font-medium text-zinc-900">
                      {r.model_name}
                    </span>
                    <Badge tone={r.condition === "new" ? "blue" : "gray"}>
                      {r.condition}
                    </Badge>
                  </div>
                  <span className="text-sm font-semibold tabular-nums text-zinc-900">
                    {r.total} avail · {r.sold} sold
                  </span>
                </div>
                <div className="mt-2 flex flex-wrap gap-1.5">
                  {r.perShop
                    .filter((c) => c.available > 0 && (!shopId || c.shopId === shopId))
                    .map((c) => {
                      const shop = shops.find((s) => s.id === c.shopId);
                      return (
                        <Link
                          key={c.shopId}
                          href={`/shops/${c.shopId}`}
                          onClick={(e) => e.stopPropagation()}
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

      <Modal
        open={selected !== null}
        onClose={() => setSelected(null)}
        title={selected?.model_name}
        subtitle={
          selected ? `Details · ${scopeLabel}` : undefined
        }
        size="xl"
      >
        {selected && (
          <ModelDetail row={selected} shops={shops} shopId={shopId} />
        )}
      </Modal>
    </div>
  );
}
