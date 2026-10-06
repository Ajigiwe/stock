# Mr Jeff Stock — PHP + MySQL Migration Plan

**Target:** Laravel (Blade + Alpine/htmx) on shared cPanel hosting, full feature parity with the Next.js 16 + Supabase app.

**Ground rules carried over from the current app:**
1. Stock integrity is enforced by the database, not the UI.
2. Fail closed on every authorization check.
3. Every mutation is atomic and idempotent where the client can retry.

---

## 1. Target architecture

```
Browser (PWA — sw.js + manifest kept as-is)
  Blade (server-rendered) + Alpine.js (state) + htmx (partial updates, polling)
        |
Laravel 11/12 (PHP 8.2+)
  routes/web.php        → replaces src/proxy.ts (auth redirects) + App Router pages
  app/Http/Controllers  → replaces src/lib/actions.ts (28 server actions)
  app/Services          → replaces the 14 Postgres RPCs (transaction orchestration)
  app/Policies + Scopes → replaces Row Level Security
  Blade views           → replaces src/components/*
  Laravel Cache (file)  → replaces unstable_cache + updateTag tags
        |
  MySQL 8 / MariaDB 10.6+
    InnoDB transactions + row locks + 5 triggers + CHECK constraints
    (replaces plpgsql RPCs + triggers)
```

### Hard hosting requirements (verify on the cPanel account **before** writing code)

| Requirement | Why |
|---|---|
| PHP ≥ 8.2 (`pdo_mysql`, `mbstring`, `openssl`, `fileinfo`, `ctype`, `tokenizer`, `xml`, `curl`, `zip`) | Laravel 11/12 baseline |
| MySQL 8.0+ **or** MariaDB 10.6+ | CHECK constraints are *enforced* (8.0.16+); MySQL 5.7 silently ignores them, which would break the `available >= 0` invariant |
| Composer available (CLI terminal or cPanel plugin) | Build `vendor/` locally and upload if the host has no CLI |
| Document root can point at `public/` | Standard Laravel layout; never expose `vendor/`, `.env` |
| OPcache enabled | No persistent process on shared hosting |

Out of scope by design (shared host constraints): Redis, queues, websockets, Docker.

---

## 2. Concept mapping — Supabase/Postgres → Laravel/MySQL

| Current (Next.js + Supabase) | Replacement |
|---|---|
| `src/proxy.ts` (auth redirects, deactivation check) | `app/Http/Middleware/Authenticate` + `EnsureActiveUser` middleware; route groups `auth`, `guest`, `owner` |
| Supabase Auth (JWT cookies) | Laravel session auth (cookie sessions, file or database driver) |
| RLS policies (33) | **Authorization in app**: Policies + query scopes + explicit fail-closed checks in every controller (MySQL has no RLS) |
| `requireSession()` / `requireScopedSession()` | `auth()` helper + `Gate`/Policy calls; a `ScopedSession` value object injected per request |
| Server actions (`"use server"`) | POST routes + Form Request validation + CSRF tokens |
| Postgres RPCs (`record_transaction`, `restore_backup`, …) | **Laravel service classes** running `DB::transaction()` + `lockForUpdate()` (see §3) |
| Triggers (`apply_item_stock_change`, `apply_stock_adjustment`, `enforce_*_shop_match`) | **Kept as MySQL triggers** (see §3) |
| `unstable_cache` + `updateTag` | `Cache::remember(key, 30s)` + explicit `Cache::forget()` in a `flushDataCache()` helper called by every mutation (file driver on shared host) |
| Supabase Realtime | htmx polling: `hx-trigger="every 30s"` on dashboard/stock/review panels (no websockets on cPanel) |
| Service-role admin client (`admin.ts`) | Not needed — the app is the *only* DB writer (biggest security win of the port) |
| `idempotency_key uuid UNIQUE` | `CHAR(36) UNIQUE` column; checked inside the transaction before insert |
| Offline queue (`offline-queue.ts`) | Same localStorage FIFO, same shape, posting `fetch()` to `POST /transactions` with `X-Idempotency-Key` |
| CSV export route, backup route | `GET /reports/export`, `GET /settings/backup`, `POST /settings/restore` (streamed) |
| `@tailwind` design tokens (`globals.css @theme`) | Tailwind 4 via npm **or** a pre-built CSS file — see §7 |
| GitHub keep-awake workflow | **Deleted** — no Supabase to pause |
| `OWNER_SETUP_SECRET` one-time `/setup` | Same mechanic: secret-guarded `GET/POST /setup` creating the owner, blocked once an owner exists |

