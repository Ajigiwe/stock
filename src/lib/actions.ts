"use server";

import { createHash } from "node:crypto";
import { updateTag } from "next/cache";
import { redirect } from "next/navigation";
import { headers } from "next/headers";
import { createClient } from "@/lib/supabase/server";
import { requireSession, DATA_CACHE_TAGS } from "@/lib/data";
import { getAdminClient } from "@/lib/admin";
import type { Database, PaymentMethod, Json } from "@/lib/database.types";

export type ActionResult = {
  ok: boolean;
  error?: string;
};

function invalidateAllData() {
  for (const t of DATA_CACHE_TAGS) {
    try {
      updateTag(t);
    } catch {
      // updateTag can only run inside a Server Action context; ignore otherwise.
    }
  }
}

// ---------------------------------------------------------------------------
// Input validation
//
// Server Actions receive whatever JSON the client sends, regardless of the
// declared TypeScript types — the types are erased at runtime. Everything that
// reaches the database goes through these first, so a hand-crafted request
// produces a clean error rather than a 500 with a raw Postgres message, or a
// NaN in a NOT NULL column.
// ---------------------------------------------------------------------------

// numeric(12,2) tops out at 10 digits before the decimal point.
const MAX_MONEY = 9_999_999_999.99;
const MAX_QTY = 1_000_000;

type Parsed<T> = { ok: true; value: T } | { ok: false; error: string };

// Optional money field: blank/absent -> null.
function parseMoney(raw: unknown, label: string): Parsed<number | null> {
  if (raw == null || raw === "") return { ok: true, value: null };
  const n = Number(raw);
  if (!Number.isFinite(n)) return { ok: false, error: `${label} must be a number.` };
  if (n < 0) return { ok: false, error: `${label} can't be negative.` };
  if (n > MAX_MONEY) return { ok: false, error: `${label} is too large.` };
  return { ok: true, value: Math.round(n * 100) / 100 };
}

// Required count field with a default when blank.
function parseCount(
  raw: unknown,
  label: string,
  fallback: number,
): Parsed<number> {
  if (raw == null || raw === "") return { ok: true, value: fallback };
  const n = Number(raw);
  if (!Number.isFinite(n) || !Number.isInteger(n)) {
    return { ok: false, error: `${label} must be a whole number.` };
  }
  if (n < 0) return { ok: false, error: `${label} can't be negative.` };
  if (n > MAX_QTY) return { ok: false, error: `${label} is too large.` };
  return { ok: true, value: n };
}

function parseQty(raw: unknown): Parsed<number> {
  const n = Number(raw);
  if (!Number.isFinite(n) || !Number.isInteger(n) || n <= 0) {
    return { ok: false, error: "Quantity must be a whole number above 0." };
  }
  if (n > MAX_QTY) return { ok: false, error: "Quantity is too large." };
  return { ok: true, value: n };
}

const UUID_RE =
  /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

function isUuid(v: unknown): v is string {
  return typeof v === "string" && UUID_RE.test(v);
}

// Deterministic fallback idempotency key: a UUIDv5-style hash of the whole
// submission. If the client omits its key (old form, API caller), retries of
// the same submission still dedupe in the DB instead of deducting stock twice.
function derivedIdempotencyKey(input: RecordTransactionInput): string {
  const canonical = JSON.stringify([
    input.shopId,
    input.type,
    input.paymentMethod,
    input.amount,
    input.date,
    input.customerName.trim().toLowerCase(),
    input.customerPhone.trim(),
    [...(input.outItems ?? [])]
      .map((i) => [i.modelId, i.qty])
      .sort((a, b) => String(a[0]).localeCompare(String(b[0]))),
    [...(input.swapIn ?? [])].map((s) => s.name.trim().toLowerCase()).sort(),
  ]);
  const h = createHash("sha256").update(canonical).digest("hex");
  // Format a 32-hex slice as a UUID so it passes the DB uuid column + isUuid().
  const s = h.slice(0, 32);
  return `${s.slice(0, 8)}-${s.slice(8, 12)}-${s.slice(12, 16)}-${s.slice(16, 20)}-${s.slice(20)}`;
}

// A YYYY-MM-DD transaction date, anchored at midday UTC so the calendar day is
// stable across timezones. Bounded to a sane window: an unparseable string used
// to throw a RangeError out of the action, and nothing stopped a transaction
// being dated to the year 3000.
function parseTxDate(raw: unknown): Parsed<string> {
  if (raw == null || raw === "") return { ok: true, value: new Date().toISOString() };
  if (typeof raw !== "string" || !/^\d{4}-\d{2}-\d{2}$/.test(raw)) {
    return { ok: false, error: "Enter a valid date." };
  }
  const ms = Date.parse(`${raw}T12:00:00Z`);
  if (Number.isNaN(ms)) return { ok: false, error: "Enter a valid date." };

  const now = Date.now();
  const tenYearsAgo = now - 10 * 365 * 24 * 60 * 60 * 1000;
  const tomorrow = now + 24 * 60 * 60 * 1000;
  if (ms < tenYearsAgo) return { ok: false, error: "That date is too far in the past." };
  if (ms > tomorrow) return { ok: false, error: "The date can't be in the future." };

  return { ok: true, value: new Date(ms).toISOString() };
}

function trimmed(raw: unknown): string {
  return typeof raw === "string" ? raw.trim() : "";
}

export type TxOutItem = { modelId: string; qty: number };
export type TxInItem =
  | { mode: "existing"; modelId: string; qty: number }
  | {
      mode: "new";
      name: string;
      costPrice?: string;
      salePrice?: string;
      qty: number;
    };

// A swap trade-in: the customer's old iPhone, picked from the iPhone list
// (NOT added to sellable stock). No valuation, no extra details — just the model.
export type SwapInItem = {
  name: string;
};

