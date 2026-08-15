import { redirect } from "next/navigation";
import Link from "next/link";
import { getSession, getDashboard } from "@/lib/data";
import { ClaimOwnerButton } from "@/components/claim-owner-button";
import { Dashboard } from "@/components/dashboard";

export default async function HomePage() {
  const session = await getSession();
  if (!session) redirect("/login");

  const profile = session.profile;
  if (!profile) {
    return (
      <div className="mx-auto max-w-md py-16 text-center">
        <p className="text-sm text-zinc-600">Profile not found.</p>
      </div>
    );
  }

  // Fresh signup with no role assignment yet.
  if (profile.role !== "owner" && !profile.shop_id) {
    return (
      <div className="mx-auto max-w-md py-12">
        <div className="rounded-xl border border-zinc-200 bg-white p-6 shadow-sm">
          <h1 className="text-lg font-semibold text-zinc-900">Welcome</h1>
          <p className="mt-2 text-sm text-zinc-600">
            Your account is created but has no role or shop assigned yet.
          </p>
          <div className="mt-4">
            <ClaimOwnerButton />
          </div>
          <p className="mt-4 text-xs text-zinc-400">
            If you are shop staff, ask the owner to assign you to a shop.
          </p>
        </div>
      </div>
    );
  }

  if (profile.role === "attendant" && profile.shop_id) {
    redirect(`/shops/${profile.shop_id}`);
  }

  // Owner dashboard.
  const summaries = await getDashboard();

  if (summaries.length === 0) {
    return (
      <div className="mx-auto max-w-md py-12 text-center">
        <h1 className="text-lg font-semibold text-zinc-900">No shops yet</h1>
        <p className="mt-2 text-sm text-zinc-600">
          Add your first shop to start tracking stock.
        </p>
        <Link href="/settings" className="mt-4 inline-block rounded-lg bg-zinc-900 px-4 py-2 text-sm font-medium text-white">
          Add a shop
        </Link>
      </div>
    );
  }

  return <Dashboard summaries={summaries} />;
}
