"use client";

import { useRouter } from "next/navigation";
import type { Shop } from "@/lib/data";

export function ShopSwitcher({ shops }: { shops: Shop[] }) {
  const router = useRouter();

  return (
    <select
      className="h-9 w-full cursor-pointer rounded-lg border border-white/15 bg-white/10 px-2 text-sm text-white"
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