export type RecordTransactionInput = {
  shopId: string;
  customerName: string;
  customerPhone: string;
  type: "sale" | "swap" | "repair";
  paymentMethod: PaymentMethod;
  amount: string;
  date: string; // YYYY-MM-DD
  outItems: TxOutItem[];
  inItems?: TxInItem[];
  swapIn?: SwapInItem[];
  // Generated by the form, not here: the DB dedupes on it, so it must stay
  // stable across retries of the same submission.
  idempotencyKey?: string;
};

const TX_TYPES = ["sale", "swap", "repair"] as const;
const PAYMENT_METHODS = [
  "cash",
  "mobile_money",
  "card",
  "bank_transfer",
  "other",
] as const;

// ---------------------------------------------------------------------------
// Auth
// ---------------------------------------------------------------------------

// Coarse device label from a user-agent string, for the login log.
function parseDevice(ua: string | null): string | null {
  if (!ua) return null;
  const l = ua.toLowerCase();
  if (l.includes("iphone")) return "iPhone";
  if (l.includes("ipad")) return "iPad";
  if (l.includes("android")) return "Android";
  if (l.includes("windows")) return "Windows";
  if (l.includes("mac os")) return "Mac";
  if (l.includes("linux")) return "Linux";
  return null;
}

// Only same-origin, non-protocol-relative paths. `next.startsWith("/")` alone
// accepts "//evil.com", which browsers treat as protocol-relative and follow
// off-site.
function safeNextPath(raw: unknown): string {
  const next = typeof raw === "string" ? raw : "";
  if (!next.startsWith("/")) return "/";
  if (next.startsWith("//") || next.startsWith("/\\")) return "/";
  return next;
}

// ---------------------------------------------------------------------------
// Login rate limiting
//
// In-memory sliding window, keyed by IP + email. This is a single-node Next
// server, so process memory is the right scope; if the app is ever scaled to
// multiple instances this must move to Redis or the database.
// ---------------------------------------------------------------------------

const LOGIN_MAX_ATTEMPTS = 8;
const LOGIN_WINDOW_MS = 10 * 60 * 1000; // 10 minutes

const loginAttempts = new Map<string, number[]>();

function loginBucketKey(ip: string | null, email: string): string {
  return `${ip ?? "noip"}|${email.toLowerCase()}`;
}

function loginRateLimited(key: string): boolean {
  const now = Date.now();
  const list = (loginAttempts.get(key) ?? []).filter(
    (t) => now - t < LOGIN_WINDOW_MS,
  );
  if (list.length >= LOGIN_MAX_ATTEMPTS) return true;
  list.push(now);
  loginAttempts.set(key, list);
  // Opportunistic cleanup so the map can't grow without bound.
  if (loginAttempts.size > 1000) {
    for (const [k, v] of loginAttempts) {
      if (v.every((t) => now - t >= LOGIN_WINDOW_MS)) loginAttempts.delete(k);
    }
  }
  return false;
}

function clearLoginAttempts(key: string) {
  loginAttempts.delete(key);
}

export async function login(
  _prev: ActionResult,
  formData: FormData,
): Promise<ActionResult> {
  const email = String(formData.get("email") ?? "").trim();
  const password = String(formData.get("password") ?? "");

  if (!email || !password) {
    return { ok: false, error: "Enter your email and password." };
  }

  const h0 = await headers();
  const clientIp =
    (h0.get("x-forwarded-for") ?? "").split(",")[0]?.trim() || null;
  const bucketKey = loginBucketKey(clientIp, email);
  if (loginRateLimited(bucketKey)) {
    return {
      ok: false,
      error:
        "Too many sign-in attempts. Wait 10 minutes and try again.",
    };
  }

  const supabase = await createClient();

  // Retry once on transient network failures ("fetch failed").
  let userId: string | null = null;
  for (let attempt = 1; attempt <= 2; attempt++) {
    try {
      const { data, error } = await supabase.auth.signInWithPassword({
        email,
        password,
      });
      if (!error) {
        userId = data.user?.id ?? null;
        clearLoginAttempts(bucketKey);
        break;
      }
      if (attempt === 2 || !/fetch failed|network|econn/i.test(error.message)) {
        return { ok: false, error: error.message };
      }
    } catch {
      if (attempt === 2) {
        return { ok: false, error: "Could not reach the server. Check your connection and try again." };
      }
    }
    await new Promise((r) => setTimeout(r, 1000));
  }

  // Record who signed in, from where, and when. Never blocks the login.
  if (userId) {
    try {
      const h = await headers();
      const ip = (h.get("x-forwarded-for") ?? "").split(",")[0]?.trim() || null;
      const ua = h.get("user-agent") ?? null;
      const admin = getAdminClient();
      const { data: profile } = await admin
        .from("users")
        .select("name")
        .eq("id", userId)
        .maybeSingle();
      await admin.from("login_logs").insert({
        user_id: userId,
        email,
        name: profile?.name ?? null,
        ip,
        user_agent: ua,
        device: parseDevice(ua),
      });
      // The Logs page may be open for the owner — keep its cache fresh.
      updateTag("logs");
    } catch {
      // ignore — logging must never block login
    }
  }

  redirect(safeNextPath(formData.get("next")));
}

export async function logout() {
  const supabase = await createClient();
  await supabase.auth.signOut();
  redirect("/login");
}

export async function changePassword(
  _prev: ActionResult,
  formData: FormData,
): Promise<ActionResult> {
  const currentPassword = String(formData.get("currentPassword") ?? "");
  const password = String(formData.get("password") ?? "");
  const confirmPassword = String(formData.get("confirm") ?? "");

  if (!currentPassword) {
    return { ok: false, error: "Enter your current password." };
  }
  if (password.length < 8) {
    return { ok: false, error: "New password must be at least 8 characters." };
  }
  if (password !== confirmPassword) {
    return { ok: false, error: "Passwords do not match." };
  }
  if (password === currentPassword) {
    return { ok: false, error: "The new password must be different." };
  }

  const supabase = await createClient();

  // Re-authenticate first. Without this, any live session — including one on a
  // shared shop device, or a stolen cookie — could silently take over the
  // account.
  const { data: me } = await supabase.auth.getUser();
  const email = me.user?.email;
  if (!email) return { ok: false, error: "You are not signed in." };

  const { error: reauthError } = await supabase.auth.signInWithPassword({
    email,
    password: currentPassword,
  });
  if (reauthError) {
    return { ok: false, error: "Your current password is incorrect." };
  }

  const { error } = await supabase.auth.updateUser({ password });
  if (error) return { ok: false, error: error.message };

  return { ok: true };
}

