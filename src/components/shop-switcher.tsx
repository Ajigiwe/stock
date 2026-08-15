"use client";

import { useRouter } from "next/navigation";
import type { Shop } from "@/lib/data";

export function ShopSwitcher({ shops }: { shops: Shop[] }) {
  const router = useRouter();

  return (
    <select
      className="ml-1 h-9 rounded-lg border border-zinc-300 bg-white px-2 text-sm text-zinc-700"
      onChange={(e) => {
        if (e.target.value) router.push(`/shops/${e.target.value}`);
      }}
      value=""
    >
      <option value="">Shops…</option>
      {shops.map((s) => (
        <option key={s.id} value={s.id}>
          {s.name}
        </option>
      ))}
    </select>
  );
}
