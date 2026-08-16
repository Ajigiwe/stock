import Link from "next/link";
import type { DashboardData, DashboardPeriod, ShopDailySummary } from "@/lib/data";
import { formatMoney, formatDateTime } from "@/lib/format";
import { Badge, Card, EmptyState } from "@/components/ui";
import { RealtimeRefresher } from "@/components/realtime-refresher";
import { StockRequestsPanel } from "@/components/stock-requests-panel";
import { DashboardCharts } from "@/components/dashboard-charts";

const PERIOD_LABELS: Record<DashboardPeriod, string> = {
  today: "Today",
  "7d": "Last 7 days",
  "30d": "Last 30 days",
};

function PeriodToggle({ period }: { period: DashboardPeriod }) {
  const options: DashboardPeriod[] = ["today", "7d", "30d"];
  return (
    <div className="inline-flex rounded-lg border border-zinc-200 bg-white p-0.5">
      {options.map((p) => (
        <Link
          key={p}
          href={p === "today" ? "/" : `/?period=${p}`}
          className={`h-8 rounded-md px-3 text-xs font-medium leading-8 transition-colors ${
            period === p
              ? "bg-indigo-600 text-white"
              : "text-zinc-500 hover:text-zinc-800"
          }`}
        >
          {PERIOD_LABELS[p]}
        </Link>
      ))}
    </div>
  );
}

export function Dashboard({ data }: { data: DashboardData }) {
  const isOwner = data.role === "owner";
  const periodLabel = PERIOD_LABELS[data.period];

  return (
    <div className="space-y-6">
      <RealtimeRefresher />

      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-xl font-bold text-zinc-900">
            {isOwner ? "Dashboard" : data.shop?.name ?? "Dashboard"}
          </h1>
          <p className="text-sm text-zinc-500">
            {periodLabel} ·{" "}
            {isOwner
              ? `${data.summaries.length} shop${data.summaries.length === 1 ? "" : "s"} · live`
              : "your shop · live"}
          </p>
        </div>
        <Link
          href="/transactions/new"
          className="inline-flex h-10 items-center rounded-lg bg-indigo-600 px-4 text-sm font-medium text-white hover:bg-indigo-500"
        >
          Record transaction
        </Link>
      </div>

      <PeriodToggle period={data.period} />

      <StatCards totals={data.totals} isOwner={isOwner} period={data.period} />

      <DashboardCharts data={data} />

      {data.totals.low_stock > 0 && <LowStockAlert summaries={data.summaries} isOwner={isOwner} />}

      {data.pending.length > 0 && (
        <Card
          title="Pending stock approvals"
          subtitle={
            isOwner
              ? `${data.pending.length} change${data.pending.length === 1 ? "" : "s"} waiting for you`
              : `${data.pending.length} change${data.pending.length === 1 ? "" : "s"} awaiting the owner`
          }
        >
          <StockRequestsPanel
            requests={data.pending}
            isOwner={isOwner}
            showShop={isOwner}
          />
        </Card>
      )}

      <RecentTransactions recent={data.recent} isOwner={isOwner} />

      {data.summaries.map((s) => (
        <ShopClosingCard
          key={s.shop.id}
          summary={s}
          isOwner={isOwner}
          periodLabel={periodLabel}
        />
      ))}
    </div>
  );
}

function StatCards({
  totals,
  isOwner,
  period,
}: {
  totals: DashboardData["totals"];
  isOwner: boolean;
  period: DashboardPeriod;
}) {
  const revenueLabel = period === "today" ? "Revenue today" : "Revenue";
  const items = [
    {
      label: revenueLabel,
      value: formatMoney(totals.revenue),
      tone: "text-zinc-900",
      accent: "border-l-emerald-400",
    },
    ...(isOwner
      ? [
          {
            label: "Est. profit",
            value: formatMoney(totals.profit),
            tone: totals.profit >= 0 ? "text-emerald-700" : "text-red-600",
            accent: "border-l-emerald-500",
          },
        ]
      : []),
    { label: "Sales", value: String(totals.sales), tone: "text-zinc-900", accent: "border-l-sky-400" },
    { label: "Swaps", value: String(totals.swaps), tone: "text-zinc-900", accent: "border-l-violet-400" },
    { label: "Repairs", value: String(totals.repairs), tone: "text-zinc-900", accent: "border-l-amber-400" },
    { label: "Units out", value: String(totals.units_out), tone: "text-zinc-900", accent: "border-l-zinc-300" },
    {
      label: "Low stock",
      value: String(totals.low_stock),
      tone: totals.low_stock > 0 ? "text-red-600" : "text-zinc-900",
      accent: totals.low_stock > 0 ? "border-l-red-400" : "border-l-zinc-300",
    },
  ];
  const cols = isOwner ? "lg:grid-cols-7" : "lg:grid-cols-6";
  return (
    <div className={`grid grid-cols-2 gap-3 sm:grid-cols-3 ${cols}`}>
      {items.map((it) => (
        <Card key={it.label} className={`border-l-4 ${it.accent}`}>
          <div className="text-xs font-medium uppercase tracking-wide text-zinc-400">
            {it.label}
          </div>
          <div className={`mt-1 text-xl font-bold ${it.tone}`}>{it.value}</div>
        </Card>
      ))}
    </div>
  );
}