// Constant-time string comparison, so a wrong secret leaks no timing signal
// about how many characters matched. /setup is publicly reachable.
function secretsMatch(a: string, b: string): boolean {
  if (a.length !== b.length) return false;
  let diff = 0;
  for (let i = 0; i < a.length; i++) diff |= a.charCodeAt(i) ^ b.charCodeAt(i);
  return diff === 0;
}

const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

// Owner account is created by the operator through /setup (guarded by
// OWNER_SETUP_SECRET). Anyone with the secret can bootstrap the owner, and only
// once - the RPC-less guard below refuses if an owner already exists.
export async function setupOwner(
  _prev: ActionResult,
  formData: FormData,
): Promise<ActionResult> {
  const secret = process.env.OWNER_SETUP_SECRET;
  if (!secret) {
    return {
      ok: false,
      error: "OWNER_SETUP_SECRET is not configured on the server.",
    };
  }

  const name = String(formData.get("name") ?? "").trim();
  const email = String(formData.get("email") ?? "").trim();
  const password = String(formData.get("password") ?? "");
  const providedSecret = String(formData.get("secret") ?? "");

  if (!secretsMatch(providedSecret, secret)) {
    return { ok: false, error: "Invalid setup secret." };
  }
  if (!name || !EMAIL_RE.test(email) || password.length < 8) {
    return {
      ok: false,
      error: "Name and a valid email are required; password must be at least 8 characters.",
    };
  }

  try {
    const admin = getAdminClient();

    const { data: existing, error: existingError } = await admin
      .from("users")
      .select("id")
      .eq("role", "owner")
      .limit(1);
    if (existingError) return { ok: false, error: existingError.message };
    if (existing && existing.length > 0) {
      return { ok: false, error: "An owner account already exists." };
    }

    const { data, error } = await admin.auth.admin.createUser({
      email,
      password,
      email_confirm: true,
      user_metadata: { name },
    });
    if (error) return { ok: false, error: error.message };

    const userId = data.user.id;
    const { error: profileError } = await admin
      .from("users")
      .update({ name, role: "owner" })
      .eq("id", userId);
    if (profileError) return { ok: false, error: profileError.message };
  } catch (e) {
    return { ok: false, error: (e as Error).message };
  }

  return { ok: true };
}

// ---------------------------------------------------------------------------
// Transactions
// ---------------------------------------------------------------------------

export async function recordTransaction(
  input: RecordTransactionInput,
): Promise<ActionResult & { id?: string; warning?: string }> {
  const session = await requireSession();
  const supabase = await createClient();

  if (!TX_TYPES.includes(input.type)) {
    return { ok: false, error: "Choose a valid transaction type." };
  }
  if (!PAYMENT_METHODS.includes(input.paymentMethod)) {
    return { ok: false, error: "Choose a valid payment method." };
  }

  const amountParsed = parseMoney(input.amount, "Amount");
  if (!amountParsed.ok) return { ok: false, error: amountParsed.error };
  const amount = amountParsed.value ?? 0;

  const customerName = trimmed(input.customerName);
  const customerPhone = trimmed(input.customerPhone);
  if (!customerName || !customerPhone) {
    return { ok: false, error: "Customer name and phone are required." };
  }

  const dateParsed = parseTxDate(input.date);
  if (!dateParsed.ok) return { ok: false, error: dateParsed.error };

  if (input.idempotencyKey != null && !isUuid(input.idempotencyKey)) {
    return { ok: false, error: "Invalid request. Please try again." };
  }
  const idempotencyKey = input.idempotencyKey ?? derivedIdempotencyKey(input);

  // Merge duplicate lines for the same model. Sending one model as two separate
  // lines used to slip past the sale-price floor below, which only looked at the
  // first matching line's qty.
  const qtyByModel = new Map<string, number>();
  for (const it of Array.isArray(input.outItems) ? input.outItems : []) {
    if (!isUuid(it?.modelId)) continue;
    const qty = parseQty(it.qty);
    if (!qty.ok) return { ok: false, error: qty.error };
    qtyByModel.set(it.modelId, (qtyByModel.get(it.modelId) ?? 0) + qty.value);
  }
  const outItems = [...qtyByModel].map(([phone_model_id, qty]) => ({
    phone_model_id,
    qty,
  }));
  if (input.type !== "repair" && outItems.length === 0) {
    return { ok: false, error: "Add at least one phone going out." };
  }

  const inItems: Record<string, unknown>[] = [];
  for (const it of input.inItems ?? []) {
    const qty = parseQty(it?.qty);
    if (!qty.ok) continue;
    if (it.mode === "existing") {
      if (!isUuid(it.modelId)) continue;
      inItems.push({ phone_model_id: it.modelId, qty: qty.value });
    } else {
      const name = trimmed(it.name);
      if (!name) continue;
      const cost = parseMoney(it.costPrice, "Cost price");
      if (!cost.ok) return { ok: false, error: cost.error };
      const sale = parseMoney(it.salePrice, "Sale price");
      if (!sale.ok) return { ok: false, error: sale.error };
      inItems.push({
        model_name: name,
        condition: "used",
        cost_price: cost.value,
        sale_price: sale.value,
        qty: qty.value,
      });
    }
  }

  // Swap trade-ins: the customer's old phone(s), logged separately.
  const swapIn = (input.swapIn ?? []).filter((s) => trimmed(s?.name));
  if (input.type === "swap" && swapIn.length === 0) {
    return { ok: false, error: "Add the old phone the customer is trading in." };
  }

  // Attendants: force their own shop regardless of what the form sends.
  const shopId =
    session.profile?.role === "owner" ? input.shopId : session.profile?.shop_id;

  if (!isUuid(shopId)) {
    return { ok: false, error: "No shop selected." };
  }

  // Sales: the amount can't be less than the combined sale price of the phones
  // going out — a phone that costs 11K can't be sold for less than 11K.
  if (input.type === "sale" && outItems.length > 0) {
    const { data: models, error: priceError } = await supabase
      .from("phone_models")
      .select("id, sale_price")
      .eq("shop_id", shopId)
      .in("id", [...qtyByModel.keys()]);
    if (priceError) return { ok: false, error: priceError.message };
    const required = (models ?? []).reduce(
      (sum, m) => sum + (m.sale_price ?? 0) * (qtyByModel.get(m.id) ?? 0),
      0,
    );
    if (amount < required) {
      return {
        ok: false,
        error: `Amount can't be less than the phone price (${required.toLocaleString()} GHS).`,
      };
    }
  }

  const { data, error } = await supabase.rpc("record_transaction", {
    p_shop_id: shopId,
    p_customer_name: customerName,
    p_customer_phone: customerPhone,
    p_type: input.type,
    p_payment_method: input.paymentMethod,
    p_amount: amount,
    p_date: dateParsed.value,
    p_out_items: outItems,
    p_in_items: inItems,
    // Client-generated so a retry of the same submission dedupes in the DB;
    // falls back to a deterministic hash of the submission if absent.
    p_idempotency_key: idempotencyKey,
    p_swap_in: (input.type === "swap" ? swapIn : []).map((s) => ({
      model_name: trimmed(s.name),
      customer_name: customerName,
      customer_phone: customerPhone,
    })),
  });

  if (error) return { ok: false, error: error.message };

  const newId = typeof data === "string" ? data : undefined;

  invalidateAllData();
  return { ok: true, id: newId };
}