### Postgres → MySQL syntax port checklist

| Postgres | MySQL |
|---|---|
| `create type ... as enum` (6 types) | `ENUM(...)` columns (keeps invalid values out of the DB) |
| `uuid` | `CHAR(36)` (app-generated UUIDv4) or `BINARY(16)` — pick `CHAR(36)` for readable SQL |
| `timestamptz` | `DATETIME` stored in **UTC** (Ghana is UTC+0 year-round — no DST math) |
| `numeric(12,2)` | `DECIMAL(12,2)` (never `FLOAT`) |
| `jsonb` | `JSON` (backup restore logic moves to PHP — §3) |
| `gen_random_uuid()` | generated in PHP before insert |
| `auth.uid()` | `session('user_id')` passed explicitly |
| `on conflict (id) do nothing` | `INSERT IGNORE` / `ON DUPLICATE KEY` |
| `returning id into v_x` | `LAST_INSERT_ID()` |
| `security definer`, `revoke/grant` (41+32 stmts) | Drop — one DB user, no public anon key |
| `handle_new_user()` trigger on `auth.users` | `User::created` observer (or keep a MySQL trigger on `users`) |
| `user_profile_t` composite type | Plain `SELECT role, shop_id INTO ...` |
| `raise exception` | `SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = ...` |
| `plpgsql` blocks (18 functions) | **Not ported as SQL** — moved to Laravel services (§3) |
| `jsonb` operators in `restore_backup` | PHP: decode, validate, insert in chunks |
| `split_part`, `greatest()`, window fns | `SUBSTRING_INDEX`, `GREATEST()`, all fine on MySQL 8 |

---

## 3. The key structural decision: where the logic lives

**Do not port the 18 PL/pgSQL functions 1:1.** MySQL stored procedures are painful to write, debug, test, and deploy on shared hosting. Instead:

### Keep in the database (defense in depth — 5 triggers + CHECK)
- `apply_item_stock_change` → fires on `transaction_items` insert: `available += / -= qty` with a `CHECK (available >= 0)` guard.
- `apply_stock_adjustment` → fires on `stock_adjustments`: positive → `bought_in += delta`; `available` follows.
- `enforce_item_shop_match`, `enforce_adjustment_shop_match` → reject items whose `shop_id` ≠ parent's.
- Derived columns (`available`, `opening_stock`, `bought_in`) stay non-writable by app code — Laravel models never mass-assign them; only triggers touch them.

### Move to Laravel services (single `DB::transaction()` + `SELECT … FOR UPDATE`)
| Postgres RPC | Laravel service |
|---|---|
| `record_transaction` | `TransactionRecorder::record()` — validates stock, locks `phone_models` rows, inserts tx + items + swap-ins, dedupes on idempotency key |
| `review_transaction` / `void_transaction` | `TransactionReviewer::approve()/reject()/void()` (rejection reverses stock via the same item triggers) |
| `adjust_stock` / `bulk_adjust_stock` | `StockAdjuster::apply()` → inserts into `stock_adjustments` (triggers do the math) |
| `approve/reject/approve_all_stock_request` | `StockRequestDecider` |
| `submit/lock_daily_close` | `DailyCloseService` |
| `submit/approve_stock_count`, `apply_stock_count_correction` | `StockCountService` |
| `update_swapped_phone_status` | `SwappedPhoneService` |
| `restore_backup` | `BackupRestorer::restore()` — decode JSON, validate every table, insert in one transaction, roll back entirely on any error |

**Why this is safe:** unlike Supabase (where an anon key could reach Postgres directly), MySQL is only reachable from this Laravel app. InnoDB rolls back the whole statement — including trigger effects — if any part fails. The triggers remain as a backstop against a future bug in app code.

**Why it's better:** transaction logic becomes unit-testable PHP instead of un-debuggable SQL, deployable via git, no `DELIMITER` gymnastics.

