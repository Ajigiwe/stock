"use client";

import { useEffect } from "react";
import { useRouter } from "next/navigation";
import { createClient } from "@/lib/supabase/client";

const REFRESH_MS = 30_000;

// Keeps the dashboard current. Supabase Realtime's INSERT/UPDATE events aren't
// reliably delivered with these RLS policies (only DELETE events come through),
// so this also refreshes on mount, when the tab regains focus, and on a timer —
// guaranteeing the numbers are never stale.
export function RealtimeRefresher() {
  const router = useRouter();

  useEffect(() => {
    const refresh = () => router.refresh();

    refresh();
    window.addEventListener("focus", refresh);
    const id = setInterval(refresh, REFRESH_MS);
    return () => {
      window.removeEventListener("focus", refresh);
      clearInterval(id);
    };
  }, [router]);

  useEffect(() => {
    const supabase = createClient();
    const channel = supabase
      .channel("dashboard-live")
      .on(
        "postgres_changes",
        { event: "*", schema: "public", table: "transactions" },
        () => router.refresh(),
      )
      .on(
        "postgres_changes",
        { event: "*", schema: "public", table: "phone_models" },
        () => router.refresh(),
      )
      .on(
        "postgres_changes",
        { event: "*", schema: "public", table: "stock_adjustments" },
        () => router.refresh(),
      )
      .on(
        "postgres_changes",
        { event: "*", schema: "public", table: "swapped_phones" },
        () => router.refresh(),
      )
      .subscribe();

    return () => {
      supabase.removeChannel(channel);
    };
  }, [router]);

  return null;
}