import { redirect } from "next/navigation";
import { getSession, getShops } from "@/lib/data";
import { createClient } from "@/lib/supabase/server";
import { Card } from "@/components/ui";
import { ShopManager } from "@/components/settings/shop-manager";
import { StaffManager } from "@/components/settings/staff-manager";

export default async function SettingsPage() {
  const session = await getSession();
  if (!session) redirect("/login");

  if (session.profile?.role !== "owner") {
    redirect("/");
  }

  const supabase = await createClient();
  const [shops, staffRes] = await Promise.all([
    getShops(),
    supabase.from("users").select("*").order("name"),
  ]);

  const staff = staffRes.data ?? [];

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-xl font-bold text-zinc-900">Settings</h1>
        <p className="text-sm text-zinc-500">Manage shops and staff</p>
      </div>

      <Card title="Shops" subtitle="Your shop locations">
        <ShopManager shops={shops} />
      </Card>

      <Card title="Staff" subtitle="Shop attendants">
        <StaffManager shops={shops} staff={staff} />
      </Card>
    </div>
  );
}
