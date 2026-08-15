import Link from "next/link";
import type { ShopDailySummary } from "@/lib/data";
import { formatMoney } from "@/lib/format";
import { Badge, Card, EmptyState } from "@/components/ui";

export function Dashboard({ summaries }: { summaries: ShopDailySummary[] }) {
  const totalRevenue = summaries.reduce((a, s) => a + s.revenue, 0);
  const totalSales = summaries.reduce((a, s) => a + s.total_sales, 0);
  const totalSwaps = summaries.reduce((a, s) => a + s.total_swaps, 0);
  const lowStockTotal = summaries.reduce((a, s) => a + s.low_stock.length, 0);

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-end justify-between gap-2">
        <div>
          <h1 className="text-xl font-bold text-zinc-900">Today&apos;s closing</h1>
          <p className="text-sm text-zinc-500">
            {new Date().toDateString()} · all shops
          </p>
        </div>
        <div className="flex gap-6">
          <div className="text-right">
            <div className="text-xs font-medium uppercase tracking-wide text-zinc-400">Revenue</div>
            <div className="text-lg font-bold text-zinc-900">{formatMoney(totalRevenue)}</div>
          </div>
          <div className="text-right">
            <div className="text-xs font-medium uppercase tracking-wide text-zinc-400">Sales</div>
            <div className="text-lg font-bold text-zinc-900">{totalSales}</div>
          </div>
          <div className="text-right">
            <div className="text-xs font-medium uppercase tracking-wide text-zinc-400">Swaps</div>
            <div className="text-lg font-bold text-zinc-900">{totalSwaps}</div>
          </div>
          <div className="text-right">
            <div className="text-xs font-medium uppercase tracking-wide text-zinc-400">Low stock</div>
            <div className="text-lg font-bold text-red-600">{lowStockTotal}</div>
          </div>
        </div>
      </div>

      {summaries.map((s) => (
        <Card
          key={s.shop.id}
          title={s.shop.name}
          subtitle={s.shop.location ?? undefined}
          actions={
            <Link
              href={`/shops/${s.shop.id}`}
              className="text-sm font-medium text-zinc-900 underline"
            >
              Shop view →
            </Link>
          }
        >
          {s.low_stock.length > 0 && (
            <div className="mb-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">
              <span className="font-semibold">Low stock:</span>{" "}
              {s.low_stock.map((m) => m.model_name).join(", ")}
            </div>
          )}

          <div className="mb-3 flex flex-wrap gap-3 text-sm text-zinc-600">
            <span>
              Sales: <b>{s.total_sales}</b>
            </span>
            <span>
              Swaps: <b>{s.total_swaps}</b>
            </span>
            <span>
              Revenue: <b>{formatMoney(s.revenue)}</b>
            </span>
          </div>

          {s.rows.length === 0 ? (
            <EmptyState>No units out today yet.</EmptyState>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-zinc-100 text-left text-xs uppercase tracking-wide text-zinc-400">
                    <th className="py-2 pr-4 font-medium">Model</th>
                    <th className="py-2 pr-4 font-medium">Condition</th>
                    <th className="py-2 pr-4 text-right font-medium">Sold</th>
                    <th className="py-2 pr-4 text-right font-medium">Swapped out</th>
                    <th className="py-2 text-right font-medium">Total out</th>
                  </tr>
                </thead>
                <tbody>
                  {s.rows.map((r) => (
                    <tr key={`${r.model_name}-${r.condition}`} className="border-b border-zinc-50">
                      <td className="py-2 pr-4 font-medium text-zinc-900">{r.model_name}</td>
                      <td className="py-2 pr-4">
                        <Badge tone={r.condition === "new" ? "blue" : "gray"}>
                          {r.condition}
                        </Badge>
                      </td>
                      <td className="py-2 pr-4 text-right">{r.sold}</td>
                      <td className="py-2 pr-4 text-right">{r.swapped_out}</td>
                      <td className="py-2 text-right font-semibold">{r.sold + r.swapped_out}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </Card>
      ))}
    </div>
  );
}