function LowStockAlert({
  summaries,
  isOwner,
}: {
  summaries: ShopDailySummary[];
  isOwner: boolean;
}) {
  const items = summaries.flatMap((s) =>
    s.low_stock.map((m) => ({
      shop: s.shop,
      model: m,
    })),
  );
  return (
    <div className="rounded-xl border border-amber-200 bg-amber-50 p-4">
      <h2 className="text-sm font-semibold text-amber-900">Low stock</h2>
      <ul className="mt-2 space-y-1">
        {items.map(({ shop, model }) => (
          <li key={model.id} className="text-sm text-amber-800">
            <span className="font-medium">{model.model_name}</span>{" "}
            <Badge tone={model.condition === "new" ? "blue" : "gray"}>
              {model.condition}
            </Badge>{" "}
            · {model.available} left (min {model.low_stock_threshold})
            {isOwner && <span className="text-amber-600"> · {shop.name}</span>}
          </li>
        ))}
      </ul>
    </div>
  );
}

function RecentTransactions({
  recent,
  isOwner,
}: {
  recent: DashboardData["recent"];
  isOwner: boolean;
}) {
  return (
    <Card
      title="Recent transactions"
      subtitle="Latest activity"
      actions={
        <Link href="/reports" className="text-sm font-medium text-zinc-900 underline">
          View reports →
        </Link>
      }
    >
      {recent.length === 0 ? (
        <EmptyState>No transactions yet. Record your first sale or swap.</EmptyState>
      ) : (
        <ul className="divide-y divide-zinc-50">
          {recent.map((t) => (
            <li key={t.id} className="flex items-center justify-between gap-3 py-2">
              <div className="min-w-0">
                <div className="flex items-center gap-2">
                  <span className="font-medium text-zinc-900">
                    {t.items.map((i) => (i.direction === "out" ? "−" : "+") + i.model_name).join(", ") ||
                      "—"}
                  </span>
                  <Badge tone={t.type === "sale" ? "green" : t.type === "swap" ? "blue" : "gray"}>
                    {t.type}
                  </Badge>
                </div>
                <div className="text-xs text-zinc-500">
                  {t.customer_name || "Walk-in"} · {formatDateTime(t.date)}
                  {isOwner && t.shop_name ? ` · ${t.shop_name}` : ""}
                </div>
              </div>
              <span className="text-sm font-semibold text-zinc-900">{formatMoney(t.amount)}</span>
            </li>
          ))}
        </ul>
      )}
    </Card>
  );
}

function ShopClosingCard({
  summary,
  isOwner,
  periodLabel,
}: {
  summary: ShopDailySummary;
  isOwner: boolean;
  periodLabel: string;
}) {
  return (
    <Card
      title={isOwner ? `${summary.shop.name} · ${periodLabel.toLowerCase()}` : `${periodLabel} closing`}
      subtitle={isOwner ? summary.shop.location ?? undefined : undefined}
      actions={
        <Link
          href={`/shops/${summary.shop.id}`}
          className="text-sm font-medium text-zinc-900 underline"
        >
          Shop view →
        </Link>
      }
    >
      <div className="mb-3 flex flex-wrap gap-3 text-sm text-zinc-600">
        <span>
          Sales: <b>{summary.total_sales}</b>
        </span>
        <span>
          Swaps: <b>{summary.total_swaps}</b>
        </span>
        <span>
          Revenue: <b>{formatMoney(summary.revenue)}</b>
        </span>
        {isOwner && (
          <span>
            Est. profit:{" "}
            <b className={summary.profit >= 0 ? "text-emerald-700" : "text-red-600"}>
              {formatMoney(summary.profit)}
            </b>
          </span>
        )}
      </div>

      {summary.rows.length === 0 ? (
        <EmptyState>No units out today yet.</EmptyState>
      ) : (
        <>
          <div className="hidden overflow-x-auto sm:block">
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
                {summary.rows.map((r) => (
                  <tr key={`${r.model_name}-${r.condition}`} className="border-b border-zinc-50">
                    <td className="py-2 pr-4 font-medium text-zinc-900">{r.model_name}</td>
                    <td className="py-2 pr-4">
                      <Badge tone={r.condition === "new" ? "blue" : "gray"}>{r.condition}</Badge>
                    </td>
                    <td className="py-2 pr-4 text-right">{r.sold}</td>
                    <td className="py-2 pr-4 text-right">{r.swapped_out}</td>
                    <td className="py-2 text-right font-semibold">{r.sold + r.swapped_out}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <ul className="space-y-2 sm:hidden">
            {summary.rows.map((r) => (
              <li
                key={`${r.model_name}-${r.condition}`}
                className="flex items-center justify-between gap-3 rounded-lg border border-zinc-100 bg-zinc-50 px-3 py-2"
              >
                <div className="min-w-0">
                  <div className="flex flex-wrap items-center gap-2">
                    <span className="truncate text-sm font-medium text-zinc-900">{r.model_name}</span>
                    <Badge tone={r.condition === "new" ? "blue" : "gray"}>{r.condition}</Badge>
                  </div>
                  <div className="mt-0.5 text-xs text-zinc-500">
                    Sold {r.sold} · Swapped out {r.swapped_out}
                  </div>
                </div>
                <div className="shrink-0 text-sm font-semibold text-zinc-900">{r.sold + r.swapped_out}</div>
              </li>
            ))}
          </ul>
        </>
      )}
    </Card>
  );
}