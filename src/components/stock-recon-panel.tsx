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
  const rows = recon.rows
    .map((r) => {
      const counted = countedByModel.get(r.phone_model_id) ?? null;
      return {
        ...r,
        counted,
        variance: counted == null ? null : counted - r.closing,
        touched:
          r.sold > 0 ||
          r.pending > 0 ||
          r.trade_in > 0 ||
          r.restocked > 0 ||
          r.removed > 0 ||
          counted != null,
      };
    })
    .sort(
      (a, b) =>
        Number(b.touched) - Number(a.touched) ||
        a.model_name.localeCompare(b.model_name) ||
        a.condition.localeCompare(b.condition),
    );

  const touchedRows = rows.filter((r) => r.touched);
  const untouchedRows = rows.filter((r) => !r.touched);

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
                  <th className="py-2 pr-4 text-right font-medium">Morning</th>
                  <th className="py-2 pr-4 text-right font-medium">Bought</th>
                  <th className="py-2 pr-4 text-right font-medium">Left now</th>
                  <th className="py-2 pr-4 text-right font-medium">Counted</th>
                  <th className="py-2 text-right font-medium">Δ</th>
                </tr>
              </thead>
              <tbody>
                {touchedRows.map((r) => (
                  <tr key={r.phone_model_id} className="border-b border-paper">
                    <td className="py-2 pr-4">
                      <div className="flex flex-wrap items-center gap-2">
                        <span className="font-medium text-ink">{r.model_name}</span>
                        <Badge tone={r.condition === "new" ? "blue" : "gray"}>
                          {r.condition}
                        </Badge>
                      </div>
                      <StockNotes r={r} />
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

                {untouchedRows.length > 0 && (
                  <tr>
                    <td
                      colSpan={6}
                      className="pt-3 text-xs font-medium uppercase tracking-wide text-mute"
                    >
                      No movement — {untouchedRows.length} model
                      {untouchedRows.length === 1 ? "" : "s"} unchanged
                    </td>
                  </tr>
                )}

                {untouchedRows.map((r) => (
                  <tr
                    key={r.phone_model_id}
                    className="border-b border-paper opacity-60"
                  >
                    <td className="py-2 pr-4">
                      <div className="flex flex-wrap items-center gap-2">
                        <span className="font-medium text-ink">{r.model_name}</span>
                        <Badge tone={r.condition === "new" ? "blue" : "gray"}>
                          {r.condition}
                        </Badge>
                      </div>
                    </td>
                    <td className="py-2 pr-4 text-right">{r.opening}</td>
                    <td className="py-2 pr-4 text-right">{r.sold}</td>
                    <td className="py-2 pr-4 text-right">{r.closing}</td>
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
            {touchedRows.map((r) => (
              <ReconCard key={r.phone_model_id} r={r} />
            ))}
            {untouchedRows.length > 0 && (
              <li className="px-1 pt-2 text-xs font-medium uppercase tracking-wide text-mute">
                No movement — {untouchedRows.length} model
                {untouchedRows.length === 1 ? "" : "s"} unchanged
              </li>
            )}
            {untouchedRows.map((r) => (
              <li key={r.phone_model_id} className="opacity-60">
                <ReconCard r={r} />
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

function StockNotes({ r }: { r: StockReconRowWithMeta }) {
  const notes: string[] = [];
  if (r.trade_in) notes.push(`+${r.trade_in} trade-in`);
  if (r.restocked) notes.push(`+${r.restocked} restocked`);
  if (r.removed) notes.push(`−${r.removed} removed`);
  if (notes.length === 0) return null;
  return (
    <div className="mt-0.5 text-xs text-mute">
      {notes.join(" · ")}
    </div>
  );
}

function ReconCard({ r }: { r: StockReconRowWithMeta }) {
  return (
    <li className="rounded-lg border border-line bg-paper px-3 py-2">
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
      <StockNotes r={r} />
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
  );
}

type StockReconRowWithMeta = {
  phone_model_id: string;
  model_name: string;
  condition: "new" | "used";
  opening: number;
  sold: number;
  pending: number;
  trade_in: number;
  restocked: number;
  removed: number;
  closing: number;
  counted: number | null;
  variance: number | null;
  touched: boolean;
};

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