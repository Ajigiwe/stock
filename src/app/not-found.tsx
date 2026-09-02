import Link from "next/link";

export default function NotFound() {
  return (
    <div className="mx-auto flex min-h-screen max-w-md flex-col items-center justify-center gap-3 px-4 text-center">
      <div className="font-mono text-5xl font-extrabold tracking-tight text-brand">
        404
      </div>
      <h1 className="text-lg font-extrabold text-ink">Page not found</h1>
      <p className="text-sm text-mute">
        The page you&rsquo;re looking for doesn&rsquo;t exist or may have moved.
      </p>
      <Link
        href="/"
        className="mt-2 inline-flex h-11 items-center rounded-[10px] bg-brand px-6 text-sm font-bold text-white transition-colors hover:bg-brand-deep"
      >
        Back to dashboard
      </Link>
    </div>
  );
}
