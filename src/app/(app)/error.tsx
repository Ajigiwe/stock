"use client";

export default function AppError({
  error,
  reset,
}: {
  error: Error & { digest?: string };
  reset: () => void;
}) {
  return (
    <div className="mx-auto flex max-w-md flex-col items-center gap-3 px-4 py-20 text-center">
      <div className="flex h-14 w-14 items-center justify-center rounded-2xl bg-lowstock-tint">
        <svg
          width="28"
          height="28"
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          strokeWidth="2"
          strokeLinecap="round"
          strokeLinejoin="round"
          className="text-lowstock"
        >
          <path d="M12 9v4M12 17h.01" />
          <path d="M10.3 3.9L1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z" />
        </svg>
      </div>
      <h1 className="text-lg font-extrabold text-ink">Something went wrong</h1>
      <p className="text-sm text-mute">
        The page failed to load. Your data is safe — try again, and if it keeps
        failing, check your connection.
      </p>
      {error.digest && (
        <p className="font-mono text-[11px] text-mute/70">Ref: {error.digest}</p>
      )}
      <button
        type="button"
        onClick={reset}
        className="mt-2 inline-flex h-11 items-center rounded-[10px] bg-brand px-6 text-sm font-bold text-white transition-colors hover:bg-brand-deep"
      >
        Try again
      </button>
    </div>
  );
}
