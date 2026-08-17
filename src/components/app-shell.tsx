"use client";

import { useEffect, useState, useSyncExternalStore, type ReactNode } from "react";
import Link from "next/link";
import { usePathname } from "next/navigation";
import { ShopSwitcher } from "@/components/shop-switcher";
import { LogoutButton } from "@/components/logout-button";
import type { Shop } from "@/lib/data";

type NavLink = { href: string; label: string; icon: ReactNode };

// Persisted sidebar-collapse preference as a tiny external store, so the
// initial client snapshot matches the server (no hydration mismatch) and we
// avoid calling setState inside an effect.
const COLLAPSE_KEY = "sidebar-collapsed";
let collapseState = false;
const collapseListeners = new Set<() => void>();

function setCollapseGlobal(v: boolean) {
  collapseState = v;
  try {
    localStorage.setItem(COLLAPSE_KEY, v ? "1" : "0");
  } catch {
    /* ignore */
  }
  collapseListeners.forEach((l) => l());
}

function subscribeCollapse(cb: () => void) {
  collapseListeners.add(cb);
  return () => {
    collapseListeners.delete(cb);
  };
}

const svgProps = {
  width: 18,
  height: 18,
  viewBox: "0 0 24 24",
  fill: "none",
  stroke: "currentColor",
  strokeWidth: 2,
  strokeLinecap: "round" as const,
  strokeLinejoin: "round" as const,
};

const ICONS: Record<string, ReactNode> = {
  dashboard: (
    <svg {...svgProps}>
      <rect x="3" y="3" width="7" height="7" rx="1" />
      <rect x="14" y="3" width="7" height="7" rx="1" />
      <rect x="14" y="14" width="7" height="7" rx="1" />
      <rect x="3" y="14" width="7" height="7" rx="1" />
    </svg>
  ),
  record: (
    <svg {...svgProps}>
      <circle cx="12" cy="12" r="9" />
      <path d="M12 8v8M8 12h8" />
    </svg>
  ),
  reports: (
    <svg {...svgProps}>
      <path d="M3 21h18" />
      <rect x="5" y="11" width="3" height="7" rx="1" />
      <rect x="10.5" y="6" width="3" height="12" rx="1" />
      <rect x="16" y="9" width="3" height="9" rx="1" />
    </svg>
  ),
  devices: (
    <svg {...svgProps}>
      <rect x="7" y="2" width="10" height="20" rx="2" />
      <path d="M11 18h2" />
    </svg>
  ),
  logs: (
    <svg {...svgProps}>
      <path d="M8 6h13M8 12h13M8 18h13" />
      <path d="M3.5 6h.01M3.5 12h.01M3.5 18h.01" />
    </svg>
  ),
  settings: (
    <svg {...svgProps}>
      <circle cx="12" cy="12" r="3" />
      <path d="M12 2v3M12 19v3M2 12h3M19 12h3M4.9 4.9l2.1 2.1M17 17l2.1 2.1M19.1 4.9L17 7M7 17l-2.1 2.1" />
    </svg>
  ),
  shop: (
    <svg {...svgProps}>
      <path d="M4 9l1-5h14l1 5" />
      <path d="M4 9a2.5 2.5 0 0 0 5 0 2.5 2.5 0 0 0 5 0 2.5 2.5 0 0 0 5 0" />
      <path d="M5 9v11h14V9" />
      <path d="M9 20v-6h6v6" />
    </svg>
  ),
  account: (
    <svg {...svgProps}>
      <circle cx="12" cy="8" r="4" />
      <path d="M4 21c0-4 4-6 8-6s8 2 8 6" />
    </svg>
  ),
};

