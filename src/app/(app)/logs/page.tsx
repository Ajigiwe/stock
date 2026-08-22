import { redirect } from "next/navigation";
import { getSession, getLoginLogs, getStockLogs } from "@/lib/data";
import { formatDateTime } from "@/lib/format";
import { Badge, Card, EmptyState } from "@/components/ui";

export const dynamic = "force-dynamic";

const ACTION_LABEL: Record<string, string> = {
  create_model: "Added model",
  update_model: "Edited product",
  adjust_stock: "Adjusted stock",
  bulk_create: "Bulk added models",
};

function stockDetails(action: string, details: unknown): string {
  if (typeof details !== "object" || details === null) return "";
  const d = details as Record<string, unknown>;
  if (action === "adjust_stock") {
    const delta = Number(d.delta ?? 0);
    const type = String(d.type ?? "");
    const reason = String(d.reason ?? "");
    return `${delta > 0 ? "+" : ""}${delta} (${type})${reason ? ` · ${reason}` : ""}`;
  }
  if (action === "create_model") {
    const opening = Number(d.opening_stock ?? 0);
    return `Opening stock ${opening}`;
  }
  if (action === "bulk_create") return "";
  return "";
}

export default async function LogsPage() {
  const session = await getSession();
  if (!session) redirect("/login");
  if (session.profile?.role !== "owner") redirect("/");

  const [loginLogs, stockLogs] = await Promise.all([
    getLoginLogs(100),
    getStockLogs(200),
  ]);

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-xl font-bold text-ink">Logs</h1>
        <p className="text-sm text-mute">
          Who signed in and every stock edit, for your review
        </p>
      </div>

      <Card
        title="Sign-ins"
        subtitle={`${loginLogs.length} recent login${loginLogs.length === 1 ? "" : "s"}`}
      >
        {loginLogs.length === 0 ? (
          <EmptyState>No sign-ins recorded yet.</EmptyState>
        ) : (
          <ul className="divide-y divide-paper">
            {loginLogs.map((l) => (
              <li
                key={l.id}
                className="flex flex-wrap items-center justify-between gap-2 py-2"
              >
                <div className="min-w-0">
                  <div className="flex items-center gap-2">
                    <span className="font-medium text-ink">
                      {l.name ?? l.email ?? "Unknown"}
                    </span>
                    {l.device && (
                      <Badge tone="gray">{l.device}</Badge>
                    )}
                  </div>
                  <div className="mt-0.5 text-xs text-mute">
                    {l.email ? `${l.email} · ` : ""}
                    {l.ip ? `IP ${l.ip}` : "location unknown"}
                  </div>
                </div>
                <span className="text-xs text-mute">
                  {formatDateTime(l.created_at)}
                </span>
              </li>
            ))}
          </ul>
        )}
      </Card>

      <Card
        title="Stock edits"
        subtitle={`${stockLogs.length} recent change${stockLogs.length === 1 ? "" : "s"}`}
      >
        {stockLogs.length === 0 ? (
          <EmptyState>No stock edits recorded yet.</EmptyState>
        ) : (
          <ul className="divide-y divide-paper">
            {stockLogs.map((l) => (
              <li
                key={l.id}
                className="flex flex-wrap items-center justify-between gap-2 py-2"
              >
                <div className="min-w-0">
                  <div className="flex items-center gap-2">
                    <span className="font-medium text-ink">
                      {l.staff_name ?? "—"}
                    </span>
                    <Badge
                      tone={
                        l.action === "adjust_stock"
                          ? Number(
                              (l.details as Record<string, unknown> | null)
                                ?.delta ?? 0,
                            ) > 0
                            ? "green"
                            : "red"
                          : l.action === "create_model"
                            ? "blue"
                            : "gray"
                      }
                    >
                      {ACTION_LABEL[l.action] ?? l.action}
                    </Badge>
                  </div>
                  <div className="mt-0.5 text-xs text-mute">
                    {l.model_name ?? "—"}
                    {l.condition ? ` (${l.condition})` : ""}
                    {stockDetails(l.action, l.details)
                      ? ` · ${stockDetails(l.action, l.details)}`
                      : ""}
                    {l.shop_name ? ` · ${l.shop_name}` : ""}
                  </div>
                </div>
                <span className="text-xs text-mute">
                  {formatDateTime(l.created_at)}
                </span>
              </li>
            ))}
          </ul>
        )}
      </Card>
    </div>
  );
}