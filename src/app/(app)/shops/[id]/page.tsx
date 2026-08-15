import Link from "next/link";
import { redirect } from "next/navigation";
import {
  getSession,
  getStock,
  getTodayTransactions,
  getDailySummary,
} from "@/lib/data";
import { formatMoney, formatDateTime } from "@/lib/format";
import { Badge, Card, EmptyState } from "@/components/ui";
import { AdjustStockForm } from "@/components/adjust-stock-form";
import { AddModelForm } from "@/components/add-model-form";
import { DeleteTransactionButton } from "@/components/delete-transaction-button";

export default async function ShopPage({
  params,
}: {
  params: Promise<{ id: string }>;
}) {
  const { id } = await params;

  const session = await getSession();
  if (!session) redirect("/login");

  // Attendants may only view their own shop.
  if (session.profile?.role === "attendant" && session.profile.shop_id !== id) {
    redirect(`/shops/${session.profile.shop_id}`);
  }

  const [summary, stock, transactions] = await Promise.all([
    getDailySummary(id),
    getStock(id),
    getTodayTransactions(id),
  ]);

  const isOwner = session.profile?.role === "owner";

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-xl font-bold text-zinc-900">{summary.shop.name}</h1>
          <p className="text-sm text-zinc-500">
            {summary.shop.location ?? "—"} · {new Date().toDateString()}
          </p>
        </div>
        <Link
          href={`/transactions/new?shop=${id}`}
          className="inline-flex h-10 items-center rounded-lg bg-zinc-900 px-4 text-sm font-medium text-white hover:bg-zinc-700"
        >
          Record transaction
        </Link>
      </div>

      {summary.low_stock.length > 0 && (
        <div className="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
          <span className="font-semibold">Low stock:</span>{" "}
          {summary.low_stock.map((m) => `${m.model_name} (${m.available})`).join(", ")}
        </div>
      )}

      <Card title="Today's closing" subtitle="Units out, split by sale vs swap">
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
        )}
      </Card>

      <Card
        title="Stock"
        subtitle="Live balance per model"
        actions={<AddModelForm shopId={id} />}
      >
        {stock.length === 0 ? (
          <EmptyState>No models yet. Add the first one above.</EmptyState>
        ) : (
          <div className="overflow-x-auto">
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
                {stock.map((m) => {
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
                        <AdjustStockForm shopId={id} phoneModelId={m.id} modelName={m.model_name} />
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      <Card title="Today's transactions" subtitle={`${transactions.length} recorded today`}>
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