export function AppShell({
  role,
  shops,
  shopId,
  userName,
  children,
}: {
  role: string | null;
  shops: Shop[];
  shopId?: string | null;
  userName?: string | null;
  children: React.ReactNode;
}) {
  const [open, setOpen] = useState(false);
  const collapsed = useSyncExternalStore(
    subscribeCollapse,
    () => collapseState,
    () => false,
  );
  const pathname = usePathname();

  // Hydrate the preference from localStorage after mount (updates the external
  // store, not React state — keeps the initial render matching the server).
  useEffect(() => {
    try {
      const stored = localStorage.getItem(COLLAPSE_KEY) === "1";
      if (stored !== collapseState) setCollapseGlobal(stored);
    } catch {
      /* ignore */
    }
  }, []);

  const toggleCollapsed = () => setCollapseGlobal(!collapseState);

  const links: NavLink[] = [
    { href: "/", label: "Dashboard", icon: ICONS.dashboard },
    { href: "/transactions/new", label: "Record transaction", icon: ICONS.record },
    { href: "/reports", label: "Reports", icon: ICONS.reports },
    ...(role === "owner"
      ? [
          { href: "/devices", label: "Devices", icon: ICONS.devices },
          { href: "/logs", label: "Logs", icon: ICONS.logs },
          { href: "/settings", label: "Settings", icon: ICONS.settings },
        ]
      : []),
    ...(role === "attendant" && shopId
      ? [{ href: `/shops/${shopId}`, label: "My shop", icon: ICONS.shop }]
      : []),
    { href: "/account", label: "Account", icon: ICONS.account },
  ];

  const isActive = (href: string) =>
    href === "/" ? pathname === "/" : pathname.startsWith(href);

  const renderSidebar = (isCollapsed: boolean, onToggle?: () => void) => (
    <div className="flex h-full flex-col">
      <div className="flex h-14 shrink-0 items-center gap-2 border-b border-zinc-800 px-3">
        {!isCollapsed && (
          <Link
            href="/"
            onClick={() => setOpen(false)}
            className="text-base font-bold text-white"
          >
            Mr Jeff Stock
          </Link>
        )}
        {onToggle && (
          <button
            type="button"
            onClick={onToggle}
            aria-label={isCollapsed ? "Expand sidebar" : "Collapse sidebar"}
            title={isCollapsed ? "Expand" : "Collapse"}
            className={`inline-flex h-8 w-8 items-center justify-center rounded-lg text-zinc-400 transition-colors hover:bg-zinc-800 hover:text-white ${
              isCollapsed ? "mx-auto" : "ml-auto"
            }`}
          >
            <svg
              width="18"
              height="18"
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              strokeWidth="2"
              strokeLinecap="round"
              strokeLinejoin="round"
            >
              {isCollapsed ? (
                <path d="M9 6l6 6-6 6" />
              ) : (
                <path d="M15 6l-6 6 6 6" />
              )}
            </svg>
          </button>
        )}
      </div>

      <nav className="flex-1 space-y-1 overflow-y-auto p-3">
        {links.map((l) => (
          <Link
            key={l.href}
            href={l.href}
            onClick={() => setOpen(false)}
            title={isCollapsed ? l.label : undefined}
            className={`flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors ${
              isCollapsed ? "justify-center" : ""
            } ${
              isActive(l.href)
                ? "bg-indigo-600 text-white"
                : "text-zinc-400 hover:bg-zinc-800 hover:text-white"
            }`}
          >
            <span className="shrink-0">{l.icon}</span>
            {!isCollapsed && <span className="truncate">{l.label}</span>}
          </Link>
        ))}

        {role === "owner" && shops.length > 0 && !isCollapsed && (
          <div className="mt-3 border-t border-zinc-800 pt-3">
            <p className="px-3 pb-1 text-xs uppercase tracking-wide text-zinc-500">
              Shops
            </p>
            <ShopSwitcher shops={shops} />
          </div>
        )}
      </nav>

      <div className="shrink-0 border-t border-zinc-800 p-3">
        <Link
          href="/account"
          onClick={() => setOpen(false)}
          title={isCollapsed ? userName || "Account" : undefined}
          className={`mb-2 flex items-center gap-2 rounded-lg px-2 py-1.5 transition-colors hover:bg-zinc-900 ${
            isCollapsed ? "justify-center" : ""
          }`}
        >
          <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-indigo-600 text-xs font-semibold text-white">
            {(userName || "?").charAt(0).toUpperCase()}
          </span>
          {!isCollapsed && (
            <span className="min-w-0">
              <span className="block truncate text-sm font-medium text-white">
                {userName || "My account"}
              </span>
              <span className="block text-xs capitalize text-zinc-500">
                {role ?? "signed in"}
              </span>
            </span>
          )}
        </Link>
        <LogoutButton collapsed={isCollapsed} />
      </div>
    </div>
  );

  return (
    <>
      {/* Mobile top bar */}
      <header className="fixed inset-x-0 top-0 z-40 flex h-14 items-center justify-between bg-zinc-900 px-4 md:hidden">
        <button
          onClick={() => setOpen(true)}
          aria-label="Open menu"
          className="inline-flex h-9 w-9 items-center justify-center rounded-lg text-white hover:bg-zinc-800"
        >
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round">
            <path d="M4 6h16M4 12h16M4 18h16" />
          </svg>
        </button>
        <Link href="/" className="text-base font-bold text-white">
          Mr Jeff Stock
        </Link>
        <span className="w-9" />
      </header>

      {/* Mobile overlay sidebar (always expanded) */}
      {open && (
        <div className="fixed inset-0 z-50 md:hidden">
          <div
            className="absolute inset-0 bg-black/50"
            onClick={() => setOpen(false)}
          />
          <aside className="absolute inset-y-0 left-0 w-64 bg-zinc-900 shadow-xl">
            <button
              type="button"
              onClick={() => setOpen(false)}
              aria-label="Close menu"
              className="absolute right-3 top-3 z-10 inline-flex h-8 w-8 items-center justify-center rounded-lg text-zinc-400 transition-colors hover:bg-zinc-800 hover:text-white"
            >
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round">
                <path d="M6 6l12 12M18 6L6 18" />
              </svg>
            </button>
            {renderSidebar(false)}
          </aside>
        </div>
      )}

      {/* Desktop sidebar (collapsible) */}
      <aside
        className={`fixed inset-y-0 left-0 z-30 hidden bg-zinc-900 transition-[width] duration-200 md:block ${
          collapsed ? "w-16" : "w-60"
        }`}
      >
        {renderSidebar(collapsed, toggleCollapsed)}
      </aside>

      <main
        className={`min-h-screen pt-14 transition-[margin] duration-200 md:pt-0 ${
          collapsed ? "md:ml-16" : "md:ml-60"
        }`}
      >
        <div className="mx-auto w-full max-w-5xl px-4 py-6">{children}</div>
      </main>
    </>
  );
}
