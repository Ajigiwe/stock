import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  experimental: {
    // Client-side router cache. `dynamic` pages (this app is force-dynamic)
    // default to 0s in Next 15+, which means every sidebar click re-fetches
    // the whole page from the server — the dominant cost when switching
    // between pages. These values restore segment caching so revisits are
    // instant, while server actions revalidate the matching data cache tags
    // (and their client-cache entries) so numbers stay fresh after a write.
    staleTimes: {
      dynamic: 30,
      static: 300,
    },
  },
};

export default nextConfig;
