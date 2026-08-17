import "server-only";

import { createClient as createAdminClient } from "@supabase/supabase-js";
import type { Database } from "@/lib/database.types";

function adminEnv() {
  const url = process.env.NEXT_PUBLIC_SUPABASE_URL;
  const key = process.env.SUPABASE_SERVICE_ROLE_KEY;
  if (!url || !key) {
    throw new Error("SUPABASE_SERVICE_ROLE_KEY not configured on the server.");
  }
  return { url, key };
}

export function getAdminClient() {
  const { url, key } = adminEnv();
  return createAdminClient<Database>(url, key, {
    auth: { autoRefreshToken: false, persistSession: false },
    global: {
      fetch: (input, init) =>
        fetch(input, { ...init, cache: "no-store" }),
    },
  });
}

// Stateless admin client used inside `unstable_cache`. Reads are cached by
// Next, so we leave fetch at its default (uncached) behaviour — the cache lives
// at the function level, not the fetch level.
export function getCachedAdminClient() {
  const { url, key } = adminEnv();
  return createAdminClient<Database>(url, key, {
    auth: { autoRefreshToken: false, persistSession: false },
  });
}
