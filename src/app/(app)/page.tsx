import { redirect } from "next/navigation";
import Link from "next/link";
import { getSession, getDashboardData, type DashboardPeriod } from "@/lib/data";
import { Dashboard } from "@/components/dashboard";

export const dynamic = "force-dynamic";

export default async function HomePage({
  searchParams,
}: {
  searchParams: Promise<{ period?: string }>;
}) {
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

  // No role or shop assigned yet (shouldn't happen normally - the owner
  // creates all accounts and assigns staff to a shop).
  if (profile.role !== "owner" && !profile.shop_id) {
    return (
      <div className="mx-auto max-w-md py-12">
        <div className="rounded-xl border border-zinc-200 bg-white p-6 shadow-sm">
          <h1 className="text-lg font-semibold text-zinc-900">Welcome</h1>
          <p className="mt-2 text-sm text-zinc-600">
            Your account has no role or shop assigned yet. Ask the owner to
            assign you to a shop from Settings.
          </p>
        </div>
      </div>
    );
  }

  const sp = await searchParams;
  const period: DashboardPeriod =
    sp.period === "7d" || sp.period === "30d" ? sp.period : "today";
  const data = await getDashboardData(period);

  if (profile.role === "owner" && data.summaries.length === 0) {
    return (
      <div className="mx-auto max-w-md py-12 text-center">
        <h1 className="text-lg font-semibold text-zinc-900">No shops yet</h1>
        <p className="mt-2 text-sm text-zinc-600">
          Add your first shop to start tracking stock.
        </p>
        <Link
          href="/settings"
          className="mt-4 inline-block rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-500"
        >
          Add a shop
        </Link>
      </div>
    );
  }

  return <Dashboard data={data} />;
}