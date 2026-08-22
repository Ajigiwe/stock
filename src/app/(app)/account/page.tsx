import { redirect } from "next/navigation";
import { getSession } from "@/lib/data";
import { createClient } from "@/lib/supabase/server";
import { Badge, Card } from "@/components/ui";
import { ChangePasswordForm } from "@/components/account/change-password-form";

export const dynamic = "force-dynamic";

export default async function AccountPage() {
  const session = await getSession();
  if (!session) redirect("/login");

  const profile = session.profile;
  const isOwner = profile?.role === "owner";

  let shopName: string | null = null;
  if (profile?.shop_id) {
    const supabase = await createClient();
    const { data } = await supabase
      .from("shops")
      .select("name")
      .eq("id", profile.shop_id)
      .maybeSingle();
    shopName = data?.name ?? null;
  }

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-xl font-bold text-ink">Account</h1>
        <p className="text-sm text-mute">Your profile and password</p>
      </div>

      <Card title="Your details">
        <dl className="grid gap-x-6 gap-y-3 sm:grid-cols-2">
          <div>
            <dt className="text-xs font-medium uppercase tracking-wide text-mute">Name</dt>
            <dd className="mt-0.5 text-sm font-medium text-ink">
              {profile?.name || "—"}
            </dd>
          </div>
          <div>
            <dt className="text-xs font-medium uppercase tracking-wide text-mute">Email</dt>
            <dd className="mt-0.5 text-sm text-ink">{session.email ?? "—"}</dd>
          </div>
          <div>
            <dt className="text-xs font-medium uppercase tracking-wide text-mute">Role</dt>
            <dd className="mt-0.5">
              <Badge tone={isOwner ? "blue" : "gray"}>
                {isOwner ? "Owner" : "Attendant"}
              </Badge>
            </dd>
          </div>
          {!isOwner && (
            <div>
              <dt className="text-xs font-medium uppercase tracking-wide text-mute">Shop</dt>
              <dd className="mt-0.5 text-sm text-ink">{shopName ?? "—"}</dd>
            </div>
          )}
        </dl>
      </Card>

      <Card title="Change password" subtitle="Use at least 6 characters">
        <ChangePasswordForm />
      </Card>
    </div>
  );
}