---

## 4. Database migration plan

1. **Write Laravel migrations** translating `supabase/schema.sql` + migrations 0001–0004 into ~16 tables. Note `schema.sql` already contains 0004's objects — treat it as the single source, ignore the numbered files except where they add context.
2. **Port the 5 triggers** as `CREATE TRIGGER` statements inside migrations (`DB::unprepared()`).
3. **Port constraints:** `CHECK (available >= 0)`, `UNIQUE (shop_id, model_name, condition)`, `UNIQUE (idempotency_key)`, FK `ON DELETE` behavior (mostly `RESTRICT`, matches current `CASCADE` on auth user removal).
4. **Indexes:** copy the 14 named indexes verbatim (they're the query paths the app uses).
5. **Seeders:** a `--demo` seeder (2 shops, 8 models, 40 transactions) for local/dev testing.
6. **Data migration from live Supabase (if any data exists):** the app already has a full JSON backup export. Reuse that exact JSON shape and write `php artisan mrjeff:import-backup` — this is the same parser as `restore`, so UAT exercises it too.

---

## 5. Feature inventory (parity checklist)

### Routes (from the 13 current pages)
```
GET  /login, POST /login, POST /logout
GET  /setup (secret-guarded, one-time owner bootstrap)
GET  /                      dashboard (owner roll-up + attendant shop view)
GET  /shops/{id}            shop page (stock, day's txs, recon, close, count)
GET  /transactions/new      POS form (two-column, sale/swap/repair)
GET  /transactions/{id}     detail + review/void + receipt actions
GET  /devices               device/model management + bulk add (CSV paste/upload)
GET  /reports               filters + GET /reports/export (CSV, formula-injection safe)
GET  /settings              shops, staff, bulk models, backup/restore, stock editing
GET  /logs                  login + stock logs (owner)
GET  /account               profile + change password
GET  /settings/backup       JSON download
POST /settings/restore      atomic JSON restore
```

### Server actions (28 → controllers/services)
Auth: `login, logout, changePassword, setupOwner`
Transactions: `recordTransaction, reviewTransaction, voidTransaction, updateSwappedPhoneStatus`
Stock: `createModel, updateModel, adjustStock, bulkAdjustStock, bulkCreateModels`
Requests: `approveStockRequest, rejectStockRequest, approveAllStockRequests`
Shops/Staff: `createShop, deleteShop, createStaff, deactivateStaff, reactivateStaff, resetStaffPassword`
Reconciliation: `submitDailyClose, lockDailyClose, submitStockCount, approveStockCount, applyStockCountCorrection`
Backup: `restoreBackup`

### Cross-cutting behaviors to preserve
- Owner creates staff (no public signup); deactivation signs the user out immediately (port the `app_metadata.deactivated` check → `users.active` checked in middleware on every request).
- Below-list-price sales land in `pending_review` until the owner approves/rejects.
- Audit: every mutation writes a `transaction_events` / `stock_logs` row.
- Receipt/share summary text (WhatsApp-formatted) — keep the same string builders.
- Offline queue with idempotency keys; auto-sync on reconnect.
- PWA: `sw.js` (network-first navigations, precached shell), `manifest.webmanifest`, icons — all unchanged.
- Fail-closed scoping: attendant redirected away from any shop id ≠ their own.
- CSV formula-injection escaping (`=+-@` prefix) — already solved, port the `esc()` verbatim.

---

## 6. Phased delivery

| # | Milestone | Contents | Done when |
|---|---|---|---|
| **M0** | Foundation | Verify host requirements, Laravel install, Tailwind build, Pest, base Blade layout (sidebar + shop switcher shell), `.env`, git repo/dir | `php artisan serve` shows the shell; CI runs `pint --test` + `pest` |
| **M1** | Database | All migrations + 5 triggers + constraints + indexes + demo seeder | Invariant tests pass: stock can't go negative, shop mismatch rejected, idempotency dedupes |
| **M2** | Auth & RBAC | Session login, middleware (replaces `proxy.ts`), policies, `/setup`, staff provisioning, deactivation, change password | Role table from README reproduced as feature tests |
| **M3** | Core POS | Stock/devices pages, transaction form (sale/swap/repair) incl. swap-ins, offline queue, receipt text | Can sell, swap, repair end-to-end offline-tolerant |
| **M4** | Owner controls | Pending review/void, stock requests, adjustments, stock counts, daily close, logs, audit events | Full fraud-control loop works |
| **M5** | Reports & admin | Report filters, CSV export, backup/restore, settings screens, dashboard charts | Export matches current CSV byte-for-byte on sample data |
| **M6** | Cutover | PWA polish, htmx polling, real data import, UAT on cPanel, DNS/URL cutover | Old app kept live in parallel until UAT sign-off |

Rough sizing for one developer: M0–M1 ~3–4 days, M2 ~2, M3 ~5, M4 ~5, M5 ~3, M6 ~3 — **~3–4 weeks** to full parity.

**Status (2026-10-06):** M0–M5 implemented in this repository — schema + 12
triggers, all 43 routes, services/queries/views/controllers, offline queue,
PWA, CI. Verified by 55 tests (258 assertions) against real MySQL plus a
21-check HTTP smoke of every page as owner and attendant. M6 (real-data
import, cPanel UAT, DNS cutover, timed deletions in §10) is pending host
access and UAT sign-off; the old app stays live until then.

---

## 7. Frontend notes

- **Styling:** install Tailwind 4 in the Laravel app with Vite (cPanel doesn't run Vite — build CSS once locally and commit `public/build/`, or run `npm run build` before each upload). Alternative for zero build step: compile `globals.css` once to a static stylesheet.
- **Interactivity:** Alpine for the form (qty steppers, swap-in rows, model picker filtering), htmx for partial refresh (`#stock-table`, `#daily-summary`, `#review-panel`) and 30s polling.
- **Blade components** mirror `src/components/` 1:1 — `x-stock-table`, `x-transaction-form`, `x-dashboard`, etc. — so the React JSX translates mechanically.
- **Keep as-is:** `public/sw.js`, `public/manifest.webmanifest`, icons, `format.ts`→`format.php` (money/date helpers).

---

## 8. Testing strategy (closes the current "0 tests" gap)

- **Pest** + factories + `RefreshDatabase`.
- **DB-level invariant tests (highest value):**
  - sale beyond available stock → rejected, no partial writes
  - swap writes both directions atomically; forced failure rolls back both
  - duplicate idempotency key returns the original transaction id
  - `stock_adjustments` negative/positive delta math
  - `CHECK (available >= 0)` fires from raw SQL, bypassing the app
- **Authorization tests:** every route × {owner, attendant, wrong-shop attendant, deactivated user} → expected allow/redirect (mirrors the README role table).
- **Parity checklist:** a spreadsheet of the current app's observable behaviors, ticked off during UAT.

---

## 9. Risks & mitigations

| Risk | Mitigation |
|---|---|
| Host runs MySQL 5.7 (CHECK ignored) | Verify in M0 with `SELECT VERSION()`; require MariaDB 10.6+ or change host |
| Authorization regression now that RLS is gone | Policies + explicit scope in every query + route-level tests; never accept a shop id from the client without comparing it to the session profile |
| Logic drift between old RPC and new service | Port one RPC at a time, run the same scenario against both apps during UAT |
| Shared-host perf (no persistent PHP) | OPcache, avoid N+1 (eager-load), 30s cache on owner reads, keep page count of queries similar to today |
| CSV import / backup restore hitting `max_execution_time` | Chunked inserts; stream the backup file; document raising `max_execution_time` in `.user.ini` |
| Losing Supabase Realtime → stale dashboards | htmx 30s polling on the 3 panels that benefit; acceptable for a shop-floor tool |
| Data loss at cutover | Old app stays live; export backup → import → row-count + totals reconciliation report before switching |
| Composer/vendor unavailable on host | `composer install --no-dev -o` locally, upload `vendor/` (standard cPanel practice) |

## 10. What gets deleted

`supabase/` (after import), `src/` (after UAT), `.github/workflows/keep-supabase-awake.yml`, all `NEXT_PUBLIC_SUPABASE_*` / service-role env vars, `next.config.ts` staleTimes workaround, `design.md` → rewritten as the Laravel design doc.
