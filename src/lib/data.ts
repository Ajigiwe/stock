import "server-only";

import { createClient } from "@/lib/supabase/server";
import { todayISO, addDays } from "@/lib/format";
import type { Database } from "@/lib/database.types";

type Shop = Database["public"]["Tables"]["shops"]["Row"];
type UserProfile = Database["public"]["Tables"]["users"]["Row"];
type PhoneModel = Database["public"]["Tables"]["phone_models"]["Row"];
type Transaction = Database["public"]["Tables"]["transactions"]["Row"];
type TransactionItem = Database["public"]["Tables"]["transaction_items"]["Row"];
type StockAdjustment = Database["public"]["Tables"]["stock_adjustments"]["Row"];

export type {
  Shop,
  UserProfile,
  PhoneModel,
  Transaction,
  TransactionItem,
  StockAdjustment,
};

export type SessionUser = {
  id: string;
  email?: string;
  profile: UserProfile | null;
};

export type TransactionWithDetails = Transaction & {
  shop_name: string | null;
  staff_name: string | null;
  items: {
    id: string;
    direction: "out" | "in";
    qty: number;
    model_name: string;
    condition: "new" | "used";
  }[];
};

export type DailyRow = {
  phone_model_id: string;
  model_name: string;
  condition: "new" | "used";
  sold: number;
  swapped_out: number;
};

export type ShopDailySummary = {
  shop: Shop;
  rows: DailyRow[];
  total_sales: number;
  total_swaps: number;
  revenue: number;
  low_stock: PhoneModel[];
};

export async function getSession(): Promise<SessionUser | null> {
  const supabase = await createClient();
  const {
    data: { user },
  } = await supabase.auth.getUser();
  if (!user) return null;

  const { data: profile } = await supabase
    .from("users")
    .select("*")
    .eq("id", user.id)
    .maybeSingle();

  return { id: user.id, email: user.email, profile };
}

export async function requireSession(): Promise<SessionUser> {
  const session = await getSession();
  if (!session) {
    throw new Error("Not authenticated");
  }
  return session;
}

export async function getShops(): Promise<Shop[]> {
  const supabase = await createClient();
  const { data, error } = await supabase
    .from("shops")
    .select("*")
    .order("name");
  if (error) throw new Error(error.message);
  return data ?? [];
}

export async function getStock(shopId?: string): Promise<PhoneModel[]> {
  const supabase = await createClient();
  let q = supabase
    .from("phone_models")
    .select("*")
    .order("model_name");
  if (shopId) q = q.eq("shop_id", shopId);
  const { data, error } = await q;
  if (error) throw new Error(error.message);
  return data ?? [];
}

export async function getTransactions(opts: {
  shopId?: string;
  from?: string; // YYYY-MM-DD inclusive
  to?: string; // YYYY-MM-DD inclusive
  type?: string;
  paymentMethod?: string;
}): Promise<TransactionWithDetails[]> {
  const supabase = await createClient();

  let q = supabase.from("transactions").select("*").order("date", { ascending: false });
  if (opts.shopId) q = q.eq("shop_id", opts.shopId);
  if (opts.from) q = q.gte("date", `${opts.from}T00:00:00Z`);
  if (opts.to) q = q.lt("date", `${addDays(opts.to, 1)}T00:00:00Z`);
  if (opts.type) q = q.eq("type", opts.type as "sale" | "swap" | "repair");
  if (opts.paymentMethod) q = q.eq("payment_method", opts.paymentMethod as Database["public"]["Enums"]["payment_method"]);

  const { data: txs, error } = await q;
  if (error) throw new Error(error.message);

  return hydrateTransactions(supabase, txs ?? []);
}

