import "server-only";

import { unstable_cache } from "next/cache";
import { createClient } from "@/lib/supabase/server";
import { getCachedAdminClient } from "@/lib/admin";
import { todayISO, addDays } from "@/lib/format";
import type { Database } from "@/lib/database.types";
import type { SupabaseClient } from "@supabase/supabase-js";

export const DATA_CACHE_REVALIDATE_SECONDS = 30;
export const DATA_CACHE_TAGS = [
  "shops",
  "stock",
  "transactions",
  "requests",
  "adjustments",
  "swaps",
  "logs",
  "users",
] as const;
export type DataCacheTag = (typeof DATA_CACHE_TAGS)[number];

type Shop = Database["public"]["Tables"]["shops"]["Row"];
type UserProfile = Database["public"]["Tables"]["users"]["Row"];
type PhoneModel = Database["public"]["Tables"]["phone_models"]["Row"];
type Transaction = Database["public"]["Tables"]["transactions"]["Row"];
type TransactionItem = Database["public"]["Tables"]["transaction_items"]["Row"];
type StockAdjustment = Database["public"]["Tables"]["stock_adjustments"]["Row"];
type StockRequest = Database["public"]["Tables"]["stock_requests"]["Row"];
type SwappedPhone = Database["public"]["Tables"]["swapped_phones"]["Row"];