export async function deleteTransaction(id: string): Promise<ActionResult> {
  const session = await requireSession();
  // Owner-only. The RPC enforces this too, but relying solely on that left the
  // one mutating action with no visible guard at its call site.
  if (session.profile?.role !== "owner") {
    return { ok: false, error: "Only the owner can delete transactions." };
  }
  if (!isUuid(id)) return { ok: false, error: "Invalid transaction." };

  const supabase = await createClient();

  // 1. Capture a full snapshot before the cascade delete fires. A failed read
  //    must not silently produce an empty audit record.
  const { data: txRow, error: txErr } = await supabase
    .from("transactions")
    .select("*")
    .eq("id", id)
    .maybeSingle();
  if (txErr) return { ok: false, error: txErr.message };
  if (!txRow) return { ok: false, error: "Transaction not found." };

  const { data: items, error: itemsErr } = await supabase
    .from("transaction_items")
    .select("*")
    .eq("transaction_id", id);
  if (itemsErr) return { ok: false, error: itemsErr.message };
  const snapshot = { transaction: txRow, items: items ?? [] };

  // 2. Delete via RPC (cascades transaction_items).
  const { error } = await supabase.rpc("delete_transaction", {
    p_transaction_id: id,
  });
  if (error) return { ok: false, error: error.message };

  // 3. Best-effort log the deleted transaction for audit.
  try {
    const admin = getAdminClient();
    await admin.from("stock_logs").insert({
      shop_id: txRow.shop_id,
      staff_id: session.id,
      action: "delete_transaction",
      model_name: null,
      phone_model_id: null,
      condition: null,
      details: { deleted_transaction: snapshot } as Json,
    });
  } catch (e) {
    console.error("stock_log insert (delete_transaction) failed:", e);
  }

  invalidateAllData();
  return { ok: true };
}

const SWAP_STATUSES = ["in_stock", "sold", "returned"] as const;

export async function updateSwappedPhoneStatus(
  id: string,
  status: "in_stock" | "sold" | "returned",
): Promise<ActionResult> {
  const session = await requireSession();
  if (session.profile?.role !== "owner") {
    return { ok: false, error: "Only the owner can update swapped phones." };
  }
  if (!isUuid(id)) return { ok: false, error: "Invalid trade-in." };
  if (!SWAP_STATUSES.includes(status)) {
    return { ok: false, error: "Invalid status." };
  }
  const supabase = await createClient();
  const { error } = await supabase
    .from("swapped_phones")
    .update({ status })
    .eq("id", id);
  if (error) return { ok: false, error: error.message };
  invalidateAllData();
  return { ok: true };
}

// ---------------------------------------------------------------------------
// Stock
// ---------------------------------------------------------------------------

// Who may edit stock directly: the owner, or a staff member the owner granted
// stock-editing privileges to. Everyone else goes through the approval flow.
async function canEditStock(
  session: Awaited<ReturnType<typeof requireSession>>,
): Promise<boolean> {
  return (
    session.profile?.role === "owner" ||
    session.profile?.can_edit_stock === true
  );
}

type StockLogInput = {
  shopId: string;
  staffId: string;
  action: string;
  phoneModelId?: string | null;
  modelName?: string | null;
  condition?: string | null;
  details?: Json;
};

// Appends a stock_logs row so the owner can see every stock edit. Best-effort:
// logging failure must never fail the stock change itself.
async function logStockEdit(input: StockLogInput): Promise<void> {
  try {
    const admin = getAdminClient();
    await admin.from("stock_logs").insert({
      shop_id: input.shopId,
      phone_model_id: input.phoneModelId ?? null,
      staff_id: input.staffId,
      action: input.action,
      model_name: input.modelName ?? null,
      condition: input.condition ?? null,
      details: input.details ?? null,
    });
  } catch (e) {
    console.error("stock_log insert failed:", e);
  }
}

export type CreateModelInput = {
  shopId: string;
  modelName: string;
  condition: "new" | "used";
  costPrice: string;
  salePrice: string;
  openingStock: string;
  lowStockThreshold: string;
};

const CONDITIONS = ["new", "used"] as const;

