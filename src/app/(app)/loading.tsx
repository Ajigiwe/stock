export default function Loading() {
  return (
    <div className="animate-pulse space-y-6">
      <div className="flex items-end justify-between gap-3">
        <div className="space-y-2">
          <div className="h-6 w-40 rounded bg-zinc-200" />
          <div className="h-4 w-56 rounded bg-zinc-100" />
        </div>
        <div className="h-10 w-40 rounded-lg bg-zinc-200" />
      </div>

      <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
        {Array.from({ length: 6 }).map((_, i) => (
          <div
            key={i}
            className="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm"
          >
            <div className="h-3 w-16 rounded bg-zinc-100" />
            <div className="mt-2 h-6 w-20 rounded bg-zinc-200" />
          </div>
        ))}
      </div>

      <div className="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm">
        <div className="h-4 w-32 rounded bg-zinc-200" />
        <div className="mt-4 h-40 w-full rounded bg-zinc-100" />
      </div>

      <div className="grid gap-6 lg:grid-cols-2">
        {Array.from({ length: 2 }).map((_, i) => (
          <div
            key={i}
            className="space-y-3 rounded-xl border border-zinc-200 bg-white p-4 shadow-sm"
          >
            <div className="h-4 w-24 rounded bg-zinc-200" />
            {Array.from({ length: 4 }).map((_, j) => (
              <div key={j} className="h-3 w-full rounded bg-zinc-100" />
            ))}
          </div>
        ))}
      </div>
    </div>
  );
}
