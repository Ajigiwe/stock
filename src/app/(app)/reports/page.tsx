import { redirect } from "next/navigation";
import Link from "next/link";
import {
  getSession,
  getShops,
  getTransactions,
  type TransactionWithDetails,
} from "@/lib/data";
import { formatMoney, formatDateTime } from "@/lib/format";
import { Badge, Card, EmptyState, Input, Label, Select } from "@/components/ui";

export const dynamic = "force-dynamic";

const PAYMENT_LABELS: Record<string, string> = {
  cash: "Cash",
  mobile_money: "Mobile money",
  card: "Card",
  bank_transfer: "Bank transfer",
  other: "Other",
};

export default async function ReportsPage({
  searchParams,
}: {
  searchParams: Promise<{ shop?: string; from?: string; to?: string; type?: string; payment?: string }>;
}) {
  const session = await getSession();
  if (!session) redirect("/login");

  const sp = await searchParams;
  const shops = await getShops();

  const isOwner = session.profile?.role === "owner";
  const shopId = isOwner ? sp.shop : session.profile?.shop_id;

  const txs = await getTransactions({
    shopId: shopId || undefined,
    from: sp.from,
    to: sp.to,
    type: sp.type,
    paymentMethod: sp.payment,
  });

  const revenue = txs.reduce((a, t) => a + (t.amount ?? 0), 0);
  const byType = (type: string) => txs.filter((t) => t.type === type);
  const paymentBreakdown = txs.reduce<Record<string, number>>((acc, t) => {
    acc[t.payment_method] = (acc[t.payment_method] ?? 0) + (t.amount ?? 0);
    return acc;
  }, {});

  const qs = new URLSearchParams();
  if (sp.shop) qs.set("shop", sp.shop);
  if (sp.from) qs.set("from", sp.from);
  if (sp.to) qs.set("to", sp.to);
  if (sp.type) qs.set("type", sp.type);
  if (sp.payment) qs.set("payment", sp.payment);
  const exportHref = `/reports/export?${qs.toString()}`;

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-xl font-bold text-zinc-900">Reports</h1>
          <p className="text-sm text-zinc-500">{txs.length} transactions in range</p>
        </div>
        <a
          href={exportHref}
          className="inline-flex h-10 items-center rounded-lg border border-zinc-300 bg-white px-4 text-sm font-medium text-zinc-700 hover:bg-zinc-50"
        >
          Export CSV
        </a>
      </div>

      <Card title="Filters">
        <form method="get" className="grid gap-3 sm:grid-cols-5">
          {isOwner && (
            <div>
              <Label>Shop</Label>
              <Select name="shop" defaultValue={sp.shop ?? ""}>
                <option value="">All shops</option>
                {shops.map((s) => (
                  <option key={s.id} value={s.id}>
                    {s.name}
                  </option>
                ))}
              </Select>
            </div>
          )}
          <div>
            <Label>From</Label>
            <Input type="date" name="from" defaultValue={sp.from ?? ""} />
          </div>
          <div>
            <Label>To</Label>
            <Input type="date" name="to" defaultValue={sp.to ?? ""} />
          </div>
          <div>
            <Label>Type</Label>
            <Select name="type" defaultValue={sp.type ?? ""}>
              <option value="">All</option>
              <option value="sale">Sale</option>
              <option value="swap">Swap</option>
              <option value="repair">Repair</option>
            </Select>
          </div>
          <div>
            <Label>Payment</Label>
            <Select name="payment" defaultValue={sp.payment ?? ""}>
              <option value="">All</option>
              {Object.entries(PAYMENT_LABELS).map(([k, v]) => (
                <option key={k} value={k}>
                  {v}
                </option>
              ))}
            </Select>
          </div>
          <div className="sm:col-span-5">
            <button
              type="submit"
              className="inline-flex h-10 w-full items-center justify-center rounded-lg bg-indigo-600 px-4 text-sm font-medium text-white hover:bg-indigo-500 sm:w-auto"
            >
              Apply
            </button>
          </div>
        </form>
      </Card>

      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <Card>
          <div className="text-xs font-medium uppercase tracking-wide text-zinc-400">Revenue</div>
          <div className="mt-1 text-xl font-bold text-zinc-900">{formatMoney(revenue)}</div>
        </Card>
        <Card>
          <div className="text-xs font-medium uppercase tracking-wide text-zinc-400">Sales</div>
          <div className="mt-1 text-xl font-bold text-zinc-900">{byType("sale").length}</div>
        </Card>
        <Card>
          <div className="text-xs font-medium uppercase tracking-wide text-zinc-400">Swaps</div>
          <div className="mt-1 text-xl font-bold text-zinc-900">{byType("swap").length}</div>
        </Card>
        <Card>
          <div className="text-xs font-medium uppercase tracking-wide text-zinc-400">Repairs</div>
          <div className="mt-1 text-xl font-bold text-zinc-900">{byType("repair").length}</div>
        </Card>
      </div>

      {Object.keys(paymentBreakdown).length > 0 && (
        <Card title="Revenue by payment method">
          <div className="flex flex-wrap gap-4 text-sm">
            {Object.entries(paymentBreakdown).map(([k, v]) => (
              <div key={k} className="flex items-center gap-2">
                <Badge tone="blue">{PAYMENT_LABELS[k] ?? k}</Badge>
                <span className="font-semibold text-zinc-900">{formatMoney(v)}</span>
              </div>
            ))}
          </div>
        </Card>
      )}

      <Card title="Transactions">
        {txs.length === 0 ? (
          <EmptyState>No transactions match these filters.</EmptyState>
        ) : (
          <>
            <div className="hidden overflow-x-auto sm:block">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-zinc-100 text-left text-xs uppercase tracking-wide text-zinc-400">
                    <th className="py-2 pr-4 font-medium">Date</th>
                    {isOwner && <th className="py-2 pr-4 font-medium">Shop</th>}
                    <th className="py-2 pr-4 font-medium">Staff</th>
                    <th className="py-2 pr-4 font-medium">Type</th>
                    <th className="py-2 pr-4 font-medium">Items</th>
                    <th className="py-2 pr-4 font-medium">Payment</th>
                    <th className="py-2 pr-4 text-right font-medium">Amount</th>
                    <th className="py-2 font-medium"></th>
                  </tr>
                </thead>
                <tbody>
                  {txs.map((t) => (
                    <ReportRow key={t.id} t={t} isOwner={isOwner} />
                  ))}
                </tbody>
              </table>
            </div>
            <ul className="space-y-2 sm:hidden">
              {txs.map((t) => (
                <li key={t.id} className="rounded-lg border border-zinc-200 bg-zinc-50 p-3">
                  <div className="flex items-start justify-between gap-3">
                    <div className="min-w-0">
                      <div className="flex flex-wrap items-center gap-2">
                        <Badge tone={t.type === "sale" ? "green" : t.type === "swap" ? "blue" : "gray"}>
                          {t.type}
                        </Badge>
                        {isOwner && t.shop_name && (
                          <span className="text-xs text-zinc-500">{t.shop_name}</span>
                        )}
                      </div>
                      <div className="mt-1 text-sm font-medium text-zinc-900">
                        {t.items.map((i) => (i.direction === "out" ? "−" : "+") + i.model_name).join(", ") ||
                          "—"}
                      </div>
                      <div className="mt-0.5 text-xs text-zinc-500">
                        {formatDateTime(t.date)} · {t.staff_name ?? "—"} ·{" "}
                        {PAYMENT_LABELS[t.payment_method] ?? t.payment_method}
                      </div>
                    </div>
                    <div className="shrink-0 text-right">
                      <div className="text-sm font-semibold text-zinc-900">{formatMoney(t.amount)}</div>
                      <Link
                        href={`/transactions/${t.id}`}
                        className="text-xs font-medium text-zinc-500 underline hover:text-zinc-800"
                      >
                        Receipt
                      </Link>
                    </div>
                  </div>
                </li>
              ))}
            </ul>
          </>
        )}
      </Card>
    </div>
  );
}

