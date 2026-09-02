import { createClient } from "@/lib/supabase/server";
import type { Database } from "@/lib/database.types";

type Transaction = Database["public"]["Tables"]["transactions"]["Row"];
type TransactionItem = Database["public"]["Tables"]["transaction_items"]["Row"];

export const dynamic = "force-dynamic";

const TX_TYPES = ["sale", "swap", "repair"] as const;
const PAYMENT_METHODS = [
  "cash",
  "mobile_money",
  "card",
  "bank_transfer",
  "other",
] as const;

// Caps the export so a single request can't try to stream the entire history.
const MAX_ROWS = 5000;

function isDate(v: string | null): v is string {
  return !!v && /^\d{4}-\d{2}-\d{2}$/.test(v) && !Number.isNaN(Date.parse(v));
}

export async function GET(request: Request) {
  const supabase = await createClient();
  const {
    data: { user },
  } = await supabase.auth.getUser();
  if (!user) {
    return new Response("Unauthorized", { status: 401 });
  }

  const url = new URL(request.url);
  const shopParam = url.searchParams.get("shop");
  const fromParam = url.searchParams.get("from");
  const toParam = url.searchParams.get("to");
  const typeParam = url.searchParams.get("type");
  const paymentParam = url.searchParams.get("payment");

  const from = isDate(fromParam) ? fromParam : undefined;
  const to = isDate(toParam) ? toParam : undefined;
  // Unknown enum values used to be cast straight into the query, producing a
  // 500 from Postgres. Drop them instead.
  const type = TX_TYPES.find((t) => t === typeParam);
  const payment = PAYMENT_METHODS.find((p) => p === paymentParam);

  // Resolve the caller's shop scope. A failed read must fail closed.
  const { data: profile, error: profileError } = await supabase
    .from("users")
    .select("role, shop_id")
    .eq("id", user.id)
    .maybeSingle();
  if (profileError) return new Response("Could not verify your account", { status: 500 });
  if (!profile) return new Response("Forbidden", { status: 403 });

  const isOwner = profile.role === "owner";
  const effectiveShopId = isOwner ? shopParam ?? undefined : profile.shop_id ?? undefined;
  if (!isOwner && !effectiveShopId) {
    return new Response("Forbidden", { status: 403 });
  }

  let q = supabase
    .from("transactions")
    .select("*")
    .order("date", { ascending: false })
    .limit(MAX_ROWS);
  if (effectiveShopId) q = q.eq("shop_id", effectiveShopId);
  if (from) q = q.gte("date", `${from}T00:00:00Z`);
  if (to) {
    const end = new Date(`${to}T00:00:00Z`);
    end.setUTCDate(end.getUTCDate() + 1);
    q = q.lt("date", end.toISOString());
  }
  if (type) q = q.eq("type", type);
  if (payment) q = q.eq("payment_method", payment);

  const { data: txs, error } = await q;
  if (error) {
    return new Response(error.message, { status: 500 });
  }

  // Hydrate names + items. Only the models and staff actually referenced are
  // fetched; this used to read all of phone_models and users on every export.
  const txIds = (txs ?? []).map((t) => t.id);
  const staffIds = [...new Set((txs ?? []).map((t) => t.staff_id))];
  const shopIds = [...new Set((txs ?? []).map((t) => t.shop_id))];

  const { data: itemRows } = txIds.length
    ? await supabase.from("transaction_items").select("*").in("transaction_id", txIds)
    : { data: [] as TransactionItem[] };
  const items = itemRows ?? [];
  const modelIds = [...new Set(items.map((i) => i.phone_model_id))];

  const [shopsRes, staffRes, modelsRes] = await Promise.all([
    shopIds.length
      ? supabase.from("shops").select("id, name").in("id", shopIds)
      : Promise.resolve({ data: [] }),
    staffIds.length
      ? supabase.from("users").select("id, name").in("id", staffIds)
      : Promise.resolve({ data: [] }),
    modelIds.length
      ? supabase
          .from("phone_models")
          .select("id, model_name, condition")
          .in("id", modelIds)
      : Promise.resolve({ data: [] }),
  ]);

  const shopName = new Map((shopsRes.data ?? []).map((s) => [s.id, s.name]));
  const staffName = new Map((staffRes.data ?? []).map((s) => [s.id, s.name]));
  const modelName = new Map(
    (modelsRes.data ?? []).map((m) => [m.id, `${m.model_name} (${m.condition})`]),
  );

  // Group items once instead of filtering the full list per transaction.
  const itemsByTx = new Map<string, TransactionItem[]>();
  for (const i of items) {
    const list = itemsByTx.get(i.transaction_id);
    if (list) list.push(i);
    else itemsByTx.set(i.transaction_id, [i]);
  }

  // A leading =, +, - or @ makes spreadsheet apps treat the cell as a formula.
  const esc = (v: unknown) => {
    let s = String(v ?? "");
    if (/^[=+\-@\t\r]/.test(s)) s = `'${s}`;
    return /[",\n]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
  };

  const header = [
    "date",
    "shop",
    "staff",
    "type",
    "customer",
    "customer_phone",
    "payment_method",
    "amount_ghs",
    "items_out",
    "items_in",
  ];

  const rows = (txs ?? []).map((t: Transaction) => {
    const own = itemsByTx.get(t.id) ?? [];
    const describe = (direction: "out" | "in") =>
      own
        .filter((i) => i.direction === direction)
        .map((i) => `${i.qty} x ${modelName.get(i.phone_model_id) ?? "unknown"}`)
        .join("; ");
    return [
      t.date,
      shopName.get(t.shop_id) ?? "",
      staffName.get(t.staff_id) ?? "",
      t.type,
      t.customer_name ?? "",
      t.customer_phone ?? "",
      t.payment_method,
      String(t.amount ?? 0),
      describe("out"),
      describe("in"),
    ]
      .map(esc)
      .join(",");
  });

  const csv = "\uFEFF" + [header.join(","), ...rows].join("\n");

  const filename = `report-${from ?? "all"}-${to ?? "all"}.csv`;
  return new Response(csv, {
    status: 200,
    headers: {
      "Content-Type": "text/csv; charset=utf-8",
      "Content-Disposition": `attachment; filename="${filename}"`,
    },
  });
}