export type {
  Shop,
  UserProfile,
  PhoneModel,
  Transaction,
  TransactionItem,
  StockAdjustment,
  StockRequest,
  SwappedPhone,
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
    cost_price: number | null;
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
  total_repairs: number;
  revenue: number;
  cogs: number;
  profit: number;
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

export type CacheTags = (typeof DATA_CACHE_TAGS)[number];

// ---------------------------------------------------------------------------
// Cached owner / global reads
//
// These use the service-role client (bypasses RLS). They are only ever called
// from owner-gated or per-shop-scoped pages, so caching the (global) results
// is safe — no authenticated user can reach data they aren't allowed to see.
// They are invalidated on mutation with `updateTag` in src/lib/actions.ts.
// ---------------------------------------------------------------------------

function revalidateOpts(
  tags: CacheTags[],
  revalidate = DATA_CACHE_REVALIDATE_SECONDS,
) {
  return { tags: [...tags], revalidate };
}

export const getCachedShops = unstable_cache(
  async () => {
    const a = getCachedAdminClient();
    const { data, error } = await a.from("shops").select("*").order("name");
    if (error) throw new Error(error.message);
    return data ?? [];
  },
  ["shops"],
  revalidateOpts(["shops"]),
);

export const getCachedStock = unstable_cache(
  async (shopId?: string): Promise<PhoneModel[]> => {
    const a = getCachedAdminClient();
    let q = a.from("phone_models").select("*").order("model_name");
    if (shopId) q = q.eq("shop_id", shopId);
    const { data, error } = await q;
    if (error) throw new Error(error.message);
    return data ?? [];
  },
  ["stock"],
  revalidateOpts(["stock"]),
);

export const getCachedTransactions = unstable_cache(
  async (opts: {
    shopId?: string;
    from?: string;
    to?: string;
    type?: string;
    paymentMethod?: string;
    limit?: number;
  }): Promise<TransactionWithDetails[]> => {
    const a = getCachedAdminClient();
    let q = a.from("transactions").select("*").order("date", { ascending: false });
    if (opts.shopId) q = q.eq("shop_id", opts.shopId);
    if (opts.from) q = q.gte("date", `${opts.from}T00:00:00Z`);
    if (opts.to) q = q.lt("date", `${addDays(opts.to, 1)}T00:00:00Z`);
    if (opts.type) q = q.eq("type", opts.type as "sale" | "swap" | "repair");
    if (opts.paymentMethod)
      q = q.eq(
        "payment_method",
        opts.paymentMethod as Database["public"]["Enums"]["payment_method"],
      );
    if (opts.limit) q = q.limit(opts.limit);

    const { data: txs, error } = await q;
    if (error) throw new Error(error.message);
    return hydrateTransactions(a, txs ?? []);
  },
  ["transactions"],
  revalidateOpts(["transactions"]),
);

export const getCachedStockRequests = unstable_cache(
  async (opts: {
    shopId?: string;
    status?: "pending" | "approved" | "rejected";
    limit?: number;
  }): Promise<StockRequestWithDetails[]> => {
    const a = getCachedAdminClient();
    let q = a
      .from("stock_requests")
      .select("*")
      .order("created_at", { ascending: false })
      .limit(opts.limit ?? 100);
    if (opts.shopId) q = q.eq("shop_id", opts.shopId);
    if (opts.status) q = q.eq("status", opts.status);
    const { data, error } = await q;
    if (error) throw new Error(error.message);
    const requests = data ?? [];

    const shopIds = new Set(requests.map((r) => r.shop_id));
    const staffIds = new Set(requests.map((r) => r.staff_id));
    const modelIds = new Set(
      requests.map((r) => r.phone_model_id).filter((x): x is string => !!x),
    );

    const [shopsRes, staffRes, modelsRes] = await Promise.all([
      shopIds.size
        ? a.from("shops").select("id, name").in("id", [...shopIds])
        : { data: [] as Pick<Shop, "id" | "name">[], error: null },
      staffIds.size
        ? a.from("users").select("id, name").in("id", [...staffIds])
        : { data: [] as Pick<UserProfile, "id" | "name">[], error: null },
      modelIds.size
        ? a.from("phone_models").select("id, model_name").in("id", [...modelIds])
        : { data: [] as Pick<PhoneModel, "id" | "model_name">[], error: null },
    ]);

    const shopName = new Map((shopsRes.data ?? []).map((s) => [s.id, s.name]));
    const staffName = new Map((staffRes.data ?? []).map((s) => [s.id, s.name]));
    const modelName = new Map((modelsRes.data ?? []).map((m) => [m.id, m.model_name]));

    return requests.map((r) => ({
      ...r,
      shop_name: shopName.get(r.shop_id) ?? null,
      staff_name: staffName.get(r.staff_id) ?? null,
      model_name_display: r.model_name ?? modelName.get(r.phone_model_id ?? "") ?? null,
    }));
  },
  ["stock-requests"],
  revalidateOpts(["requests"]),
);

export const getCachedAdjustments = unstable_cache(
  async (shopId?: string, limit = 50): Promise<StockAdjustment[]> => {
    const a = getCachedAdminClient();
    let q = a
      .from("stock_adjustments")
      .select("*")
      .order("date", { ascending: false })
      .limit(limit);
    if (shopId) q = q.eq("shop_id", shopId);
    const { data, error } = await q;
    if (error) throw new Error(error.message);
    return data ?? [];
  },
  ["adjustments"],
  revalidateOpts(["adjustments"]),
);

export const getCachedSwappedPhones = unstable_cache(
  async (opts: {
    shopId?: string;
    status?: "in_stock" | "sold" | "returned";
    limit?: number;
  }): Promise<SwappedPhone[]> => {
    const a = getCachedAdminClient();
    let q = a
      .from("swapped_phones")
      .select("*")
      .order("created_at", { ascending: false })
      .limit(opts.limit ?? 200);
    if (opts.shopId) q = q.eq("shop_id", opts.shopId);
    if (opts.status) q = q.eq("status", opts.status);
    const { data, error } = await q;
    if (error) throw new Error(error.message);
    return data ?? [];
  },
  ["swapped-phones"],
  revalidateOpts(["swaps"]),
);

// Per-shop daily summary composed from cached, self-contained admin queries
// (no nested cache) — safe for any authenticated viewer because the result is
// keyed to a single shop.
export const getCachedShopSummary = unstable_cache(
  async (
    shopId: string,
    from: string,
    to: string,
    knownShop?: Shop,
  ): Promise<ShopDailySummary> => {
    const a = getCachedAdminClient();
    const [stockRes, txsRes, shopRes] = await Promise.all([
      a.from("phone_models").select("*").eq("shop_id", shopId).order("model_name"),
      getCachedTransactions({ shopId, from, to }),
      knownShop
        ? { data: knownShop, error: null }
        : a.from("shops").select("*").eq("id", shopId).maybeSingle(),
    ]);
    if (stockRes.error) throw new Error(stockRes.error.message);
    if (!shopRes.data) throw new Error("Shop not found");

    const stock = stockRes.data ?? [];
    const txs = txsRes;
    const shop = shopRes.data as Shop;
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
    const revenue = txs.reduce((acc, t) => acc + (t.amount ?? 0), 0);
    const cogs = txs.reduce(
      (acc, t) =>
        acc + t.items.filter((i) => i.direction === "out").reduce((s, i) => s + i.qty * (i.cost_price ?? 0), 0),
      0,
    );

    return {
      shop,
      rows: [...map.values()],
      total_sales: txs.filter((t) => t.type === "sale").length,
      total_swaps: txs.filter((t) => t.type === "swap").length,
      total_repairs: txs.filter((t) => t.type === "repair").length,
      revenue,
      cogs,
      profit: revenue - cogs,
      low_stock: lowStock,
    };
  },
  ["shop-summary"],
  revalidateOpts(["stock", "transactions"]),
);

export const getCachedLoginLogs = unstable_cache(
  async (limit = 100) => {
    const a = getCachedAdminClient();
    const { data, error } = await a
      .from("login_logs")
      .select("*")
      .order("created_at", { ascending: false })
      .limit(limit);
    if (error) throw new Error(error.message);
    return data ?? [];
  },
  ["login-logs"],
  revalidateOpts(["logs"]),
);

export const getCachedStockLogs = unstable_cache(
  async (limit = 200): Promise<StockLogEntry[]> => {
    const a = getCachedAdminClient();
    const { data, error } = await a
      .from("stock_logs")
      .select("*")
      .order("created_at", { ascending: false })
      .limit(limit);
    if (error) throw new Error(error.message);
    const logs = data ?? [];

    const staffIds = new Set(logs.map((l) => l.staff_id));
    const shopIds = new Set(logs.map((l) => l.shop_id));
    const [staffRes, shopRes] = await Promise.all([
      staffIds.size
        ? a.from("users").select("id, name").in("id", [...staffIds])
        : { data: [] as { id: string; name: string }[], error: null },
      shopIds.size
        ? a.from("shops").select("id, name").in("id", [...shopIds])
        : { data: [] as { id: string; name: string }[], error: null },
    ]);
    const staffName = new Map((staffRes.data ?? []).map((u) => [u.id, u.name]));
    const shopName = new Map((shopRes.data ?? []).map((s) => [s.id, s.name]));

    return logs.map((l) => ({
      ...l,
      staff_name: staffName.get(l.staff_id) ?? null,
      shop_name: shopName.get(l.shop_id) ?? null,
    }));
  },
  ["stock-logs"],
  revalidateOpts(["logs"]),
);

// ---------------------------------------------------------------------------
// Devices (owner inventory across all shops)
// ---------------------------------------------------------------------------

export type DeviceCell = {
  shopId: string;
  available: number;
  low: boolean;
  threshold: number;
};

export type DeviceSale = {
  transactionId: string;
  date: string;
  shopId: string;
  shopName: string | null;
  staffName: string | null;
  customerName: string | null;
  customerPhone: string | null;
  qty: number;
  amount: number;
};

export type DeviceRow = {
  key: string;
  model_name: string;
  condition: "new" | "used";
  total: number;
  sold: number; // total units sold
  low: number; // number of shops running low on this model
  perShop: DeviceCell[];
  sales: DeviceSale[]; // newest first
};

export type DevicesData = {
  shops: Shop[];
  rows: DeviceRow[];
};

export async function getDevicesData(): Promise<DevicesData> {
  const [shops, stock, txs] = await Promise.all([
    getCachedShops(),
    getCachedStock(),
    getCachedTransactions({}),
  ]);
  const shopIndex = new Map(shops.map((s, i) => [s.id, i]));

  const map = new Map<string, DeviceRow>();
  const initRow = (model_name: string, condition: "new" | "used"): DeviceRow => ({
    key: `${model_name}|${condition}`,
    model_name,
    condition,
    total: 0,
    sold: 0,
    low: 0,
    perShop: shops.map((s) => ({
      shopId: s.id,
      available: 0,
      low: false,
      threshold: 0,
    })),
    sales: [],
  });

  for (const m of stock) {
    const key = `${m.model_name}|${m.condition}`;
    let row = map.get(key);
    if (!row) {
      row = initRow(m.model_name, m.condition);
      map.set(key, row);
    }
    const idx = shopIndex.get(m.shop_id);
    if (idx != null) {
      const cell = row.perShop[idx];
      cell.available = m.available;
      cell.threshold = m.low_stock_threshold;
      cell.low = m.available <= m.low_stock_threshold;
      row.low = row.perShop.filter((c) => c.low).length;
    }
    row.total += m.available;
  }

  for (const t of txs) {
    for (const it of t.items) {
      if (it.direction !== "out") continue;
      const key = `${it.model_name}|${it.condition}`;
      let row = map.get(key);
      if (!row) {
        row = initRow(it.model_name, it.condition);
        map.set(key, row);
      }
      row.sold += it.qty;
      row.sales.push({
        transactionId: t.id,
        date: t.date,
        shopId: t.shop_id,
        shopName: t.shop_name,
        staffName: t.staff_name,
        customerName: t.customer_name,
        customerPhone: t.customer_phone,
        qty: it.qty,
        amount: t.amount,
      });
    }
  }

  for (const row of map.values()) {
    row.sales.sort((a, b) => b.date.localeCompare(a.date));
  }

  const rows = [...map.values()].sort(
    (a, b) =>
      a.model_name.localeCompare(b.model_name) ||
      a.condition.localeCompare(b.condition),
  );

  return { shops, rows };
}

export async function getTransactions(opts: {
  shopId?: string;
  from?: string; // YYYY-MM-DD inclusive
  to?: string; // YYYY-MM-DD inclusive
  type?: string;
  paymentMethod?: string;
  limit?: number;
}): Promise<TransactionWithDetails[]> {
  const supabase = await createClient();

  let q = supabase.from("transactions").select("*").order("date", { ascending: false });
  if (opts.shopId) q = q.eq("shop_id", opts.shopId);
  if (opts.from) q = q.gte("date", `${opts.from}T00:00:00Z`);
  if (opts.to) q = q.lt("date", `${addDays(opts.to, 1)}T00:00:00Z`);
  if (opts.type) q = q.eq("type", opts.type as "sale" | "swap" | "repair");
  if (opts.paymentMethod) q = q.eq("payment_method", opts.paymentMethod as Database["public"]["Enums"]["payment_method"]);
  if (opts.limit) q = q.limit(opts.limit);

  const { data: txs, error } = await q;
  if (error) throw new Error(error.message);

  return hydrateTransactions(supabase, txs ?? []);
}

export async function getTransaction(
  id: string,
): Promise<TransactionWithDetails | null> {
  const supabase = await createClient();
  const { data, error } = await supabase
    .from("transactions")
    .select("*")
    .eq("id", id)
    .maybeSingle();
  if (error) throw new Error(error.message);
  if (!data) return null;
  const [tx] = await hydrateTransactions(supabase, [data]);
  return tx ?? null;
}

async function hydrateTransactions(
  supabase: SupabaseClient<Database>,
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
        .select("id, model_name, condition, cost_price")
        .in("id", [...modelIds])
    : { data: [] as Pick<PhoneModel, "id" | "model_name" | "condition" | "cost_price">[], error: null };
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
          cost_price: m?.cost_price ?? null,
        };
      }),
  }));
}

