"use client";

import { useEffect, useRef } from "react";
import { useRouter } from "next/navigation";
import { createClient } from "@/lib/supabase/client";

const REFRESH_MS = 30_000;
// Never let refreshes fire more often than this — prevents any refresh loop.
const MIN_INTERVAL_MS = 5_000;

// Keeps the dashboard current. Data reads are already cache-free
// (cache: no-store + force-dynamic), so every render is fresh; this just
// adds periodic auto-refresh plus a realtime nudge when available.
export function RealtimeRefresher() {
  const router = useRouter();
  const lastRefresh = useRef(0);

  useEffect(() => {
    const refresh = () => {
      const now = Date.now();
      if (now - lastRefresh.current < MIN_INTERVAL_MS) return;
      lastRefresh.current = now;
      router.refresh();
    };

    const onFocus = () => refresh();
    window.addEventListener("focus", onFocus);
    const id = setInterval(refresh, REFRESH_MS);
    return () => {
      window.removeEventListener("focus", onFocus);
      clearInterval(id);
    };
  }, [router]);

  useEffect(() => {
    const supabase = createClient();
    const refresh = () => {
      const now = Date.now();
      if (now - lastRefresh.current < MIN_INTERVAL_MS) return;
      lastRefresh.current = now;
      router.refresh();
    };

    const channel = supabase
      .channel("dashboard-live")
      .on(
        "postgres_changes",
        { event: "*", schema: "public", table: "transactions" },
        refresh,
      )
      .on(
        "postgres_changes",
        { event: "*", schema: "public", table: "phone_models" },
        refresh,
      )
      .on(
        "postgres_changes",
        { event: "*", schema: "public", table: "stock_adjustments" },
        refresh,
      )
      .on(
        "postgres_changes",
        { event: "*", schema: "public", table: "swapped_phones" },
        refresh,
      )
      .subscribe();

    return () => {
      supabase.removeChannel(channel);
    };
  }, [router]);

  return null;
}