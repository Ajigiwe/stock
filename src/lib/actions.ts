"use server";

import { revalidatePath } from "next/cache";
import { redirect } from "next/navigation";
import { createClient } from "@/lib/supabase/server";
import { requireSession } from "@/lib/data";
import { getAdminClient } from "@/lib/admin";
import type { Database, PaymentMethod } from "@/lib/database.types";

export type ActionResult = {
  ok: boolean;
  error?: string;
};

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
};

// ---------------------------------------------------------------------------
// Auth
// ---------------------------------------------------------------------------

export async function login(
  _prev: ActionResult,
  formData: FormData,
): Promise<ActionResult> {
  const email = String(formData.get("email") ?? "");
  const password = String(formData.get("password") ?? "");

  const supabase = await createClient();

  // Retry once on transient network failures ("fetch failed").
  for (let attempt = 1; attempt <= 2; attempt++) {
    try {
      const { error } = await supabase.auth.signInWithPassword({
        email,
        password,
      });
      if (!error) break;
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

  const next = String(formData.get("next") ?? "/");
  redirect(next.startsWith("/") ? next : "/");
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
  const password = String(formData.get("password") ?? "");
  const confirmPassword = String(formData.get("confirm") ?? "");

  if (password.length < 6) {
    return { ok: false, error: "Password must be at least 6 characters." };
  }
  if (password !== confirmPassword) {
    return { ok: false, error: "Passwords do not match." };
  }

  const supabase = await createClient();
  const { error } = await supabase.auth.updateUser({ password });
  if (error) return { ok: false, error: error.message };

  return { ok: true };
}

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

  if (providedSecret !== secret) {
    return { ok: false, error: "Invalid setup secret." };
  }
  if (!name || !email || password.length < 6) {
    return { ok: false, error: "Name required, and password must be at least 6 characters." };
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

  const amount = Number(input.amount);
  if (!Number.isFinite(amount) || amount < 0) {
    return { ok: false, error: "Enter a valid amount." };
  }

  const outItems: { phone_model_id: string; qty: number }[] = [];
  for (const it of input.outItems) {
    if (it.modelId && it.qty > 0) {
      outItems.push({ phone_model_id: it.modelId, qty: it.qty });
    }
  }
  if (input.type !== "repair" && outItems.length === 0) {
    return { ok: false, error: "Add at least one phone going out." };
  }

  const inItems: Record<string, unknown>[] = [];
  for (const it of input.inItems ?? []) {
    if (it.qty <= 0) continue;
    if (it.mode === "existing") {
      if (!it.modelId) continue;
      inItems.push({ phone_model_id: it.modelId, qty: it.qty });
    } else {
      if (!it.name?.trim()) continue;
      inItems.push({
        model_name: it.name.trim(),
        condition: "used",
        cost_price: it.costPrice ? Number(it.costPrice) : null,
        sale_price: it.salePrice ? Number(it.salePrice) : null,
        qty: it.qty,
      });
    }
  }

  // Swap trade-ins: the customer's old phone(s), logged separately.
  const swapIn = (input.swapIn ?? []).filter((s) => s.name.trim());
  if (input.type === "swap" && swapIn.length === 0) {
    return { ok: false, error: "Add the old phone the customer is trading in." };
  }

  // Sales: the amount can't be less than the combined sale price of the phones
  // going out — a phone that costs 11K can't be sold for less than 11K.
  if (input.type === "sale" && outItems.length > 0) {
    const modelIds = outItems.map((o) => o.phone_model_id);
    const { data: models, error: priceError } = await supabase
      .from("phone_models")
      .select("id, sale_price")
      .in("id", modelIds);
    if (priceError) return { ok: false, error: priceError.message };
    const required = (models ?? []).reduce(
      (sum, m) =>
        sum + (m.sale_price ?? 0) * (outItems.find((o) => o.phone_model_id === m.id)?.qty ?? 0),
      0,
    );
    if (amount < required) {
      return {
        ok: false,
        error: `Amount can't be less than the phone price (${required.toLocaleString()} GHS).`,
      };
    }
  }

  // Attendants: force their own shop regardless of what the form sends.
  const shopId =
    session.profile?.role === "owner" ? input.shopId : session.profile?.shop_id;

  if (!shopId) {
    return { ok: false, error: "No shop selected." };
  }

  const date = input.date
    ? new Date(input.date + "T12:00:00Z").toISOString()
    : undefined;

  const { data, error } = await supabase.rpc("record_transaction", {
    p_shop_id: shopId,
    p_customer_name: input.customerName?.trim() || null,
    p_customer_phone: input.customerPhone?.trim() || null,
    p_type: input.type,
    p_payment_method: input.paymentMethod,
    p_amount: amount,
    p_date: date ?? new Date().toISOString(),
    p_out_items: outItems,
    p_in_items: inItems,
  });

  if (error) return { ok: false, error: error.message };

  const newId = typeof data === "string" ? data : undefined;

  // Log the trade-in phone(s) into the swapped-phones list.
  if (swapIn.length && newId) {
    const rows = swapIn.map((s) => ({
      shop_id: shopId,
      transaction_id: newId,
      staff_id: session.id,
      model_name: s.name.trim(),
      customer_name: input.customerName?.trim() || null,
      customer_phone: input.customerPhone?.trim() || null,
    }));
    const { error: swErr } = await supabase.from("swapped_phones").insert(rows);
    if (swErr) {
      revalidatePath("/", "layout");
      return {
        ok: true,
        id: newId,
        warning: `Transaction saved, but the trade-in wasn't logged: ${swErr.message}`,
      };
    }
  }

  revalidatePath("/", "layout");
  return { ok: true, id: newId };
}

export async function deleteTransaction(id: string): Promise<ActionResult> {
  const supabase = await createClient();
  const { error } = await supabase.rpc("delete_transaction", {
    p_transaction_id: id,
  });
  if (error) return { ok: false, error: error.message };
  revalidatePath("/", "layout");
  return { ok: true };
}

export async function updateSwappedPhoneStatus(
  id: string,
  status: "in_stock" | "sold" | "returned",
): Promise<ActionResult> {
  const session = await requireSession();
  if (session.profile?.role !== "owner") {
    return { ok: false, error: "Only the owner can update swapped phones." };
  }
  const supabase = await createClient();
  const { error } = await supabase
    .from("swapped_phones")
    .update({ status })
    .eq("id", id);
  if (error) return { ok: false, error: error.message };
  revalidatePath("/", "layout");
  return { ok: true };
}

// ---------------------------------------------------------------------------
// Stock
// ---------------------------------------------------------------------------

export type CreateModelInput = {
  shopId: string;
  modelName: string;
  condition: "new" | "used";
  costPrice: string;
  salePrice: string;
  openingStock: string;
  lowStockThreshold: string;
};

export async function createModel(input: CreateModelInput): Promise<ActionResult> {
  const session = await requireSession();
  const supabase = await createClient();

  const shopId =
    session.profile?.role === "owner" ? input.shopId : session.profile?.shop_id;
  if (!shopId) return { ok: false, error: "No shop selected." };

  const modelName = input.modelName.trim();
  if (!modelName) return { ok: false, error: "Model name is required." };

  const openingStock = parseInt(input.openingStock || "0", 10);
  if (!Number.isFinite(openingStock) || openingStock < 0) {
    return { ok: false, error: "Opening stock must be 0 or more." };
  }

  if (session.profile?.role === "owner") {
    // Owner adds models immediately.
    const { error } = await supabase.from("phone_models").insert({
      shop_id: shopId,
      model_name: modelName,
      condition: input.condition,
      cost_price: input.costPrice ? Number(input.costPrice) : null,
      sale_price: input.salePrice ? Number(input.salePrice) : null,
      opening_stock: openingStock,
      bought_in: 0,
      available: openingStock,
      low_stock_threshold: parseInt(input.lowStockThreshold || "5", 10),
    });
    if (error) return { ok: false, error: error.message };
  } else {
    // Attendant: submit for owner approval.
    const { data: dup } = await supabase
      .from("phone_models")
      .select("id")
      .eq("shop_id", shopId)
      .eq("model_name", modelName)
      .eq("condition", input.condition)
      .maybeSingle();
    if (dup) {
      return { ok: false, error: "A model with this name and condition already exists in the shop." };
    }
    const { error } = await supabase.from("stock_requests").insert({
      shop_id: shopId,
      staff_id: session.id,
      type: "create_model",
      model_name: modelName,
      condition: input.condition,
      cost_price: input.costPrice ? Number(input.costPrice) : null,
      sale_price: input.salePrice ? Number(input.salePrice) : null,
      low_stock_threshold: parseInt(input.lowStockThreshold || "5", 10),
      opening_stock: openingStock,
    });
    if (error) return { ok: false, error: error.message };
  }

  revalidatePath("/", "layout");
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
  if (!shopId) return { ok: false, error: "No shop selected." };

  const modelName = input.modelName.trim();
  if (!modelName) return { ok: false, error: "Model name is required." };

  // Stock fields (opening_stock / bought_in / available) are intentionally
  // NOT editable here — they move through transactions and stock adjustments
  // only, so the stock invariant stays intact.
  const { error } = await supabase
    .from("phone_models")
    .update({
      model_name: modelName,
      condition: input.condition,
      cost_price: input.costPrice ? Number(input.costPrice) : null,
      sale_price: input.salePrice ? Number(input.salePrice) : null,
      low_stock_threshold: parseInt(input.lowStockThreshold || "5", 10),
    })
    .eq("id", input.modelId)
    .eq("shop_id", shopId);

  if (error) return { ok: false, error: error.message };
  revalidatePath("/", "layout");
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

  const delta = parseInt(input.delta, 10);
  if (!Number.isFinite(delta) || delta === 0) {
    return { ok: false, error: "Enter a non-zero quantity." };
  }

  const shopId =
    session.profile?.role === "owner" ? input.shopId : session.profile?.shop_id;
  if (!shopId) return { ok: false, error: "No shop selected." };

  if (session.profile?.role === "owner") {
    // Owner adjusts stock immediately.
    const { error } = await supabase.rpc("adjust_stock", {
      p_shop_id: shopId,
      p_phone_model_id: input.phoneModelId,
      p_delta: delta,
      p_type: delta > 0 ? "restock" : "correction",
      p_reason: input.reason?.trim() || null,
    });
    if (error) return { ok: false, error: error.message };
  } else {
    // Attendant: submit for owner approval.
    const { error } = await supabase.from("stock_requests").insert({
      shop_id: shopId,
      staff_id: session.id,
      type: "adjust_stock",
      phone_model_id: input.phoneModelId,
      delta,
      reason: input.reason?.trim() || null,
    });
    if (error) return { ok: false, error: error.message };
  }

  revalidatePath("/", "layout");
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
  if (!shopId) return { ok: false, error: "No shop selected." };

  const modelIds = [...new Set(input.items.map((i) => i.modelId).filter(Boolean))];
  if (!modelIds.length) return { ok: false, error: "Nothing to change." };

  const { data: models, error: mErr } = await supabase
    .from("phone_models")
    .select("id, available, model_name")
    .in("id", modelIds)
    .eq("shop_id", shopId);
  if (mErr) return { ok: false, error: mErr.message };
  const avail = new Map((models ?? []).map((m) => [m.id, m.available]));
  const modelName = new Map((models ?? []).map((m) => [m.id, m.model_name]));

  // Convert each target quantity into a delta against current available.
  const changes: { modelId: string; delta: number }[] = [];
  for (const it of input.items) {
    const current = avail.get(it.modelId);
    if (current == null) continue;
    const target = Math.floor(Number(it.targetQty));
    if (!Number.isFinite(target) || target < 0) continue;
    const delta = target - current;
    if (delta === 0) continue;
    if (delta < 0 && current + delta < 0) {
      return {
        ok: false,
        error: `Cannot reduce ${modelName.get(it.modelId) ?? "a model"} below 0 (only ${current} available).`,
      };
    }
    changes.push({ modelId: it.modelId, delta });
  }
  if (!changes.length) return { ok: true, changes: 0 };

  if (session.profile?.role === "owner") {
    for (const c of changes) {
      const { error } = await supabase.rpc("adjust_stock", {
        p_shop_id: shopId,
        p_phone_model_id: c.modelId,
        p_delta: c.delta,
        p_type: c.delta > 0 ? "restock" : "correction",
        p_reason: input.reason?.trim() || null,
      });
      if (error) return { ok: false, error: error.message };
    }
  } else {
    const rows = changes.map((c) => ({
      shop_id: shopId,
      staff_id: session.id,
      type: "adjust_stock" as const,
      phone_model_id: c.modelId,
      delta: c.delta,
      reason: input.reason?.trim() || null,
    }));
    const { error } = await supabase.from("stock_requests").insert(rows);
    if (error) return { ok: false, error: error.message };
  }

  revalidatePath("/", "layout");
  return { ok: true, changes: changes.length };
}

export async function approveStockRequest(id: string): Promise<ActionResult> {
  const session = await requireSession();
  if (session.profile?.role !== "owner") {
    return { ok: false, error: "Only the owner can approve stock changes." };
  }
  const supabase = await createClient();
  const { error } = await supabase.rpc("approve_stock_request", {
    p_request_id: id,
  });
  if (error) return { ok: false, error: error.message };
  revalidatePath("/", "layout");
  return { ok: true };
}

export async function rejectStockRequest(id: string): Promise<ActionResult> {
  const session = await requireSession();
  if (session.profile?.role !== "owner") {
    return { ok: false, error: "Only the owner can reject stock changes." };
  }
  const supabase = await createClient();
  const { error } = await supabase.rpc("reject_stock_request", {
    p_request_id: id,
  });
  if (error) return { ok: false, error: error.message };
  revalidatePath("/", "layout");
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
  const supabase = await createClient();
  const { data, error } = await supabase.rpc("approve_all_stock_requests", {
    p_shop_id: shopId ?? null,
  });
  if (error) return { ok: false, error: error.message };
  const row = Array.isArray(data) ? data[0] : data;
  revalidatePath("/", "layout");
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
  revalidatePath("/", "layout");
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
  revalidatePath("/", "layout");
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
  if (!name || !email || input.password.length < 6) {
    return { ok: false, error: "Name and valid email required; password at least 6 characters." };
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

  revalidatePath("/", "layout");
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
  revalidatePath("/", "layout");
  return { ok: true };
}

// ---------------------------------------------------------------------------
// Backup & restore (owner only)
// ---------------------------------------------------------------------------

export type RestoreResult = ActionResult & {
  restored?: boolean;
  transactions?: number;
};

export async function restoreBackup(raw: string): Promise<RestoreResult> {
  const session = await requireSession();
  if (session.profile?.role !== "owner") {
    return { ok: false, error: "Only the owner can restore backups." };
  }

  let parsed: unknown;
  try {
    parsed = JSON.parse(raw);
  } catch {
    return { ok: false, error: "File is not valid JSON." };
  }

  const data = parsed as { shops?: unknown[]; app?: string };
  if (!data || !Array.isArray(data.shops)) {
    return { ok: false, error: "Not a Mr Jeff Stock backup file." };
  }

  const supabase = await createClient();
  const { data: result, error } = await supabase.rpc("restore_backup", {
    p_data: parsed,
  });
  if (error) return { ok: false, error: error.message };

  revalidatePath("/", "layout");
  return {
    ok: true,
    restored: result?.restored ?? true,
    transactions: result?.transactions ?? 0,
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
  if (!rows.length) return { ok: false, error: "No rows to import." };

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

    const opening = parseInt(r.opening_stock ?? "0", 10);
    const safeOpening = Number.isFinite(opening) && opening > 0 ? opening : 0;

    toInsert.push({
      shop_id: shopId,
      model_name: name,
      condition,
      cost_price: r.cost_price ? Number(r.cost_price) : null,
      sale_price: r.sale_price ? Number(r.sale_price) : null,
      opening_stock: safeOpening,
      bought_in: 0,
      available: safeOpening,
      low_stock_threshold: parseInt(r.low_stock_threshold || "5", 10),
    });
  }

  if (toInsert.length) {
    const { error } = await supabase.from("phone_models").insert(toInsert);
    if (error) return { ok: false, error: error.message };
  }

  revalidatePath("/", "layout");
  return { ok: true, added: toInsert.length, skipped };
}
