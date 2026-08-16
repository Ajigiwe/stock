import Link from "next/link";
import { redirect } from "next/navigation";
import {
  getSession,
  getStock,
  getTransactions,
  getDailySummary,
  getAdjustments,
  getStockRequests,
  getSwappedPhones,
} from "@/lib/data";
import { formatMoney, formatDateTime, todayISO, addDays } from "@/lib/format";
import { Badge, Button, Card, EmptyState } from "@/components/ui";
import { AddModelForm } from "@/components/add-model-form";
import { DeleteTransactionButton } from "@/components/delete-transaction-button";
import { BulkStockModal } from "@/components/bulk-stock-modal";
import { StockRequestsPanel } from "@/components/stock-requests-panel";
import { StockTable } from "@/components/stock-table";
import { ShareSummaryButton } from "@/components/share-summary-button";
import { SwappedPhonesList } from "@/components/swapped-phones-list";

export default async function ShopPage({
  params,
  searchParams,
}: {
  params: Promise<{ id: string }>;
  searchParams: Promise<{ date?: string }>;
}) {
  const { id } = await params;
  const { date: dateParam } = await searchParams;

  const session = await getSession();
  if (!session) redirect("/login");

  // Attendants may only view their own shop.
  if (session.profile?.role === "attendant" && session.profile.shop_id !== id) {
    redirect(`/shops/${session.profile.shop_id}`);
  }

  const date = /^\d{4}-\d{2}-\d{2}$/.test(dateParam ?? "")
    ? dateParam!
    : todayISO();
  const isToday = date === todayISO();
  const dateLabel = isToday
    ? "Today"
    : new Date(date + "T00:00:00").toDateString();

  const [summary, stock, transactions, adjustments, pendingRequests, swappedPhones] =
    await Promise.all([
      getDailySummary(id, date),
      getStock(id),
      getTransactions({ shopId: id, from: date, to: date }),
      getAdjustments(id, 200),
      getStockRequests({ shopId: id, status: "pending" }),
      getSwappedPhones({ shopId: id }),
    ]);

  const isOwner = session.profile?.role === "owner";

  const summaryText = [
    `*${summary.shop.name}* — ${dateLabel}`,
    `Sales: ${summary.total_sales} · Swaps: ${summary.total_swaps} · Repairs: ${summary.total_repairs}`,
    `Revenue: ${formatMoney(summary.revenue)}`,
    summary.rows.length ? "Units out:" : null,
    ...summary.rows.map(
      (r) => `• ${r.model_name} (${r.condition}): ${r.sold + r.swapped_out}`,
    ),
  ]
    .filter(Boolean)
    .join("\n");

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-xl font-bold text-zinc-900">{summary.shop.name}</h1>
          <p className="text-sm text-zinc-500">
            {summary.shop.location ?? "—"} · {dateLabel}
          </p>
        </div>
        <div className="flex flex-wrap items-end gap-2">
          <form method="get" action={`/shops/${id}`} className="flex items-end gap-2">
            <div>
              <span className="mb-1 block text-xs font-medium text-zinc-500">Day</span>
              <div className="flex items-center gap-1">
                <Link
                  href={`/shops/${id}?date=${addDays(date, -1)}`}
                  aria-label="Previous day"
                  className="inline-flex h-10 w-9 items-center justify-center rounded-lg border border-zinc-300 bg-white text-zinc-600 hover:bg-zinc-50"
                >
                  ←
                </Link>
                <input
                  type="date"
                  name="date"
                  defaultValue={date}
                  max={todayISO()}
                  className="h-10 rounded-lg border border-zinc-300 bg-white px-2 text-sm text-zinc-900 focus:border-zinc-500 focus:outline-none"
                />
                <Link
                  href={`/shops/${id}?date=${addDays(date, 1)}`}
                  aria-label="Next day"
                  className="inline-flex h-10 w-9 items-center justify-center rounded-lg border border-zinc-300 bg-white text-zinc-600 hover:bg-zinc-50"
                >
                  →
                </Link>
              </div>
            </div>
            <Button type="submit" className="h-10 px-4 text-sm">
              Show
            </Button>
          </form>
          <Link
            href={`/transactions/new?shop=${id}`}
            className="inline-flex h-10 items-center rounded-lg bg-indigo-600 px-4 text-sm font-medium text-white hover:bg-indigo-500"
          >
            Record transaction
          </Link>
        </div>
      </div>

      {summary.low_stock.length > 0 && (
        <div className="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
          <span className="font-semibold">Low stock:</span>{" "}
          {summary.low_stock.map((m) => `${m.model_name} (${m.available})`).join(", ")}
        </div>
      )}

      <Card
        title={isToday ? "Today's closing" : "Closing"}
        subtitle={`${dateLabel} — units out, split by sale vs swap`}
        actions={<ShareSummaryButton text={summaryText} />}
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

      <Card
        title={isOwner ? "Pending stock changes" : "Your pending changes"}
        subtitle={
          pendingRequests.length === 0
            ? "Nothing awaiting action"
            : isOwner
              ? "Awaiting your approval"
              : "Awaiting owner approval"
        }
      >
        <StockRequestsPanel
          requests={pendingRequests}
          isOwner={isOwner}
          shopId={id}
        />
      </Card>

      <Card
        title="Stock"
        subtitle="Live balance per model"
        actions={
          <div className="flex items-center gap-2">
            <BulkStockModal shopId={id} stock={stock} isOwner={isOwner} />
            <AddModelForm shopId={id} isOwner={isOwner} />
          </div>
        }
      >
        <StockTable
          stock={stock}
          shopId={id}
          isOwner={isOwner}
          adjustments={adjustments}
        />
      </Card>

      <Card
        title="Swapped phones"
        subtitle={
          swappedPhones.length === 0
            ? "Trade-ins taken during swaps"
            : `${swappedPhones.length} trade-in${swappedPhones.length === 1 ? "" : "s"} received`
        }
      >
        <SwappedPhonesList phones={swappedPhones} isOwner={isOwner} />
      </Card>

      <Card
        title={isToday ? "Today's transactions" : "Transactions"}
        subtitle={`${transactions.length} recorded on ${dateLabel}`}
      >
        {transactions.length === 0 ? (
          <EmptyState>No transactions today.</EmptyState>
        ) : (
          <ul className="divide-y divide-zinc-50">
            {transactions.map((t) => (
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
                    {t.customer_name || "Walk-in"}
                    {t.customer_phone ? ` · ${t.customer_phone}` : ""} · {formatDateTime(t.date)} ·{" "}
                    {t.staff_name ?? "—"}
                  </div>
                </div>
                <div className="flex items-center gap-3 text-sm">
                  <Link
                    href={`/transactions/${t.id}`}
                    className="text-xs font-medium text-zinc-500 underline hover:text-zinc-800"
                  >
                    Receipt
                  </Link>
                  <span className="font-semibold text-zinc-900">{formatMoney(t.amount)}</span>
                  {isOwner && <DeleteTransactionButton id={t.id} />}
                </div>
              </li>
            ))}
          </ul>
        )}
      </Card>
    </div>
  );
}