async function hydrateTransactions(
  supabase: Awaited<ReturnType<typeof createClient>>,
  txs: Transaction[],
): Promise<TransactionWithDetails[]> {
  const txIds = txs.map((t) => t.id);
  const shopIds = new Set(txs.map((t) => t.shop_id));
  const staffIds = new Set(txs.map((t) => t.staff_id));

  const [itemsRes, shopsRes, staffRes] = await Promise.all([
    txIds.length
      ? supabase.from("transaction_items").select("*").in("transaction_id", txIds)
      : Promise.resolve({ data: [] as TransactionItem[], error: null }),
    shopIds.size
      ? supabase.from("shops").select("id, name").in("id", [...shopIds])
      : Promise.resolve({ data: [] as Pick<Shop, "id" | "name">[], error: null }),
    staffIds.size
      ? supabase.from("users").select("id, name").in("id", [...staffIds])
      : Promise.resolve({ data: [] as Pick<UserProfile, "id" | "name">[], error: null }),
  ]);

  const items = itemsRes.data ?? [];
  const shops = shopsRes.data ?? [];
  const staff = staffRes.data ?? [];
  const shopName = new Map(shops.map((s) => [s.id, s.name]));
  const staffName = new Map(staff.map((s) => [s.id, s.name]));

  const modelIds = new Set(items.map((i) => i.phone_model_id));
  const modelsRes = modelIds.size
    ? await supabase
        .from("phone_models")
        .select("id, model_name, condition")
        .in("id", [...modelIds])
    : { data: [] as Pick<PhoneModel, "id" | "model_name" | "condition">[], error: null };
  const modelInfo = new Map(
    (modelsRes.data ?? []).map((m) => [m.id, m]),
  );

  return txs.map((t) => ({
    ...t,
    shop_name: shopName.get(t.shop_id) ?? null,
    staff_name: staffName.get(t.staff_id) ?? null,
    items: items
      .filter((i) => i.transaction_id === t.id)
      .map((i) => {
        const m = modelInfo.get(i.phone_model_id);
        return {
          id: i.id,
          direction: i.direction,
          qty: i.qty,
          model_name: m?.model_name ?? "Unknown model",
          condition: m?.condition ?? "used",
        };
      }),
  }));
}

export async function getTodayTransactions(shopId?: string) {
  return getTransactions({
    shopId,
    from: todayISO(),
    to: todayISO(),
  });
}

export async function getDailySummary(shopId: string): Promise<ShopDailySummary> {
  const supabase = await createClient();
  const [shopRes, stock, txs] = await Promise.all([
    supabase.from("shops").select("*").eq("id", shopId).maybeSingle(),
    getStock(shopId),
    getTodayTransactions(shopId),
  ]);

  const shop = shopRes.data;
  if (!shop) throw new Error("Shop not found");

  const map = new Map<string, DailyRow>();
  for (const t of txs) {
    for (const item of t.items) {
      const key = `${item.model_name}|${item.condition}`;
      const row = map.get(key) ?? {
        phone_model_id: "",
        model_name: item.model_name,
        condition: item.condition,
        sold: 0,
        swapped_out: 0,
      };
      if (item.direction === "out") {
        if (t.type === "swap") row.swapped_out += item.qty;
        else row.sold += item.qty;
      }
      map.set(key, row);
    }
  }

  const lowStock = stock.filter((s) => s.available <= s.low_stock_threshold);

  return {
    shop,
    rows: [...map.values()],
    total_sales: txs.filter((t) => t.type === "sale").length,
    total_swaps: txs.filter((t) => t.type === "swap").length,
    revenue: txs.reduce((acc, t) => acc + (t.amount ?? 0), 0),
    low_stock: lowStock,
  };
}

export async function getDashboard(): Promise<ShopDailySummary[]> {
  const shops = await getShops();
  const summaries = await Promise.all(shops.map((s) => getDailySummary(s.id)));
  return summaries;
}

export async function getAdjustments(shopId?: string, limit = 50): Promise<StockAdjustment[]> {
  const supabase = await createClient();
  let q = supabase.from("stock_adjustments").select("*").order("date", { ascending: false }).limit(limit);
  if (shopId) q = q.eq("shop_id", shopId);
  const { data, error } = await q;
  if (error) throw new Error(error.message);
  return data ?? [];
}