export async function createModel(input: CreateModelInput): Promise<ActionResult> {
  const session = await requireSession();
  const supabase = await createClient();

  const shopId =
    session.profile?.role === "owner" ? input.shopId : session.profile?.shop_id;
  if (!isUuid(shopId)) return { ok: false, error: "No shop selected." };

  if (!CONDITIONS.includes(input.condition)) {
    return { ok: false, error: "Choose a valid condition." };
  }

  const modelName = trimmed(input.modelName);
  if (!modelName) return { ok: false, error: "Model name is required." };
  if (modelName.length > 120) {
    return { ok: false, error: "Model name is too long." };
  }

  const opening = parseCount(input.openingStock, "Opening stock", 0);
  if (!opening.ok) return { ok: false, error: opening.error };
  const cost = parseMoney(input.costPrice, "Cost price");
  if (!cost.ok) return { ok: false, error: cost.error };
  const sale = parseMoney(input.salePrice, "Sale price");
  if (!sale.ok) return { ok: false, error: sale.error };
  const threshold = parseCount(input.lowStockThreshold, "Low-stock threshold", 5);
  if (!threshold.ok) return { ok: false, error: threshold.error };

  if (await canEditStock(session)) {
    // Owner / privileged staff add models immediately. `available` is set by the
    // model_stock_normalize trigger from opening_stock + bought_in.
    const { error } = await supabase.from("phone_models").insert({
      shop_id: shopId,
      model_name: modelName,
      condition: input.condition,
      cost_price: cost.value,
      sale_price: sale.value,
      opening_stock: opening.value,
      bought_in: 0,
      available: opening.value,
      low_stock_threshold: threshold.value,
    });
    if (error) return { ok: false, error: error.message };
    await logStockEdit({
      shopId,
      staffId: session.id,
      action: "create_model",
      modelName,
      condition: input.condition,
      details: { opening_stock: opening.value },
    });
  } else {
    // Attendant: submit for owner approval.
    const { data: dup, error: dupErr } = await supabase
      .from("phone_models")
      .select("id")
      .eq("shop_id", shopId)
      .eq("model_name", modelName)
      .eq("condition", input.condition)
      .maybeSingle();
    if (dupErr) return { ok: false, error: dupErr.message };
    if (dup) {
      return { ok: false, error: "A model with this name and condition already exists in the shop." };
    }
    const { error } = await supabase.from("stock_requests").insert({
      shop_id: shopId,
      staff_id: session.id,
      type: "create_model",
      model_name: modelName,
      condition: input.condition,
      cost_price: cost.value,
      sale_price: sale.value,
      low_stock_threshold: threshold.value,
      opening_stock: opening.value,
    });
    if (error) return { ok: false, error: error.message };
  }

  invalidateAllData();
  return { ok: true };
}

export type UpdateModelInput = {
  shopId: string;
  modelId: string;
  modelName: string;
  condition: "new" | "used";
  costPrice: string;
  salePrice: string;
  lowStockThreshold: string;
};

export async function updateModel(input: UpdateModelInput): Promise<ActionResult> {
  const session = await requireSession();
  const supabase = await createClient();

  const shopId =
    session.profile?.role === "owner" ? input.shopId : session.profile?.shop_id;
  if (!isUuid(shopId)) return { ok: false, error: "No shop selected." };
  if (!isUuid(input.modelId)) return { ok: false, error: "Invalid product." };

  if (!(await canEditStock(session))) {
    return {
      ok: false,
      error: "Only the owner or staff with stock privileges can edit products.",
    };
  }

  if (!CONDITIONS.includes(input.condition)) {
    return { ok: false, error: "Choose a valid condition." };
  }

  const modelName = trimmed(input.modelName);
  if (!modelName) return { ok: false, error: "Model name is required." };
  if (modelName.length > 120) {
    return { ok: false, error: "Model name is too long." };
  }

  const cost = parseMoney(input.costPrice, "Cost price");
  if (!cost.ok) return { ok: false, error: cost.error };
  const sale = parseMoney(input.salePrice, "Sale price");
  if (!sale.ok) return { ok: false, error: sale.error };
  const threshold = parseCount(input.lowStockThreshold, "Low-stock threshold", 5);
  if (!threshold.ok) return { ok: false, error: threshold.error };

  const { data: before, error: beforeErr } = await supabase
    .from("phone_models")
    .select("model_name, condition, cost_price, sale_price, low_stock_threshold")
    .eq("id", input.modelId)
    .eq("shop_id", shopId)
    .maybeSingle();
  if (beforeErr) return { ok: false, error: beforeErr.message };
  if (!before) return { ok: false, error: "Product not found in this shop." };

  // Stock fields (opening_stock / bought_in / available) are intentionally
  // NOT editable here — they move through transactions and stock adjustments
  // only, so the stock invariant stays intact. The column-level UPDATE grant in
  // schema.sql enforces that at the database level too.
  const { error } = await supabase
    .from("phone_models")
    .update({
      model_name: modelName,
      condition: input.condition,
      cost_price: cost.value,
      sale_price: sale.value,
      low_stock_threshold: threshold.value,
    })
    .eq("id", input.modelId)
    .eq("shop_id", shopId);

  if (error) return { ok: false, error: error.message };

  await logStockEdit({
    shopId,
    staffId: session.id,
    action: "update_model",
    phoneModelId: input.modelId,
    modelName,
    condition: input.condition,
    details: {
      before: {
        model_name: before?.model_name ?? null,
        condition: before?.condition ?? null,
        cost_price: before?.cost_price ?? null,
        sale_price: before?.sale_price ?? null,
        low_stock_threshold: before?.low_stock_threshold ?? null,
      },
      after: {
        model_name: modelName,
        condition: input.condition,
        cost_price: cost.value,
        sale_price: sale.value,
        low_stock_threshold: threshold.value,
      },
    },
  });

  invalidateAllData();
  return { ok: true };
}

export type AdjustStockInput = {
  shopId: string;
  phoneModelId: string;
  delta: string;
  reason: string;
};

