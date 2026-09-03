import { Badge, EmptyState } from "@/components/ui";
import type { StockRecon, StockCountWithItems } from "@/lib/data";

export function StockReconPanel({
  recon,
  count,
}: {
  recon: StockRecon;
  count: StockCountWithItems | null;
}) {
  const countedByModel = new Map(
    (count?.items ?? []).map((i) => [i.phone_model_id, i.counted_qty]),
  );
  const rows = recon.rows.map((r) => {
    const counted = countedByModel.get(r.phone_model_id) ?? null;
    return {
      ...r,
      counted,
      variance: counted == null ? null : counted - r.closing,
    };
  });

  return (
    <div className="space-y-4">
      <div className="grid grid-cols-3 gap-3">
        <Stat label="In the morning" value={recon.totalOpening} tone="slate" />
        <Stat label="Bought today" value={recon.totalSold} tone="brand" />
        <Stat label="Left now" value={recon.totalClosing} tone="slate" />
      </div>

      {rows.length === 0 ? (
        <EmptyState>No stock models for this shop.</EmptyState>
      ) : (
        <>
          <div className="hidden overflow-x-auto md:block">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-line text-left text-xs uppercase tracking-wide text-mute">
                  <th className="py-2 pr-4 font-medium">Model</th>
                  <th className="py-2 pr-4 font-medium">Condition</th>
                  <th className="py-2 pr-4 text-right font-medium">Morning</th>
                  <th className="py-2 pr-4 text-right font-medium">Bought</th>
                  <th className="py-2 pr-4 text-right font-medium">Trade-ins</th>
                  <th className="py-2 pr-4 text-right font-medium">Restocked</th>
                  <th className="py-2 pr-4 text-right font-medium">Removed</th>
                  <th className="py-2 pr-4 text-right font-medium">Left now</th>
                  <th className="py-2 pr-4 text-right font-medium">Counted</th>
                  <th className="py-2 text-right font-medium">Δ</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((r) => (
                  <tr
                    key={r.phone_model_id}
                    className="border-b border-paper"
                  >
                    <td className="py-2 pr-4 font-medium text-ink">{r.model_name}</td>
                    <td className="py-2 pr-4">
                      <Badge tone={r.condition === "new" ? "blue" : "gray"}>
                        {r.condition}
                      </Badge>
                    </td>
                    <td className="py-2 pr-4 text-right">{r.opening}</td>
                    <td className="py-2 pr-4 text-right">
                      <span className="font-semibold">{r.sold}</span>
                      {r.pending > 0 && (
                        <span className="ml-1 text-xs font-medium text-amber-600">
                          +{r.pending} review
                        </span>
                      )}
                    </td>
                    <td className="py-2 pr-4 text-right">{r.trade_in || "—"}</td>
                    <td className="py-2 pr-4 text-right">{r.restocked || "—"}</td>
                    <td className="py-2 pr-4 text-right">{r.removed || "—"}</td>
                    <td className="py-2 pr-4 text-right font-semibold text-ink">
                      {r.closing}
                    </td>
                    <td className="py-2 pr-4 text-right">
                      {r.counted == null ? (
                        <span className="text-mute">—</span>
                      ) : (
                        r.counted
                      )}
                    </td>
                    <td className="py-2 text-right">
                      {r.counted == null ? (
                        <span className="text-mute">—</span>
                      ) : r.variance === 0 ? (
                        <Badge tone="green">match</Badge>
                      ) : (
                        <Badge tone="red">
                          {r.variance! > 0 ? `+${r.variance}` : `${r.variance}`}
                        </Badge>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          <ul className="space-y-2 md:hidden">
            {rows.map((r) => (
              <li
                key={r.phone_model_id}
                className="rounded-lg border border-line bg-paper px-3 py-2"
              >
                <div className="flex items-center justify-between gap-2">
                  <div className="flex flex-wrap items-center gap-2">
                    <span className="truncate text-sm font-medium text-ink">
                      {r.model_name}
                    </span>
                    <Badge tone={r.condition === "new" ? "blue" : "gray"}>
                      {r.condition}
                    </Badge>
                  </div>
                  {r.counted == null ? null : r.variance === 0 ? (
                    <Badge tone="green">match</Badge>
                  ) : (
                    <Badge tone="red">
                      {r.variance! > 0 ? `+${r.variance}` : `${r.variance}`}
                    </Badge>
                  )}
                </div>
                <div className="mt-1 grid grid-cols-3 gap-2 text-xs text-mute">
                  <span>
                    Morning <b className="text-ink">{r.opening}</b>
                  </span>
                  <span>
                    Bought{" "}
                    <b className="text-ink">
                      {r.sold}
                      {r.pending > 0 ? ` +${r.pending}` : ""}
                    </b>
                  </span>
                  <span>
                    Left <b className="text-ink">{r.closing}</b>
                  </span>
                </div>
              </li>
            ))}
          </ul>
        </>
      )}

      {count ? (
        <p className="text-xs text-mute">
          The physical count submitted for this day is included — any row that
          doesn&apos;t show <Badge tone="green">match</Badge> means what is on the
          shelf differs from the ledger, so it needs a review.
        </p>
      ) : (
        <p className="text-xs text-mute">
          No physical count was recorded for this day yet — submit one in the card
          below to compare shelf stock against this ledger.
        </p>
      )}
    </div>
  );
}

function Stat({
  label,
  value,
  tone,
}: {
  label: string;
  value: number;
  tone: "brand" | "slate";
}) {
  return (
    <div
      className={`rounded-xl border p-3 ${
        tone === "brand" ? "border-brand bg-brand-tint" : "border-line bg-paper"
      }`}
    >
      <div className="text-xs font-medium text-mute">{label}</div>
      <div
        className={`mt-1 text-2xl font-bold ${
          tone === "brand" ? "text-brand" : "text-ink"
        }`}
      >
        {value}
      </div>
    </div>
  );
}