import Link from "next/link";
import type { DashboardData, DashboardPeriod, ShopDailySummary } from "@/lib/data";
import { formatMoney, formatDateTime, todayISO } from "@/lib/format";
import { Badge, Card, EmptyState } from "@/components/ui";
import { RealtimeRefresher } from "@/components/realtime-refresher";
import { StockRequestsPanel } from "@/components/stock-requests-panel";
import { DashboardCharts } from "@/components/dashboard-charts";
import { ShopFilter } from "@/components/shop-filter";

const PERIOD_LABELS: Record<DashboardPeriod, string> = {
  today: "Today",
  "7d": "Last 7 days",
  "30d": "Last 30 days",
};

function PeriodToggle({ period }: { period: DashboardPeriod }) {
  const options: DashboardPeriod[] = ["today", "7d", "30d"];
  return (
    <div className="inline-flex rounded-lg border border-line bg-white p-0.5">
      {options.map((p) => (
        <Link
          key={p}
          href={p === "today" ? "/" : `/?period=${p}`}
          className={`h-8 rounded-md px-3 text-xs font-medium leading-8 transition-colors ${
            period === p
              ? "bg-brand text-white"
              : "text-mute hover:text-ink"
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
  const scopeLabel = isOwner
    ? data.shop
      ? data.shop.name
      : `${data.summaries.length} shop${data.summaries.length === 1 ? "" : "s"}`
    : "your shop";

  // Top models moving — units out per model across the scoped shops.
  const movers = (() => {
    const map = new Map<string, number>();
    for (const s of data.summaries) {
      for (const r of s.rows) {
        map.set(r.model_name, (map.get(r.model_name) ?? 0) + r.sold + r.swapped_out);
      }
    }
    return [...map.entries()]
      .map(([name, units]) => ({ name, units }))
      .filter((m) => m.units > 0)
      .sort((a, b) => b.units - a.units)
      .slice(0, 5);
  })();

  return (
    <div className="space-y-6">
      <RealtimeRefresher />

      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-xl font-extrabold tracking-tight text-ink">
            {isOwner ? periodLabel : data.shop?.name ?? periodLabel}
          </h1>
          <p className="mt-0.5 text-[12.5px] text-mute">
            {new Date(todayISO() + "T00:00:00").toLocaleDateString("en-GB", {
              weekday: "short",
              month: "short",
              day: "numeric",
            })}{" "}
            · {scopeLabel} · live
          </p>
        </div>
        <Link
          href="/transactions/new"
          className="inline-flex h-10 shrink-0 items-center gap-1.5 rounded-[10px] bg-brand px-4 text-sm font-bold text-white transition-colors hover:bg-brand-deep"
        >
          Record
        </Link>
      </div>

      <div className="flex flex-wrap items-center gap-3">
        <PeriodToggle period={data.period} />
        {isOwner && data.shops.length > 1 && (
          <ShopFilter
            shops={data.shops}
            value={data.shop?.id ?? null}
            period={data.period}
          />
        )}
      </div>

      <StatCards totals={data.totals} period={data.period} />

      <LowStockAlert summaries={data.summaries} isOwner={isOwner} />

      {movers.length > 0 && <TopMovers movers={movers} />}

      <DashboardCharts data={data} />

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
}: {
  totals: DashboardData["totals"];
  period: DashboardPeriod;
}) {
  const items = [
    { label: "Revenue", value: formatMoney(totals.revenue), tone: "text-ledger" },
    { label: "Sales", value: String(totals.sales), tone: "text-ink" },
    { label: "Swaps", value: String(totals.swaps), tone: "text-ink" },
    { label: "Repairs", value: String(totals.repairs), tone: "text-ink" },
    { label: "Units out", value: String(totals.units_out), tone: "text-ink" },
    {
      label: "Low stock",
      value: String(totals.low_stock),
      tone: totals.low_stock > 0 ? "text-lowstock" : "text-ink",
    },
  ];
  return (
    <div className="grid grid-cols-2 gap-2.5 sm:grid-cols-3 lg:grid-cols-6">
      {items.map((it) => (
        <div
          key={it.label}
          className="flex min-h-[84px] flex-col gap-1 rounded-2xl border border-line bg-white px-4 py-3.5"
        >
          <span className="text-[10.5px] font-semibold uppercase tracking-wider text-mute">
            {it.label}
          </span>
          <span
            className={`font-mono text-[22px] font-bold leading-tight tabular-nums ${it.tone}`}
          >
            {it.value}
          </span>
        </div>
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
  if (items.length === 0) return null;
  const first = items[0];
  const href = isOwner ? "/devices" : `/shops/${first.shop.id}`;
  return (
    <Link
      href={href}
      className="flex items-center justify-between gap-3 rounded-2xl border border-[#ebc9bb] bg-lowstock-tint p-4 transition-transform hover:-translate-y-px"
    >
      <div className="flex min-w-0 items-center gap-3">
        <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-lowstock text-[15px] font-extrabold text-white">
          {items.length}
        </span>
        <div className="min-w-0">
          <div className="text-[13.5px] font-bold text-ink">
            {items.length === 1 ? "Model running low" : "Models running low"}
          </div>
          <div className="mt-0.5 truncate text-[11.5px] text-mute">
            {first.model.model_name} · {first.model.available} left
            {isOwner ? ` · ${first.shop.name}` : ""}
            {items.length > 1 ? ` · +${items.length - 1} more` : ""}
          </div>
        </div>
      </div>
      <svg
        width="18"
        height="18"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        strokeWidth="2"
        strokeLinecap="round"
        strokeLinejoin="round"
        className="shrink-0 text-lowstock"
      >
        <path d="M9 6l6 6-6 6" />
      </svg>
    </Link>
  );
}

function TopMovers({
  movers,
}: {
  movers: { name: string; units: number }[];
}) {
  return (
    <section>
      <h2 className="mb-2.5 mt-1 text-[13px] font-bold text-ink">
        Top models moving
      </h2>
      <div className="flex flex-col gap-2">
        {movers.map((m) => (
          <div
            key={m.name}
            className="flex items-center justify-between rounded-xl border border-line bg-white px-3.5 py-2.5"
          >
            <span className="truncate text-[13px] font-semibold text-ink">
              {m.name}
            </span>
            <span className="shrink-0 font-mono text-xs font-semibold tabular-nums text-mute">
              {m.units} out
            </span>
          </div>
        ))}
      </div>
    </section>
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
        <Link href="/reports" className="text-sm font-medium text-brand hover:underline">
          View reports →
        </Link>
      }
    >
      {recent.length === 0 ? (
        <EmptyState>No transactions yet. Record your first sale or swap.</EmptyState>
      ) : (
        <ul className="divide-y divide-line">
          {recent.map((t) => (
            <li key={t.id} className="flex items-center justify-between gap-3 py-2">
              <div className="min-w-0">
                <div className="flex items-center gap-2">
                  <span className="truncate font-medium text-ink">
                    {t.items.map((i) => (i.direction === "out" ? "−" : "+") + i.model_name).join(", ") ||
                      "—"}
                  </span>
                  <Badge tone={t.type === "sale" ? "green" : t.type === "swap" ? "blue" : "gray"}>
                    {t.type}
                  </Badge>
                </div>
                <div className="text-xs text-mute">
                  {t.customer_name || "Walk-in"} · {formatDateTime(t.date)}
                  {isOwner && t.shop_name ? ` · ${t.shop_name}` : ""}
                </div>
              </div>
              <span className="shrink-0 font-mono text-sm font-semibold tabular-nums text-ink">{formatMoney(t.amount)}</span>
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
          className="text-sm font-medium text-brand hover:underline"
        >
          Shop view →
        </Link>
      }
    >
      <div className="mb-3 flex flex-wrap gap-3 text-sm text-mute">
        <span>
          Sales: <b className="text-ink">{summary.total_sales}</b>
        </span>
        <span>
          Swaps: <b className="text-ink">{summary.total_swaps}</b>
        </span>
        <span>
          Revenue: <b className="font-mono tabular-nums text-ledger">{formatMoney(summary.revenue)}</b>
        </span>
      </div>

      {summary.rows.length === 0 ? (
        <EmptyState>No units out today yet.</EmptyState>
      ) : (
        <>
          <div className="hidden overflow-x-auto sm:block">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-line text-left text-xs uppercase tracking-wide text-mute">
                  <th className="py-2 pr-4 font-medium">Model</th>
                  <th className="py-2 pr-4 font-medium">Condition</th>
                  <th className="py-2 pr-4 text-right font-medium">Sold</th>
                  <th className="py-2 pr-4 text-right font-medium">Swapped out</th>
                  <th className="py-2 text-right font-medium">Total out</th>
                </tr>
              </thead>
              <tbody>
                {summary.rows.map((r) => (
                  <tr key={`${r.model_name}-${r.condition}`} className="border-b border-line/60">
                    <td className="py-2 pr-4 font-medium text-ink">{r.model_name}</td>
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
                className="flex items-center justify-between gap-3 rounded-lg border border-line bg-paper px-3 py-2"
              >
                <div className="min-w-0">
                  <div className="flex flex-wrap items-center gap-2">
                    <span className="truncate text-sm font-medium text-ink">{r.model_name}</span>
                    <Badge tone={r.condition === "new" ? "blue" : "gray"}>{r.condition}</Badge>
                  </div>
                  <div className="mt-0.5 text-xs text-mute">
                    Sold {r.sold} · Swapped out {r.swapped_out}
                  </div>
                </div>
                <div className="shrink-0 font-mono text-sm font-semibold tabular-nums text-ink">{r.sold + r.swapped_out}</div>
              </li>
            ))}
          </ul>
        </>
      )}
    </Card>
  );
}