export async function adjustStock(input: AdjustStockInput): Promise<ActionResult> {
  const session = await requireSession();
  const supabase = await createClient();

  const delta = Number(input.delta);
  if (!Number.isInteger(delta) || delta === 0) {
    return { ok: false, error: "Enter a non-zero whole quantity." };
  }
  if (Math.abs(delta) > MAX_QTY) {
    return { ok: false, error: "That quantity is too large." };
  }

  const shopId =
    session.profile?.role === "owner" ? input.shopId : session.profile?.shop_id;
  if (!isUuid(shopId)) return { ok: false, error: "No shop selected." };
  if (!isUuid(input.phoneModelId)) return { ok: false, error: "Invalid product." };

  const reason = trimmed(input.reason) || null;

  // The model must belong to this shop on both paths. The DB enforces it too
  // (adjust_stock checks it, and a trigger guards stock_requests), but failing
  // here gives a clearer message than a raised Postgres exception.
  const { data: model, error: modelErr } = await supabase
    .from("phone_models")
    .select("model_name, condition")
    .eq("id", input.phoneModelId)
    .eq("shop_id", shopId)
    .maybeSingle();
  if (modelErr) return { ok: false, error: modelErr.message };
  if (!model) return { ok: false, error: "Product not found in this shop." };

  if (await canEditStock(session)) {
    // Owner / privileged staff adjust stock immediately.
    const { error } = await supabase.rpc("adjust_stock", {
      p_shop_id: shopId,
      p_phone_model_id: input.phoneModelId,
      p_delta: delta,
      p_type: delta > 0 ? "restock" : "correction",
      p_reason: reason,
    });
    if (error) return { ok: false, error: error.message };

    await logStockEdit({
      shopId,
      staffId: session.id,
      action: "adjust_stock",
      phoneModelId: input.phoneModelId,
      modelName: model.model_name,
      condition: model.condition,
      details: {
        delta,
        type: delta > 0 ? "restock" : "correction",
        reason,
      },
    });
  } else {
    // Attendant: submit for owner approval.
    const { error } = await supabase.from("stock_requests").insert({
      shop_id: shopId,
      staff_id: session.id,
      type: "adjust_stock",
      phone_model_id: input.phoneModelId,
      delta,
      reason,
    });
    if (error) return { ok: false, error: error.message };
  }

  invalidateAllData();
  return { ok: true };
}

export type BulkAdjustStockInput = {
  shopId: string;
  items: { modelId: string; targetQty: number }[];
  reason?: string;
};

export async function bulkAdjustStock(
  input: BulkAdjustStockInput,
): Promise<ActionResult & { changes?: number }> {
  const session = await requireSession();
  const supabase = await createClient();

  const shopId =
    session.profile?.role === "owner" ? input.shopId : session.profile?.shop_id;
  if (!isUuid(shopId)) return { ok: false, error: "No shop selected." };

  // Last target wins for a repeated model, so the request is unambiguous.
  const targets = new Map<string, number>();
  for (const it of Array.isArray(input.items) ? input.items : []) {
    if (!isUuid(it?.modelId)) continue;
    const target = parseCount(it.targetQty, "Quantity", 0);
    if (!target.ok) return { ok: false, error: target.error };
    targets.set(it.modelId, target.value);
  }
  if (!targets.size) return { ok: false, error: "Nothing to change." };

  const reason = trimmed(input.reason) || null;

  const { data: models, error: mErr } = await supabase
    .from("phone_models")
    .select("id, available, model_name, condition")
    .in("id", [...targets.keys()])
    .eq("shop_id", shopId);
  if (mErr) return { ok: false, error: mErr.message };
  if (!models?.length) return { ok: false, error: "No matching products in this shop." };

  const known = new Map(models.map((m) => [m.id, m]));

  // Only send models whose target actually differs from what we last read. The
  // DB re-reads under a row lock and recomputes the delta itself, so a sale
  // landing in between still produces the right absolute quantity.
  const items: { phone_model_id: string; target_qty: number }[] = [];
  for (const [modelId, target] of targets) {
    const m = known.get(modelId);
    if (!m) continue;
    if (m.available === target) continue;
    items.push({ phone_model_id: modelId, target_qty: target });
  }
  if (!items.length) return { ok: true, changes: 0 };

  if (await canEditStock(session)) {
    // One RPC for the whole batch: every model is locked before any delta is
    // written, so the batch either commits entirely or not at all. The previous
    // per-model loop could fail halfway and leave earlier changes applied.
    const { data: changed, error } = await supabase.rpc("bulk_adjust_stock", {
      p_shop_id: shopId,
      p_items: items,
      p_reason: reason,
    });
    if (error) return { ok: false, error: error.message };

    for (const it of items) {
      const m = known.get(it.phone_model_id);
      await logStockEdit({
        shopId,
        staffId: session.id,
        action: "adjust_stock",
        phoneModelId: it.phone_model_id,
        modelName: m?.model_name ?? null,
        condition: m?.condition ?? null,
        details: {
          target_qty: it.target_qty,
          previous_available: m?.available ?? null,
          reason,
        },
      });
    }

    invalidateAllData();
    return { ok: true, changes: Number(changed ?? items.length) };
  }

  // Attendant: submit each change for owner approval.
  const rows: Database["public"]["Tables"]["stock_requests"]["Insert"][] = [];
  for (const it of items) {
    const m = known.get(it.phone_model_id);
    if (!m) continue;
    const delta = it.target_qty - m.available;
    if (delta === 0) continue;
    if (m.available + delta < 0) {
      return {
        ok: false,
        error: `Cannot reduce ${m.model_name} below 0 (only ${m.available} available).`,
      };
    }
    rows.push({
      shop_id: shopId,
      staff_id: session.id,
      type: "adjust_stock" as const,
      phone_model_id: it.phone_model_id,
      delta,
      reason,
    });
  }
  if (!rows.length) return { ok: true, changes: 0 };

  {
    const { error } = await supabase.from("stock_requests").insert(rows);
    if (error) return { ok: false, error: error.message };
  }

  invalidateAllData();
  return { ok: true, changes: rows.length };
}

