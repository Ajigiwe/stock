"use client";

import { logout } from "@/lib/actions";

export function LogoutButton() {
  return (
    <button
      onClick={() => void logout()}
      className="ml-1 rounded-lg px-3 py-1.5 text-sm font-medium text-zinc-500 transition-colors hover:bg-zinc-100 hover:text-zinc-900"
    >
      Sign out
    </button>
  );
}