export async function getDailySummary(
  shopId: string,
  date: string = todayISO(),
): Promise<ShopDailySummary> {
  return getShopSummary(shopId, date, date);
}

export async function getShopSummary(
  shopId: string,
  from: string,
  to: string,
  known?: Shop,
): Promise<ShopDailySummary> {
  const supabase = await createClient();
  const [stock, txs, fetchedShop] = await Promise.all([
    getStock(shopId),
    getTransactions({ shopId, from, to }),
    known
      ? Promise.resolve(known)
      : supabase
          .from("shops")
          .select("*")
          .eq("id", shopId)
          .maybeSingle()
          .then((r) => r.data as Shop | null),
  ]);

  const shop = known ?? fetchedShop;
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

  const revenue = txs.reduce((acc, t) => acc + (t.amount ?? 0), 0);
  const cogs = txs.reduce(
    (acc, t) =>
      acc +
      t.items
        .filter((i) => i.direction === "out")
        .reduce((s, i) => s + i.qty * (i.cost_price ?? 0), 0),
    0,
  );

  return {
    shop,
    rows: [...map.values()],
    total_sales: txs.filter((t) => t.type === "sale").length,
    total_swaps: txs.filter((t) => t.type === "swap").length,
    total_repairs: txs.filter((t) => t.type === "repair").length,
    revenue,
    cogs,
    profit: revenue - cogs,
    low_stock: lowStock,
  };
}

