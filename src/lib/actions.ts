"use server";

import { createClient as createAdminClient } from "@supabase/supabase-js";
import { revalidatePath } from "next/cache";
import { redirect } from "next/navigation";
import { createClient } from "@/lib/supabase/server";
import { requireSession } from "@/lib/data";
import type { PaymentMethod } from "@/lib/database.types";

export type ActionResult = {
  ok: boolean;
  error?: string;
};

export type RecordTransactionInput = {
  shopId: string;
  customerName: string;
  customerPhone: string;
  type: "sale" | "swap" | "repair";
  paymentMethod: PaymentMethod;
  amount: string;
  date: string; // YYYY-MM-DD
  outModelId?: string;
  outQty?: number;
  inModelId?: string;
  inModelName?: string;
  inCostPrice?: string;
  inSalePrice?: string;
  inQty?: number;
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
  const { error } = await supabase.auth.signInWithPassword({ email, password });
  if (error) return { ok: false, error: error.message };

  const next = String(formData.get("next") ?? "/");
  redirect(next.startsWith("/") ? next : "/");
}

export async function signup(
  _prev: ActionResult,
  formData: FormData,
): Promise<ActionResult> {
  const name = String(formData.get("name") ?? "").trim();
  const email = String(formData.get("email") ?? "").trim();
  const password = String(formData.get("password") ?? "");

  if (!name || !email || password.length < 6) {
    return { ok: false, error: "Name required, and password must be at least 6 characters." };
  }

  const supabase = await createClient();
  const { error } = await supabase.auth.signUp({
    email,
    password,
    options: { data: { name } },
  });
  if (error) return { ok: false, error: error.message };

  // If email confirmation is disabled, a session is returned immediately.
  return { ok: true };
}

export async function logout() {
  const supabase = await createClient();
  await supabase.auth.signOut();
  redirect("/login");
}

export async function claimOwner(): Promise<ActionResult> {
  const supabase = await createClient();
  const { error } = await supabase.rpc("claim_owner");
  if (error) return { ok: false, error: error.message };
  revalidatePath("/", "layout");
  return { ok: true };
}

// ---------------------------------------------------------------------------
// Transactions
// ---------------------------------------------------------------------------

export async function recordTransaction(
  input: RecordTransactionInput,
): Promise<ActionResult> {
  const session = await requireSession();
  const supabase = await createClient();

  const amount = Number(input.amount);
  if (!Number.isFinite(amount) || amount < 0) {
    return { ok: false, error: "Enter a valid amount." };
  }

  const outItems: { phone_model_id: string; qty: number }[] = [];
  if (input.type !== "repair" && input.outModelId && (input.outQty ?? 0) > 0) {
    outItems.push({ phone_model_id: input.outModelId, qty: input.outQty! });
  }

  const inItems: Record<string, unknown>[] = [];
  if (input.type === "swap" && (input.inQty ?? 0) > 0) {
    if (input.inModelId) {
      inItems.push({ phone_model_id: input.inModelId, qty: input.inQty });
    } else if (input.inModelName?.trim()) {
      inItems.push({
        model_name: input.inModelName.trim(),
        condition: "used",
        cost_price: input.inCostPrice ? Number(input.inCostPrice) : null,
        sale_price: input.inSalePrice ? Number(input.inSalePrice) : null,
        qty: input.inQty,
      });
    } else {
      return { ok: false, error: "Select or enter the phone coming in for the swap." };
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

  const { error } = await supabase.rpc("record_transaction", {
    p_shop_id: shopId,
    p_customer_name: input.customerName?.trim() || null,
    p_customer_phone: input.customerPhone?.trim() || null,
    p_type: input.type,
    p_payment_method: input.paymentMethod,
    p_amount: amount,
    p_date: date ?? new Date().toISOString(),
    p_out_items: JSON.stringify(outItems),
    p_in_items: JSON.stringify(inItems),
  });

  if (error) return { ok: false, error: error.message };

  revalidatePath("/", "layout");
  return { ok: true };
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

  const { error } = await supabase.rpc("adjust_stock", {
    p_shop_id: shopId,
    p_phone_model_id: input.phoneModelId,
    p_delta: delta,
    p_type: delta > 0 ? "restock" : "correction",
    p_reason: input.reason?.trim() || null,
  });

  if (error) return { ok: false, error: error.message };
  revalidatePath("/", "layout");
  return { ok: true };
}

// ---------------------------------------------------------------------------
// Admin (owner only)
// ---------------------------------------------------------------------------

function adminClient() {
  const url = process.env.NEXT_PUBLIC_SUPABASE_URL;
  const key = process.env.SUPABASE_SERVICE_ROLE_KEY;
  if (!url || !key) {
    throw new Error("SUPABASE_SERVICE_ROLE_KEY not configured on the server.");
  }
  return createAdminClient(url, key, {
    auth: { autoRefreshToken: false, persistSession: false },
  });
}

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
    const admin = adminClient();
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
    const admin = adminClient();
    const { error } = await admin.auth.admin.deleteUser(id);
    if (error) return { ok: false, error: error.message };
  } catch (e) {
    return { ok: false, error: (e as Error).message };
  }
  revalidatePath("/", "layout");
  return { ok: true };
}
