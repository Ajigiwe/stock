import Link from "next/link";
import { redirect } from "next/navigation";
import { getSession, getTransaction } from "@/lib/data";
import { createClient } from "@/lib/supabase/server";
import { formatMoney, formatDateTime } from "@/lib/format";
import { Badge } from "@/components/ui";
import { ReceiptActions } from "@/components/receipt-actions";

export const dynamic = "force-dynamic";

const PAYMENT_LABELS: Record<string, string> = {
  cash: "Cash",
  mobile_money: "Mobile money",
  card: "Card",
  bank_transfer: "Bank transfer",
  other: "Other",
};

const TYPE_LABELS: Record<string, string> = {
  sale: "Sale",
  swap: "Swap",
  repair: "Repair",
};

export default async function ReceiptPage({
  params,
}: {
  params: Promise<{ id: string }>;
}) {
  const { id } = await params;
  const session = await getSession();
  if (!session) redirect("/login");

  const tx = await getTransaction(id);
  if (!tx) {
    return (
      <div className="mx-auto max-w-md py-16 text-center">
        <p className="text-sm text-zinc-600">Transaction not found.</p>
        <Link href="/reports" className="mt-3 inline-block text-sm font-medium text-zinc-900 underline">
          Back to reports
        </Link>
      </div>
    );
  }

  const supabase = await createClient();
  const { data: shop } = await supabase
    .from("shops")
    .select("name, location, phone")
    .eq("id", tx.shop_id)
    .maybeSingle();

  const out = tx.items.filter((i) => i.direction === "out");
  const inn = tx.items.filter((i) => i.direction === "in");
  const receiptNo = tx.id.slice(-8).toUpperCase();
  const itemLine = (i: (typeof tx.items)[number]) =>
    `${i.qty} x ${i.model_name} (${i.condition})`;

  const shareLines = [
    `*${shop?.name ?? "Mr Jeff Stock"}* — Receipt ${receiptNo}`,
    shop?.phone ? `Tel: ${shop.phone}` : null,
    `Date: ${formatDateTime(tx.date)}`,
    `Type: ${TYPE_LABELS[tx.type] ?? tx.type}`,
    out.length ? `Items: ${out.map(itemLine).join(", ")}` : null,
    inn.length ? `Trade-in: ${inn.map(itemLine).join(", ")}` : null,
    `Total: ${formatMoney(tx.amount)} (${PAYMENT_LABELS[tx.payment_method] ?? tx.payment_method})`,
    tx.customer_name ? `Customer: ${tx.customer_name}` : null,
    "Thank you for your business!",
  ].filter(Boolean);
  const shareText = shareLines.join("\n");

  return (
    <div className="mx-auto max-w-md space-y-4">
      <div className="no-print flex items-center justify-between">
        <Link
          href={`/shops/${tx.shop_id}`}
          className="text-sm text-zinc-500 hover:text-zinc-800"
        >
          ← Back to shop
        </Link>
        <Link
          href="/transactions/new"
          className="text-sm font-medium text-zinc-900 underline"
        >
          New transaction
        </Link>
      </div>

      <div className="rounded-xl border border-zinc-200 bg-white p-6 shadow-sm">
        <div className="text-center">
          <h1 className="text-lg font-bold text-zinc-900">
            {shop?.name ?? "Mr Jeff Stock"}
          </h1>
          {shop?.location && (
            <p className="text-xs text-zinc-500">{shop.location}</p>
          )}
          {shop?.phone && <p className="text-xs text-zinc-500">Tel: {shop.phone}</p>}
        </div>

        <div className="my-4 border-t border-dashed border-zinc-300" />

        <div className="flex items-center justify-between text-xs text-zinc-500">
          <span>Receipt #{receiptNo}</span>
          <Badge tone={tx.type === "sale" ? "green" : tx.type === "swap" ? "blue" : "gray"}>
            {TYPE_LABELS[tx.type] ?? tx.type}
          </Badge>
        </div>
        <div className="mt-1 text-xs text-zinc-500">{formatDateTime(tx.date)}</div>
        {tx.staff_name && (
          <div className="text-xs text-zinc-500">Served by {tx.staff_name}</div>
        )}
        {(tx.customer_name || tx.customer_phone) && (
          <div className="mt-1 text-xs text-zinc-500">
            Customer: {tx.customer_name || "—"}
            {tx.customer_phone ? ` · ${tx.customer_phone}` : ""}
          </div>
        )}

        <div className="my-4 border-t border-dashed border-zinc-300" />

        {out.length > 0 && (
          <div>
            <div className="mb-1 text-[11px] font-semibold uppercase tracking-wide text-zinc-400">
              Items
            </div>
            <ul className="space-y-1 text-sm text-zinc-800">
              {out.map((i) => (
                <li key={i.id} className="flex justify-between gap-2">
                  <span>{i.model_name}</span>
                  <span className="text-zinc-500">x{i.qty}</span>
                </li>
              ))}
            </ul>
          </div>
        )}

        {inn.length > 0 && (
          <div className="mt-3">
            <div className="mb-1 text-[11px] font-semibold uppercase tracking-wide text-zinc-400">
              Trade-in received
            </div>
            <ul className="space-y-1 text-sm text-zinc-800">
              {inn.map((i) => (
                <li key={i.id} className="flex justify-between gap-2">
                  <span>{i.model_name}</span>
                  <span className="text-zinc-500">x{i.qty}</span>
                </li>
              ))}
            </ul>
          </div>
        )}

        <div className="my-4 border-t border-dashed border-zinc-300" />

        <div className="flex items-end justify-between">
          <span className="text-sm font-medium text-zinc-500">
            {tx.type === "swap" ? "Top-up paid" : tx.type === "repair" ? "Repair charge" : "Total"}
          </span>
          <span className="text-xl font-bold text-zinc-900">{formatMoney(tx.amount)}</span>
        </div>
        <div className="mt-0.5 text-right text-xs text-zinc-500">
          Paid by {PAYMENT_LABELS[tx.payment_method] ?? tx.payment_method}
        </div>

        <p className="mt-5 text-center text-xs text-zinc-400">
          Thank you for your business!
        </p>
      </div>

      <ReceiptActions shareText={shareText} customerPhone={tx.customer_phone} />
    </div>
  );
}
