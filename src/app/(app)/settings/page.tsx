import { redirect } from "next/navigation";
import { getSession, getShops } from "@/lib/data";
import { createClient } from "@/lib/supabase/server";
import { Card } from "@/components/ui";
import { ShopManager } from "@/components/settings/shop-manager";
import { StaffManager } from "@/components/settings/staff-manager";
import { BackupRestore } from "@/components/settings/backup-restore";
import { BulkAddModels } from "@/components/settings/bulk-add-models";

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
        <p className="text-sm text-zinc-500">Manage shops, staff, and data</p>
      </div>

      <Card title="Shops" subtitle="Your shop locations">
        <ShopManager shops={shops} />
      </Card>

      <Card title="Staff" subtitle="Shop attendants">
        <StaffManager shops={shops} staff={staff} />
      </Card>

      <Card title="Bulk add devices" subtitle="Import many phone models at once">
        <BulkAddModels shops={shops} />
      </Card>

      <Card title="Backup & restore" subtitle="Download or restore a full data backup">
        <BackupRestore />
      </Card>
    </div>
  );
}
