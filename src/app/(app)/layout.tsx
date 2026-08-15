import Link from "next/link";
import { redirect } from "next/navigation";
import { getSession, getShops } from "@/lib/data";
import { LogoutButton } from "@/components/logout-button";
import { ShopSwitcher } from "@/components/shop-switcher";

export const dynamic = "force-dynamic";

export default async function AppLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  const session = await getSession();
  if (!session) redirect("/login");

  const profile = session.profile;
  const shops = profile?.role === "owner" ? await getShops() : [];

  const navLinks = [
    { href: "/", label: "Home" },
    { href: "/transactions/new", label: "Record" },
    { href: "/reports", label: "Reports" },
  ];

  return (
    <div className="flex min-h-screen flex-col">
      <header className="sticky top-0 z-10 border-b border-zinc-200 bg-white/95 backdrop-blur">
        <div className="mx-auto flex h-14 w-full max-w-5xl items-center justify-between gap-3 px-4">
          <Link href="/" className="text-base font-bold text-zinc-900">
            Mr Jeff Stock
          </Link>

          <nav className="flex items-center gap-1">
            {navLinks.map((l) => (
              <Link
                key={l.href}
                href={l.href}
                className="rounded-lg px-3 py-1.5 text-sm font-medium text-zinc-600 transition-colors hover:bg-zinc-100 hover:text-zinc-900"
              >
                {l.label}
              </Link>
            ))}
            {profile?.role === "owner" && shops.length > 0 && (
              <div className="hidden sm:block">
                <ShopSwitcher shops={shops} />
              </div>
            )}
            {profile?.role === "owner" && (
              <Link
                href="/settings"
                className="rounded-lg px-3 py-1.5 text-sm font-medium text-zinc-600 transition-colors hover:bg-zinc-100 hover:text-zinc-900"
              >
                Settings
              </Link>
            )}
            <LogoutButton />
          </nav>
        </div>
      </header>
      <main className="mx-auto w-full max-w-5xl flex-1 px-4 py-6">
        {children}
      </main>
    </div>
  );
}