function ReportRow({ t, isOwner }: { t: TransactionWithDetails; isOwner: boolean }) {
  return (
    <tr className="border-b border-zinc-50">
      <td className="py-2 pr-4 text-zinc-500">{formatDateTime(t.date)}</td>
      {isOwner && <td className="py-2 pr-4">{t.shop_name ?? "—"}</td>}
      <td className="py-2 pr-4 text-zinc-500">{t.staff_name ?? "—"}</td>
      <td className="py-2 pr-4">
        <Badge tone={t.type === "sale" ? "green" : t.type === "swap" ? "blue" : "gray"}>
          {t.type}
        </Badge>
      </td>
      <td className="py-2 pr-4 text-zinc-700">
        {t.items.map((i) => (i.direction === "out" ? "−" : "+") + i.model_name).join(", ") || "—"}
      </td>
      <td className="py-2 pr-4 text-zinc-500">{PAYMENT_LABELS[t.payment_method] ?? t.payment_method}</td>
      <td className="py-2 pr-4 text-right font-semibold text-zinc-900">{formatMoney(t.amount)}</td>
      <td className="py-2 text-right">
        <Link
          href={`/transactions/${t.id}`}
          className="text-xs font-medium text-zinc-500 underline hover:text-zinc-800"
        >
          Receipt
        </Link>
      </td>
    </tr>
  );
}
