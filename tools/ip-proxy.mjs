// Local CONNECT proxy that routes outbound TLS through reachable IPs.
//
// Why this exists: on this network, DNS hands back GitHub edge IPs that are
// firewalled (api.github.com -> 140.82.121.5 times out) while other edges of
// the same host are reachable (140.82.113.5 answers 200). Composer/curl pin
// whatever DNS returned and fail. This proxy keeps TLS end-to-end (plain
// CONNECT tunnel, no MITM) but picks the IP itself: pinned candidates first,
// then every other A record, with a short connect timeout per attempt.
//
// Usage:  node tools/ip-proxy.mjs 8888
// Then:   set https_proxy=http://127.0.0.1:8888  (what Composer reads)

import net from "node:net";
import http from "node:http";
import dns from "node:dns";

const PORT = Number(process.argv[2] || 8888);
const CONNECT_TIMEOUT_MS = 6000;

// Hosts whose DNS answer is known-bad on this network, with edges that work.
const PINNED = {
  "api.github.com": ["140.82.113.5", "140.82.121.10"],
  "github.com": ["140.82.113.5", "140.82.121.10"],
  "codeload.github.com": ["140.82.121.10"],
};

const lookupAll = (host) =>
  new Promise((resolve) => {
    dns.lookup(host, { all: true, family: 4 }, (err, addrs) =>
      resolve(err ? [] : addrs.map((a) => a.address)),
    );
  });

async function candidates(host) {
  const pinned = PINNED[host] || [];
  const rest = (await lookupAll(host)).filter((ip) => !pinned.includes(ip));
  return [...pinned, ...rest];
}

/** Connect to host:port, trying each candidate IP in turn. */
function connectTunnel(host, port, onReady) {
  const tried = new Set();
  let done = false;

  const attempt = async () => {
    if (done) return;
    const ips = await candidates(host);
    const next = ips.find((ip) => !tried.has(ip));
    if (!next) {
      if (!done) onReady(new Error(`no reachable IP for ${host}`));
      done = true;
      return;
    }
    tried.add(next);

    const sock = net.connect({ host: next, port, timeout: CONNECT_TIMEOUT_MS });
    sock.once("connect", () => {
      if (done) return sock.destroy();
      done = true;
      onReady(null, sock, next);
    });
    sock.once("timeout", () => sock.destroy());
    sock.once("error", () => {
      if (!done) void attempt(); // next candidate
    });
  };

  void attempt();
}

const server = http.createServer((req, res) => {
  // Plain HTTP proxying (Composer normally uses https, so this is a fallback).
  const [host, port] = [req.headers.host, 80];
  connectTunnel(host, port, (err, sock, ip) => {
    if (err) {
      res.writeHead(502).end(`proxy: ${err.message}`);
      return;
    }
    const head = `${req.method} ${req.url} HTTP/1.1\r\n` +
      Object.entries(req.headers)
        .map(([k, v]) => `${k}: ${Array.isArray(v) ? v.join(", ") : v}`)
        .join("\r\n") + "\r\n\r\n";
    sock.write(head);
    req.pipe(sock);
    sock.pipe(res);
    sock.once("error", () => res.destroy());
  });
});

server.on("connect", (req, clientSocket, head) => {
  const idx = req.url.lastIndexOf(":");
  const host = req.url.slice(0, idx);
  const port = Number(req.url.slice(idx + 1));

  connectTunnel(host, port, (err, upstream, ip) => {
    if (err) {
      clientSocket.write("HTTP/1.1 502 Bad Gateway\r\n\r\n");
      clientSocket.destroy();
      return;
    }
    clientSocket.write("HTTP/1.1 200 Connection Established\r\n\r\n");
    if (head?.length) upstream.write(head);
    upstream.pipe(clientSocket);
    clientSocket.pipe(upstream);
    console.log(`tunnel ${host}:${port} via ${ip}`);
    upstream.once("error", () => clientSocket.destroy());
  });

  clientSocket.once("error", () => {});
});

server.listen(PORT, "127.0.0.1", () => {
  console.log(`ip-proxy listening on 127.0.0.1:${PORT}`);
});