export async function getAdjustments(shopId?: string, limit = 50): Promise<StockAdjustment[]> {
  const supabase = await createClient();
  let q = supabase.from("stock_adjustments").select("*").order("date", { ascending: false }).limit(limit);
  if (shopId) q = q.eq("shop_id", shopId);
  const { data, error } = await q;
  if (error) throw new Error(error.message);
  return data ?? [];
}

export async function getSwappedPhones(opts: {
  shopId?: string;
  transactionId?: string;
  status?: "in_stock" | "sold" | "returned";
  limit?: number;
} = {}): Promise<SwappedPhone[]> {
  const supabase = await createClient();
  let q = supabase
    .from("swapped_phones")
    .select("*")
    .order("created_at", { ascending: false })
    .limit(opts.limit ?? 200);
  if (opts.shopId) q = q.eq("shop_id", opts.shopId);
  if (opts.transactionId) q = q.eq("transaction_id", opts.transactionId);
  if (opts.status) q = q.eq("status", opts.status);
  const { data, error } = await q;
  if (error) throw new Error(error.message);
  return data ?? [];
}

export type StockRequestWithDetails = StockRequest & {
  shop_name: string | null;
  staff_name: string | null;
  model_name_display: string | null;
};

export async function getStockRequests(opts: {
  shopId?: string;
  status?: "pending" | "approved" | "rejected";
  limit?: number;
} = {}): Promise<StockRequestWithDetails[]> {
  const supabase = await createClient();
  let q = supabase
    .from("stock_requests")
    .select("*")
    .order("created_at", { ascending: false })
    .limit(opts.limit ?? 100);
  if (opts.shopId) q = q.eq("shop_id", opts.shopId);
  if (opts.status) q = q.eq("status", opts.status);
  const { data, error } = await q;
  if (error) throw new Error(error.message);
  const requests = data ?? [];

  const shopIds = new Set(requests.map((r) => r.shop_id));
  const staffIds = new Set(requests.map((r) => r.staff_id));
  const modelIds = new Set(
    requests.map((r) => r.phone_model_id).filter((x): x is string => !!x),
  );

  const [shopsRes, staffRes, modelsRes] = await Promise.all([
    shopIds.size
      ? supabase.from("shops").select("id, name").in("id", [...shopIds])
      : Promise.resolve({ data: [] as Pick<Shop, "id" | "name">[], error: null }),
    staffIds.size
      ? supabase.from("users").select("id, name").in("id", [...staffIds])
      : Promise.resolve({ data: [] as Pick<UserProfile, "id" | "name">[], error: null }),
    modelIds.size
      ? supabase.from("phone_models").select("id, model_name").in("id", [...modelIds])
      : Promise.resolve({ data: [] as Pick<PhoneModel, "id" | "model_name">[], error: null }),
  ]);

  const shopName = new Map((shopsRes.data ?? []).map((s) => [s.id, s.name]));
  const staffName = new Map((staffRes.data ?? []).map((s) => [s.id, s.name]));
  const modelName = new Map((modelsRes.data ?? []).map((m) => [m.id, m.model_name]));

  return requests.map((r) => ({
    ...r,
    shop_name: shopName.get(r.shop_id) ?? null,
    staff_name: staffName.get(r.staff_id) ?? null,
    model_name_display:
      r.model_name ?? modelName.get(r.phone_model_id ?? "") ?? null,
  }));
}

