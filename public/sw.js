const CACHE = "mrjeff-stock-v5";
const PRECACHE = ["/", "/manifest.webmanifest", "/icon-192.png", "/icon-512.png"];

// Only these may be cached read-through. Navigations are handled separately
// (network-first shell fallback), and everything else — notably the CSV export
// and the backup download — must go straight to the network so a stale copy is
// never served.
const STATIC_ASSET = /\.(?:js|css|png|svg|ico|webmanifest|woff2?|ttf|jpg|jpeg|gif|map)$/i;

self.addEventListener("install", (event) => {
  event.waitUntil(
    caches
      .open(CACHE)
      .then((cache) => cache.addAll(PRECACHE))
      .then(() => self.skipWaiting()),
  );
});

self.addEventListener("activate", (event) => {
  event.waitUntil(
    caches
      .keys()
      .then((keys) =>
        Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))),
      )
      .then(() => self.clients.claim()),
  );
});

self.addEventListener("fetch", (event) => {
  const { request } = event;
  if (request.method !== "GET") return;

  const url = new URL(request.url);
  if (url.origin !== self.location.origin) return;

  // Navigations: network-first, fall back to the cached shell when offline.
  if (request.mode === "navigate") {
    event.respondWith(
      fetch(request)
        .then((res) => {
          const copy = res.clone();
          caches.open(CACHE).then((cache) => cache.put(request, copy));
          return res;
        })
        .catch(() =>
          caches.match(request).then((hit) => hit || caches.match("/")),
        ),
    );
    return;
  }

  // Static assets: cache-first, fill on miss. Anything else (exports,
  // downloads, API calls) bypasses the cache entirely.
  if (!STATIC_ASSET.test(url.pathname)) {
    event.respondWith(fetch(request));
    return;
  }

  event.respondWith(
    caches.match(request).then(
      (hit) =>
        hit ||
        fetch(request).then((res) => {
          if (res.ok) {
            const copy = res.clone();
            caches.open(CACHE).then((cache) => cache.put(request, copy));
          }
          return res;
        }),
    ),
  );
});