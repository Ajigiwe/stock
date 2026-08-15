"use client";

import { logout } from "@/lib/actions";

export function LogoutButton({ collapsed = false }: { collapsed?: boolean }) {
  return (
    <button
      onClick={() => void logout()}
      title={collapsed ? "Sign out" : undefined}
      aria-label="Sign out"
      className={`flex w-full items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium text-zinc-400 transition-colors hover:bg-zinc-800 hover:text-white ${
        collapsed ? "justify-center" : ""
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
        className="shrink-0"
      >
        <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />
        <path d="M16 17l5-5-5-5" />
        <path d="M21 12H9" />
      </svg>
      {!collapsed && <span>Sign out</span>}
    </button>
  );
}