// ---------------------------------------------------------------------------
// Unified dashboard (owner + attendant)
// ---------------------------------------------------------------------------

export type DashboardTotals = {
  revenue: number;
  profit: number;
  sales: number;
  swaps: number;
  repairs: number;
  units_out: number;
  low_stock: number;
};

export type DailyPoint = {
  date: string; // YYYY-MM-DD
  revenue: number;
  profit: number;
  units: number;
  sales: number;
};

export type DashboardPeriod = "today" | "7d" | "30d";

export type DashboardData = {
  role: "owner" | "attendant" | null;
  scope: "all" | "shop";
  period: DashboardPeriod;
  shop: Shop | null;
  shops: Shop[];
  summaries: ShopDailySummary[];
  series: DailyPoint[];
  recent: TransactionWithDetails[];
  pending: StockRequestWithDetails[];
  totals: DashboardTotals;
};

function aggregateTotals(summaries: ShopDailySummary[]): DashboardTotals {
  return {
    revenue: summaries.reduce((a, s) => a + s.revenue, 0),
    profit: summaries.reduce((a, s) => a + s.profit, 0),
    sales: summaries.reduce((a, s) => a + s.total_sales, 0),
    swaps: summaries.reduce((a, s) => a + s.total_swaps, 0),
    repairs: summaries.reduce((a, s) => a + s.total_repairs, 0),
    units_out: summaries.reduce(
      (a, s) => a + s.rows.reduce((x, r) => x + r.sold + r.swapped_out, 0),
      0,
    ),
    low_stock: summaries.reduce((a, s) => a + s.low_stock.length, 0),
  };
}

