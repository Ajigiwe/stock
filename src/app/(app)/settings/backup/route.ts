import { NextResponse } from "next/server";
import { createClient } from "@/lib/supabase/server";

export const dynamic = "force-dynamic";

// Owner-only: download a full JSON backup of all business data. Shape matches
// the restore_backup() RPC so the file can be fed straight back in.
export async function GET() {
  const supabase = await createClient();
  const {
    data: { user },
  } = await supabase.auth.getUser();
  if (!user) return NextResponse.json({ error: "Unauthorized" }, { status: 401 });

  const { data: profile } = await supabase
    .from("users")
    .select("role")
    .eq("id", user.id)
    .maybeSingle();
  if (profile?.role !== "owner") {
    return NextResponse.json({ error: "Forbidden" }, { status: 403 });
  }

  const tables = [
    "shops",
    "users",
    "phone_models",
    "transactions",
    "transaction_items",
    "stock_adjustments",
  ] as const;

  const backup: Record<string, unknown> = {
    app: "mr-jeff-stock",
    version: 1,
    exported_at: new Date().toISOString(),
  };

  for (const table of tables) {
    const { data: rows, error } = await supabase.from(table).select("*");
    if (error) return NextResponse.json({ error: error.message }, { status: 500 });
    backup[table] = rows ?? [];
  }

  const filename = `mr-jeff-stock-backup-${new Date().toISOString().slice(0, 10)}.json`;
  return new Response(JSON.stringify(backup, null, 2), {
    status: 200,
    headers: {
      "Content-Type": "application/json",
      "Content-Disposition": `attachment; filename="${filename}"`,
    },
  });
}
