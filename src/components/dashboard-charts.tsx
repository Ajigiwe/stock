import type { DashboardData } from "@/lib/data";
import { formatMoney } from "@/lib/format";
import { Card, EmptyState } from "@/components/ui";

function dm(iso: string) {
  const parts = iso.split("-");
  return `${Number(parts[2])}/${Number(parts[1])}`;
}

export function DashboardCharts({ data }: { data: DashboardData }) {
  const isOwner = data.role === "owner";
  const series = data.series;
  const showTrend = series.length > 1;
  const maxRev = Math.max(1, ...series.map((p) => p.revenue));
  const labelStep = Math.max(1, Math.ceil(series.length / 7));

  // Aggregate top models (units out) across the period.
  const modelMap = new Map<
    string,
    { name: string; condition: string; units: number }
  >();
  for (const s of data.summaries) {
    for (const r of s.rows) {
      const key = `${r.model_name}|${r.condition}`;
      const cur =
        modelMap.get(key) ?? { name: r.model_name, condition: r.condition, units: 0 };
      cur.units += r.sold + r.swapped_out;
      modelMap.set(key, cur);
    }
  }
  const topModels = [...modelMap.values()]
    .filter((m) => m.units > 0)
    .sort((a, b) => b.units - a.units)
    .slice(0, 6);
  const maxUnits = Math.max(1, ...topModels.map((m) => m.units));

  const typeItems = [
    { label: "Sales", value: data.totals.sales, cls: "bg-emerald-500" },
    { label: "Swaps", value: data.totals.swaps, cls: "bg-sky-500" },
    { label: "Repairs", value: data.totals.repairs, cls: "bg-zinc-400" },
  ];
  const maxType = Math.max(1, ...typeItems.map((t) => t.value));
  const hasTx = data.totals.sales + data.totals.swaps + data.totals.repairs > 0;

  return (
    <div className="space-y-6">
      {showTrend && (
        <Card
          title="Revenue trend"
          subtitle={isOwner ? "Daily revenue · profit shaded" : "Daily revenue"}
        >
          {isOwner && (
            <div className="mb-3 flex items-center gap-4 text-xs text-zinc-500">
              <span className="flex items-center gap-1.5">
                <span className="h-2.5 w-2.5 rounded-sm bg-zinc-200" />
                Revenue
              </span>
              <span className="flex items-center gap-1.5">
                <span className="h-2.5 w-2.5 rounded-sm bg-emerald-500" />
                Profit
              </span>
            </div>
          )}
          <div className="flex h-40 items-end gap-1">
            {series.map((p) => {
              const revPct = p.revenue > 0 ? Math.max(2, (p.revenue / maxRev) * 100) : 0;
              const profitPct =
                p.revenue > 0
                  ? Math.min(100, Math.max(0, (p.profit / p.revenue) * 100))
                  : 0;
              return (
                <div
                  key={p.date}
                  className="flex h-full flex-1 flex-col justify-end"
                  title={`${dm(p.date)} — ${formatMoney(p.revenue)}${
                    isOwner ? ` · profit ${formatMoney(p.profit)}` : ""
                  }`}
                >
                  <div
                    className="relative w-full rounded-t bg-zinc-200"
                    style={{ height: `${revPct}%` }}
                  >
                    {isOwner && profitPct > 0 && (
                      <div
                        className="absolute inset-x-0 bottom-0 rounded-t bg-emerald-500"
                        style={{ height: `${profitPct}%` }}
                      />
                    )}
                  </div>
                </div>
              );
            })}
          </div>
          <div className="mt-1 flex gap-1">
            {series.map((p, i) => (
              <div
                key={p.date}
                className="flex-1 truncate text-center text-[10px] text-zinc-400"
              >
                {i % labelStep === 0 ? dm(p.date) : ""}
              </div>
            ))}
          </div>
        </Card>
      )}

      <div className="grid gap-6 lg:grid-cols-2">
        <Card title="Sales by type" subtitle="Transactions in period">
          {!hasTx ? (
            <EmptyState>No transactions in this period.</EmptyState>
          ) : (
            <ul className="space-y-3">
              {typeItems.map((t) => (
                <li key={t.label}>
                  <div className="mb-1 flex justify-between text-xs">
                    <span className="font-medium text-zinc-700">{t.label}</span>
                    <span className="text-zinc-500">{t.value}</span>
                  </div>
                  <div className="h-2.5 w-full overflow-hidden rounded-full bg-zinc-100">
                    <div
                      className={`h-full rounded-full ${t.cls}`}
                      style={{ width: `${(t.value / maxType) * 100}%` }}
                    />
                  </div>
                </li>
              ))}
            </ul>
          )}
        </Card>

        <Card title="Top models" subtitle="Units out in period">
          {topModels.length === 0 ? (
            <EmptyState>No units out in this period.</EmptyState>
          ) : (
            <ul className="space-y-3">
              {topModels.map((m) => (
                <li key={`${m.name}|${m.condition}`}>
                  <div className="mb-1 flex justify-between gap-2 text-xs">
                    <span className="min-w-0 truncate font-medium text-zinc-700">
                      {m.name}
                    </span>
                    <span className="shrink-0 text-zinc-500">{m.units}</span>
                  </div>
                  <div className="h-2.5 w-full overflow-hidden rounded-full bg-zinc-100">
                    <div
                      className="h-full rounded-full bg-zinc-800"
                      style={{ width: `${(m.units / maxUnits) * 100}%` }}
                    />
                  </div>
                </li>
              ))}
            </ul>
          )}
        </Card>
      </div>
    </div>
  );
}