export async function approveStockRequest(id: string): Promise<ActionResult> {
  const session = await requireSession();
  if (session.profile?.role !== "owner") {
    return { ok: false, error: "Only the owner can approve stock changes." };
  }
  if (!isUuid(id)) return { ok: false, error: "Invalid request." };
  const supabase = await createClient();
  const { error } = await supabase.rpc("approve_stock_request", {
    p_request_id: id,
  });
  if (error) return { ok: false, error: error.message };
  invalidateAllData();
  return { ok: true };
}

export async function rejectStockRequest(id: string): Promise<ActionResult> {
  const session = await requireSession();
  if (session.profile?.role !== "owner") {
    return { ok: false, error: "Only the owner can reject stock changes." };
  }
  if (!isUuid(id)) return { ok: false, error: "Invalid request." };
  const supabase = await createClient();
  const { error } = await supabase.rpc("reject_stock_request", {
    p_request_id: id,
  });
  if (error) return { ok: false, error: error.message };
  invalidateAllData();
  return { ok: true };
}

export type ApproveAllResult = ActionResult & {
  approved?: number;
  failed?: number;
};

export async function approveAllStockRequests(
  shopId?: string,
): Promise<ApproveAllResult> {
  const session = await requireSession();
  if (session.profile?.role !== "owner") {
    return { ok: false, error: "Only the owner can approve stock changes." };
  }
  if (shopId != null && !isUuid(shopId)) {
    return { ok: false, error: "Invalid shop." };
  }
  const supabase = await createClient();
  const { data, error } = await supabase.rpc("approve_all_stock_requests", {
    p_shop_id: shopId ?? null,
  });
  if (error) return { ok: false, error: error.message };
  const row = Array.isArray(data) ? data[0] : data;
  invalidateAllData();
  return {
    ok: true,
    approved: Number(row?.approved ?? 0),
    failed: Number(row?.failed ?? 0),
  };
}

// ---------------------------------------------------------------------------
// Admin (owner only)
// ---------------------------------------------------------------------------

export type CreateShopInput = { name: string; location: string; phone: string };

export async function createShop(input: CreateShopInput): Promise<ActionResult> {
  const session = await requireSession();
  if (session.profile?.role !== "owner") {
    return { ok: false, error: "Only the owner can add shops." };
  }
  const name = input.name.trim();
  if (!name) return { ok: false, error: "Shop name is required." };

  const supabase = await createClient();
  const { error } = await supabase.from("shops").insert({
    name,
    location: input.location?.trim() || null,
    phone: input.phone?.trim() || null,
  });
  if (error) return { ok: false, error: error.message };
  invalidateAllData();
  return { ok: true };
}

export async function deleteShop(id: string): Promise<ActionResult> {
  const session = await requireSession();
  if (session.profile?.role !== "owner") {
    return { ok: false, error: "Only the owner can remove shops." };
  }
  const supabase = await createClient();
  const { error } = await supabase.from("shops").delete().eq("id", id);
  if (error) return { ok: false, error: error.message };
  invalidateAllData();
  return { ok: true };
}

export type CreateStaffInput = {
  name: string;
  email: string;
  password: string;
  shopId: string;
};

export async function createStaff(input: CreateStaffInput): Promise<ActionResult> {
  const session = await requireSession();
  if (session.profile?.role !== "owner") {
    return { ok: false, error: "Only the owner can add staff." };
  }

  const name = input.name.trim();
  const email = input.email.trim();
  if (!name || !EMAIL_RE.test(email) || input.password.length < 8) {
    return { ok: false, error: "Name and a valid email required; password at least 8 characters." };
  }

  try {
    const admin = getAdminClient();
    const { data, error } = await admin.auth.admin.createUser({
      email,
      password: input.password,
      email_confirm: true,
      user_metadata: { name },
    });
    if (error) return { ok: false, error: error.message };

    const userId = data.user.id;
    const { error: profileError } = await admin.from("users").upsert({
      id: userId,
      name,
      role: "attendant",
      shop_id: input.shopId,
    });
    if (profileError) return { ok: false, error: profileError.message };
  } catch (e) {
    return { ok: false, error: (e as Error).message };
  }

  invalidateAllData();
  return { ok: true };
}

export async function removeStaff(id: string): Promise<ActionResult> {
  const session = await requireSession();
  if (session.profile?.role !== "owner") {
    return { ok: false, error: "Only the owner can remove staff." };
  }
  if (id === session.id) {
    return { ok: false, error: "You cannot remove yourself." };
  }
  try {
    const admin = getAdminClient();
    const { error } = await admin.auth.admin.deleteUser(id);
    if (error) return { ok: false, error: error.message };
  } catch (e) {
    return { ok: false, error: (e as Error).message };
  }
  invalidateAllData();
  return { ok: true };
}

export async function resetStaffPassword(
  id: string,
  password: string,
): Promise<ActionResult> {
  const session = await requireSession();
  if (session.profile?.role !== "owner") {
    return { ok: false, error: "Only the owner can reset staff passwords." };
  }
  if (id === session.id) {
    return { ok: false, error: "You cannot reset your own password here." };
  }
  if (password.length < 8) {
    return { ok: false, error: "Password must be at least 8 characters." };
  }
  try {
    const admin = getAdminClient();
    const { error } = await admin.auth.admin.updateUserById(id, { password });
    if (error) return { ok: false, error: error.message };
  } catch (e) {
    return { ok: false, error: (e as Error).message };
  }
  return { ok: true };
}

export async function setStaffStockPrivilege(
  id: string,
  canEditStock: boolean,
): Promise<ActionResult> {
  const session = await requireSession();
  if (session.profile?.role !== "owner") {
    return { ok: false, error: "Only the owner can change staff privileges." };
  }
  if (id === session.id) {
    return { ok: false, error: "You cannot change your own privileges." };
  }
  const supabase = await createClient();
  const { error } = await supabase
    .from("users")
    .update({ can_edit_stock: canEditStock })
    .eq("id", id);
  if (error) return { ok: false, error: error.message };
  invalidateAllData();
  return { ok: true };
}

