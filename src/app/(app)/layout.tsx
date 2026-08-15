import { redirect } from "next/navigation";
import { getSession, getShops } from "@/lib/data";
import { AppShell } from "@/components/app-shell";

export const dynamic = "force-dynamic";

export default async function AppLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  const session = await getSession();
  if (!session) redirect("/login");

  const profile = session.profile;
  const shops = profile?.role === "owner" ? await getShops() : [];

  return (
    <AppShell
      role={profile?.role ?? null}
      shops={shops}
      shopId={profile?.shop_id ?? null}
      userName={profile?.name ?? session.email ?? null}
    >
      {children}
    </AppShell>
  );
}