"use client";

import { useRouter } from "next/navigation";
import type { DashboardPeriod, Shop } from "@/lib/data";
import { Select } from "@/components/ui";

export function ShopFilter({
  shops,
  value,
  period,
}: {
  shops: Shop[];
  value: string | null;
  period: DashboardPeriod;
}) {
  const router = useRouter();

  const onSelect = (id: string) => {
    const params = new URLSearchParams();
    if (period !== "today") params.set("period", period);
    if (id) params.set("shop", id);
    const qs = params.toString();
    router.push(qs ? `/?${qs}` : "/");
  };

  return (
    <Select
      value={value ?? ""}
      onChange={(e) => onSelect(e.target.value)}
      aria-label="Filter by shop"
      className="h-9 w-auto min-w-[9rem] text-xs"
    >
      <option value="">All shops</option>
      {shops.map((s) => (
        <option key={s.id} value={s.id}>
          {s.name}
        </option>
      ))}
    </Select>
  );
}