// ---------------------------------------------------------------------------
// Backup & restore (owner only)
// ---------------------------------------------------------------------------

export type RestoreResult = ActionResult & {
  restored?: boolean;
  transactions?: number;
  stock_repaired?: number;
};

// The RPC re-validates everything, but checking here first gives a clear error
// instead of a Postgres exception, and — more importantly — keeps a mangled
// file away from a function whose job is to delete the entire database.
const RESTORE_KEYS = [
  "shops",
  "users",
  "phone_models",
  "transactions",
  "transaction_items",
  "stock_adjustments",
] as const;

export async function restoreBackup(raw: string): Promise<RestoreResult> {
  const session = await requireSession();
  if (session.profile?.role !== "owner") {
    return { ok: false, error: "Only the owner can restore backups." };
  }
  if (typeof raw !== "string" || raw.length > 20 * 1024 * 1024) {
    return { ok: false, error: "Backup file is missing or too large." };
  }

  let parsed: unknown;
  try {
    parsed = JSON.parse(raw);
  } catch {
    return { ok: false, error: "File is not valid JSON." };
  }
  if (parsed == null || typeof parsed !== "object" || Array.isArray(parsed)) {
    return { ok: false, error: "Not a Mr Jeff Stock backup file." };
  }

  const data = parsed as Record<string, unknown>;
  for (const key of RESTORE_KEYS) {
    if (!Array.isArray(data[key])) {
      return { ok: false, error: `Not a Mr Jeff Stock backup file (missing "${key}").` };
    }
  }
  const users = data.users as { role?: unknown }[];
  if (!users.some((u) => u && typeof u === "object" && u.role === "owner")) {
    return { ok: false, error: "Backup file contains no owner account." };
  }

  const supabase = await createClient();
  const { data: result, error } = await supabase.rpc("restore_backup", {
    p_data: parsed,
  });
  if (error) return { ok: false, error: error.message };

  invalidateAllData();
  return {
    ok: true,
    restored: result?.restored ?? true,
    transactions: result?.transactions ?? 0,
    stock_repaired: result?.stock_repaired ?? 0,
  };
}

// ---------------------------------------------------------------------------
// Bulk device import (owner only)
// ---------------------------------------------------------------------------

export type BulkModelRow = {
  model_name: string;
  condition: "new" | "used";
  cost_price?: string;
  sale_price?: string;
  opening_stock?: string;
  low_stock_threshold?: string;
};

export type BulkResult = ActionResult & {
  added?: number;
  skipped?: { name: string; reason: string }[];
};

export async function bulkCreateModels(
  shopId: string,
  rows: BulkModelRow[],
): Promise<BulkResult> {
  const session = await requireSession();
  if (session.profile?.role !== "owner") {
    return { ok: false, error: "Only the owner can bulk add devices." };
  }
  if (!shopId) return { ok: false, error: "Select a shop." };
  if (!isUuid(shopId)) return { ok: false, error: "Invalid shop." };
  if (!rows.length) return { ok: false, error: "No rows to import." };

  // One giant INSERT used to be built with no cap; a pasted file of 100k rows
  // would have been sent to Postgres in a single statement.
  const MAX_BULK_ROWS = 500;
  if (rows.length > MAX_BULK_ROWS) {
    return { ok: false, error: `Import at most ${MAX_BULK_ROWS} rows at a time.` };
  }

  const supabase = await createClient();

  // Skip models that already exist for this shop (same name + condition).
  const { data: existing, error: exErr } = await supabase
    .from("phone_models")
    .select("model_name, condition")
    .eq("shop_id", shopId);
  if (exErr) return { ok: false, error: exErr.message };

  const existingKeys = new Set(
    (existing ?? []).map((m) => `${m.model_name}|${m.condition}`),
  );

  const toInsert: Database["public"]["Tables"]["phone_models"]["Insert"][] = [];
  const skipped: { name: string; reason: string }[] = [];
  const seen = new Set<string>();

  for (const r of rows) {
    const name = (r.model_name ?? "").trim();
    const condition: "new" | "used" = r.condition === "used" ? "used" : "new";
    if (!name) {
      skipped.push({ name: "(empty)", reason: "Missing model name" });
      continue;
    }

    const key = `${name}|${condition}`;
    if (existingKeys.has(key)) {
      skipped.push({ name, reason: "Already exists in this shop" });
      continue;
    }
    if (seen.has(key)) {
      skipped.push({ name, reason: "Duplicate within the import file" });
      continue;
    }
    seen.add(key);

    const opening = parseCount(r.opening_stock ?? "0", "Opening stock", 0);
    if (!opening.ok) {
      skipped.push({ name, reason: opening.error });
      continue;
    }
    const cost = parseMoney(r.cost_price, "Cost price");
    if (!cost.ok) {
      skipped.push({ name, reason: cost.error });
      continue;
    }
    const sale = parseMoney(r.sale_price, "Sale price");
    if (!sale.ok) {
      skipped.push({ name, reason: sale.error });
      continue;
    }
    const threshold = parseCount(
      r.low_stock_threshold || "5",
      "Low-stock threshold",
      5,
    );
    if (!threshold.ok) {
      skipped.push({ name, reason: threshold.error });
      continue;
    }

    toInsert.push({
      shop_id: shopId,
      model_name: name,
      condition,
      cost_price: cost.value,
      sale_price: sale.value,
      opening_stock: opening.value,
      bought_in: 0,
      available: opening.value,
      low_stock_threshold: threshold.value,
    });
  }

  if (toInsert.length) {
    const { error } = await supabase.from("phone_models").insert(toInsert);
    if (error) return { ok: false, error: error.message };
  }

  invalidateAllData();
  return { ok: true, added: toInsert.length, skipped };
}
