"use client";

import { useEffect } from "react";
import { useRouter } from "next/navigation";
import type { Shop } from "@/lib/data";

export function ShopSwitcher({ shops }: { shops: Shop[] }) {
  const router = useRouter();

  // Warm the pages the owner is most likely to open next. A native <select>
  // can't prefetch on hover like a Link can, so prefetch every shop in the
  // background as soon as the switcher mounts — the router cache then renders
  // the target shop page instantly when it is picked.
  useEffect(() => {
    for (const s of shops) {
      router.prefetch(`/shops/${s.id}`);
    }
  }, [router, shops]);

  return (
    <select
      className="h-9 w-full cursor-pointer rounded-lg border border-white/15 bg-white/10 px-2 text-sm text-white"
      onChange={(e) => {
        if (e.target.value) router.push(`/shops/${e.target.value}`);
      }}
      value=""
    >
      <option value="" className="text-white">
        Shops…
      </option>
      {shops.map((s) => (
        <option key={s.id} value={s.id} className="text-ink">
          {s.name}
        </option>
      ))}
    </select>
  );
}
