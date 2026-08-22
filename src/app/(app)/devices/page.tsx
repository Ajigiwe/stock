import { redirect } from "next/navigation";
import { getSession, getDevicesData } from "@/lib/data";
import { DevicesTable } from "@/components/devices-table";

export const dynamic = "force-dynamic";

export default async function DevicesPage() {
  const session = await getSession();
  if (!session) redirect("/login");
  if (session.profile?.role !== "owner") redirect("/");

  const { shops, rows } = await getDevicesData();

  const totalUnits = rows.reduce((a, r) => a + r.total, 0);
  const totalSold = rows.reduce((a, r) => a + r.sold, 0);
  const lowModels = rows.filter((r) => r.low > 0).length;

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-xl font-bold text-ink">Devices</h1>
        <p className="text-sm text-mute">
          Available pieces per model across every shop, plus who sold them
        </p>
      </div>

      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <div className="rounded-xl border border-line bg-white p-4 shadow-sm">
          <div className="text-[11px] font-semibold uppercase tracking-wide text-mute">
            Models
          </div>
          <div className="mt-1 text-2xl font-bold tabular-nums text-ink">
            {rows.length}
          </div>
        </div>
        <div className="rounded-xl border border-line bg-white p-4 shadow-sm">
          <div className="text-[11px] font-semibold uppercase tracking-wide text-mute">
            Units available
          </div>
          <div className="mt-1 text-2xl font-bold tabular-nums text-ink">
            {totalUnits}
          </div>
        </div>
        <div className="rounded-xl border border-line bg-white p-4 shadow-sm">
          <div className="text-[11px] font-semibold uppercase tracking-wide text-mute">
            Units sold
          </div>
          <div className="mt-1 text-2xl font-bold tabular-nums text-instock">
            {totalSold}
          </div>
        </div>
        <div className="rounded-xl border border-line bg-white p-4 shadow-sm">
          <div className="text-[11px] font-semibold uppercase tracking-wide text-mute">
            Low in a shop
          </div>
          <div
            className={`mt-1 text-2xl font-bold tabular-nums ${
              lowModels > 0 ? "text-lowstock" : "text-ink"
            }`}
          >
            {lowModels}
          </div>
        </div>
      </div>

      <DevicesTable shops={shops} rows={rows} />
    </div>
  );
}