function periodRange(period: DashboardPeriod): { from: string; to: string } {
  const to = todayISO();
  if (period === "7d") return { from: addDays(to, -6), to };
  if (period === "30d") return { from: addDays(to, -29), to };
  return { from: to, to };
}

function buildSeries(
  txs: TransactionWithDetails[],
  from: string,
  to: string,
): DailyPoint[] {
  const days = new Map<string, DailyPoint>();
  let cur = from;
  for (let i = 0; i < 400; i++) {
    days.set(cur, { date: cur, revenue: 0, profit: 0, units: 0, sales: 0 });
    if (cur === to) break;
    cur = addDays(cur, 1);
  }
  for (const t of txs) {
    const day = (t.date ?? "").slice(0, 10);
    const p = days.get(day);
    if (!p) continue;
    const amount = t.amount ?? 0;
    const outItems = t.items.filter((i) => i.direction === "out");
    const cogs = outItems.reduce((s, i) => s + i.qty * (i.cost_price ?? 0), 0);
    p.revenue += amount;
    p.profit += amount - cogs;
    p.units += outItems.reduce((s, i) => s + i.qty, 0);
    if (t.type === "sale") p.sales += 1;
  }
  return [...days.values()];
}

export async function getDashboardData(
  period: DashboardPeriod = "today",
  shopFilter?: string,
): Promise<DashboardData> {
  const session = await requireSession();
  const role = session.profile?.role ?? null;
  const { from, to } = periodRange(period);

  if (role === "owner") {
    const shops = await getCachedShops();
    const shopId =
      shopFilter && shops.some((s) => s.id === shopFilter) ? shopFilter : undefined;

    const [summaries, recent, pending, rangeTxs] = await Promise.all([
      Promise.all(
        shops
          .filter((s) => !shopId || s.id === shopId)
          .map((s) => getCachedShopSummary(s.id, from, to, s)),
      ),
      getCachedTransactions(shopId ? { shopId, limit: 10 } : { limit: 10 }),
      getCachedStockRequests(
        shopId ? { shopId, status: "pending" } : { status: "pending" },
      ),
      getCachedTransactions(shopId ? { shopId, from, to } : { from, to }),
    ]);
    return {
      role,
      scope: shopId ? "shop" : "all",
      period,
      shop: shopId ? shops.find((s) => s.id === shopId) ?? null : null,
      shops,
      summaries,
      series: buildSeries(rangeTxs, from, to),
      recent,
      pending,
      totals: aggregateTotals(summaries),
    };
  }

  // Attendant (or a profile with no shop yet).
  const shopId = session.profile?.shop_id ?? undefined;
  let summaries: ShopDailySummary[] = [];
  let shop: Shop | null = null;
  let recent: TransactionWithDetails[] = [];
  let pending: StockRequestWithDetails[] = [];
  let rangeTxs: TransactionWithDetails[] = [];
  if (shopId) {
    const [summary, r, p, rt] = await Promise.all([
      getShopSummary(shopId, from, to),
      getTransactions({ shopId, limit: 10 }),
      getStockRequests({ shopId, status: "pending" }),
      getTransactions({ shopId, from, to }),
    ]);
    summaries = [summary];
    shop = summary.shop;
    recent = r;
    pending = p;
    rangeTxs = rt;
  }
  return {
    role,
    scope: "shop",
    period,
    shop,
    shops: [],
    summaries,
    series: buildSeries(rangeTxs, from, to),
    recent,
    pending,
    totals: aggregateTotals(summaries),
  };
}

// ---------------------------------------------------------------------------
// Logs (owner)
// ---------------------------------------------------------------------------

export type LoginLog = Database["public"]["Tables"]["login_logs"]["Row"];

export async function getLoginLogs(limit = 100): Promise<LoginLog[]> {
  // Owner-gated by the calling page; uses the service-role client so the
  // result can be cached.
  return getCachedLoginLogs(limit);
}

export type StockLogEntry = Database["public"]["Tables"]["stock_logs"]["Row"] & {
  staff_name: string | null;
  shop_name: string | null;
};

export async function getStockLogs(limit = 200): Promise<StockLogEntry[]> {
  // Owner-gated by the calling page; uses the service-role client so the
  // result can be cached.
  return getCachedStockLogs(limit);
}
