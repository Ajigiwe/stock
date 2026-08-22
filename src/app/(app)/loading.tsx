export default function Loading() {
  return (
    <div className="animate-pulse space-y-6">
      <div className="flex items-end justify-between gap-3">
        <div className="space-y-2">
          <div className="h-6 w-40 rounded bg-line" />
          <div className="h-4 w-56 rounded bg-paper" />
        </div>
        <div className="h-10 w-40 rounded-lg bg-line" />
      </div>

      <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
        {Array.from({ length: 6 }).map((_, i) => (
          <div
            key={i}
            className="rounded-xl border border-line bg-white p-4 shadow-sm"
          >
            <div className="h-3 w-16 rounded bg-paper" />
            <div className="mt-2 h-6 w-20 rounded bg-line" />
          </div>
        ))}
      </div>

      <div className="rounded-xl border border-line bg-white p-4 shadow-sm">
        <div className="h-4 w-32 rounded bg-line" />
        <div className="mt-4 h-40 w-full rounded bg-paper" />
      </div>

      <div className="grid gap-6 lg:grid-cols-2">
        {Array.from({ length: 2 }).map((_, i) => (
          <div
            key={i}
            className="space-y-3 rounded-xl border border-line bg-white p-4 shadow-sm"
          >
            <div className="h-4 w-24 rounded bg-line" />
            {Array.from({ length: 4 }).map((_, j) => (
              <div key={j} className="h-3 w-full rounded bg-paper" />
            ))}
          </div>
        ))}
      </div>
    </div>
  );
}
