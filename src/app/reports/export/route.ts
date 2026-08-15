import { createClient } from "@/lib/supabase/server";
import type { Database } from "@/lib/database.types";

type Transaction = Database["public"]["Tables"]["transactions"]["Row"];
type TransactionItem = Database["public"]["Tables"]["transaction_items"]["Row"];

export async function GET(request: Request) {
  const supabase = await createClient();
  const {
    data: { user },
  } = await supabase.auth.getUser();
  if (!user) {
    return new Response("Unauthorized", { status: 401 });
  }

  const url = new URL(request.url);
  const shopId = url.searchParams.get("shop") ?? undefined;
  const from = url.searchParams.get("from") ?? undefined;
  const to = url.searchParams.get("to") ?? undefined;
  const type = url.searchParams.get("type") ?? undefined;
  const payment = url.searchParams.get("payment") ?? undefined;

  // Resolve the caller's shop scope.
  const { data: profile } = await supabase
    .from("users")
    .select("role, shop_id")
    .eq("id", user.id)
    .maybeSingle();

  const isOwner = profile?.role === "owner";
  const effectiveShopId = isOwner ? shopId : profile?.shop_id ?? undefined;

  let q = supabase.from("transactions").select("*").order("date", { ascending: false });
  if (effectiveShopId) q = q.eq("shop_id", effectiveShopId);
  if (from) q = q.gte("date", `${from}T00:00:00Z`);
  if (to) {
    const end = new Date(`${to}T00:00:00Z`);
    end.setUTCDate(end.getUTCDate() + 1);
    q = q.lt("date", end.toISOString());
  }
  if (type) q = q.eq("type", type as Database["public"]["Enums"]["tx_type"]);
  if (payment) q = q.eq("payment_method", payment as Database["public"]["Enums"]["payment_method"]);

  const { data: txs, error } = await q;
  if (error) {
    return new Response(error.message, { status: 500 });
  }

  // Hydrate names + items.
  const txIds = (txs ?? []).map((t) => t.id);
  const [itemsRes, shopsRes, staffRes, modelsRes] = await Promise.all([
    txIds.length
      ? supabase.from("transaction_items").select("*").in("transaction_id", txIds)
      : Promise.resolve({ data: [] as TransactionItem[], error: null }),
    supabase.from("shops").select("id, name"),
    supabase.from("users").select("id, name"),
    supabase.from("phone_models").select("id, model_name, condition, cost_price"),
  ]);

  const items = itemsRes.data ?? [];
  const shopName = new Map((shopsRes.data ?? []).map((s) => [s.id, s.name]));
  const staffName = new Map((staffRes.data ?? []).map((s) => [s.id, s.name]));
  const modelName = new Map((modelsRes.data ?? []).map((m) => [m.id, `${m.model_name} (${m.condition})`]));
  const modelCost = new Map(
    (modelsRes.data ?? []).map((m) => [m.id, m.cost_price ?? 0]),
  );

  const esc = (v: unknown) => {
    const s = String(v ?? "");
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
    ...(isOwner ? ["cogs_ghs", "est_profit_ghs"] : []),
    "items_out",
    "items_in",
  ];

  const rows = (txs ?? []).map((t: Transaction) => {
    const outRows = items.filter(
      (i) => i.transaction_id === t.id && i.direction === "out",
    );
    const out = outRows
      .map((i) => `${i.qty} x ${modelName.get(i.phone_model_id)}`)
      .join("; ");
    const inn = items
      .filter((i) => i.transaction_id === t.id && i.direction === "in")
      .map((i) => `${i.qty} x ${modelName.get(i.phone_model_id)}`)
      .join("; ");
    const cogs = outRows.reduce(
      (s, i) => s + i.qty * (modelCost.get(i.phone_model_id) ?? 0),
      0,
    );
    return [
      t.date,
      shopName.get(t.shop_id) ?? "",
      staffName.get(t.staff_id) ?? "",
      t.type,
      t.customer_name ?? "",
      t.customer_phone ?? "",
      t.payment_method,
      String(t.amount ?? 0),
      ...(isOwner
        ? [String(cogs), String((t.amount ?? 0) - cogs)]
        : []),
      out,
      inn,
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
