# Mr Jeff Stock — System Design Document (Laravel/MySQL)

This is the design document for the **PHP rewrite**. The original Next.js 16 +
Supabase design it replaces is preserved in git history; its behaviour —
including every user-facing string — remains the specification
(`PORTING-CONTRACT.md` binds the build, `PLAN-php-migration.md` the phases).

---

## 1. Architecture Overview

A server-rendered Laravel 12 monolith: Blade views + Alpine.js (no SPA, no
client-side data fetching), MySQL 8 / MariaDB 10.6+ for storage, file-based
sessions/cache, sync queue — everything runs on shared cPanel hosting with no
Redis, no queue workers, no websockets.

Request lifecycle:

```
HTTP → middleware (setup.state | auth | active | throttle)
     → controller (thin: merge request + route ids, call one service/query)
     → service (validation, DB::transaction + lockForUpdate, idempotency)
        ↳ or query class (fail-closed scoping, DataCache)
     → respond()  — JSON {ok,...} for expectsJson(), else redirect + flash
     → Blade view (layout + content section, Alpine for interactivity)
```

Three hard rules carry over from the original:

1. **`phone_models.available` is never written by application code** — twelve
   MySQL triggers derive it; overselling raises a `SIGNAL` whose message is
   shown verbatim to the user.
2. **Every mutation runs inside `DB::transaction()`** with the touched model
   rows locked `FOR UPDATE` in id order (deadlock avoidance), the port of the
   Postgres RPCs.
3. **Authorization fails closed** — owner-only paths re-check the role
   server-side on every request; attendants are scoped to their own shop by
   the query layer, never by trusting a client-supplied shop id.

## 2. Tech Stack

| Layer | Choice | Notes |
|---|---|---|
| Framework | Laravel 12 (PHP ≥ 8.2) | `pdo_mysql` required |
| Database | MySQL 8 / MariaDB 10.6+ (utf8mb4) | CHECK constraints + triggers; SQLite cannot run them, tests use real MySQL |
| Views | Blade + Tailwind 4 (Vite) + Alpine.js | `public/build/` committed; host needs no Node |
| Sessions / cache / queue | file / file / sync | `.env` values, no Redis |
| PWA | `public/sw.js` + `manifest.webmanifest` | ported unchanged |
| CI | GitHub Actions (MySQL 8 service) | `pint --test` + `php artisan test` |

## 3. Environment Variables

`.env.example` documents all of them. Application-specific:

- `OWNER_SETUP_SECRET` — one-time owner creation at `/setup` (no owner row ⇒
  every page redirects there; the route dies once an owner exists).
- `APP_URL`, `DB_*` — standard.
- **There is no `.env.local`** — Laravel 12 loads `.env.{APP_ENV}`; the
  Next.js `.env.local` convention does not apply (`.env.local.nextbackup` is
  the gitignored original, kept only as reference).

## 4. Database Schema (14 business tables + 6 infrastructure)

Raw DDL in `database/migrations/0000_00_00_000001_create_mrjeff_tables.php`
(`supabase/schema.sql` was the single source of truth for the port).

| Table | Purpose / notable columns |
|---|---|
| `shops` | `name`, `location` |
| `users` | `role` ENUM, `shop_id` (SET NULL, null ⇒ owner), `active`, `deactivated_at/by`; **no credentials** — auth was passwordless in the original, the port keeps email+bcrypt password (see §9) |
| `phone_models` | per-shop stock row: `model_name`, `condition`, `cost_price`, `sale_price`, `opening_stock`, `bought_in`, `low_stock_threshold`, **`available` (derived)**; UNIQUE `(shop_id, model_name, condition)` |
| `transactions` | `type`, `payment_method`, `amount`, `customer_name/phone`, `date` (business day, anchored 12:00 UTC), `status`, `idempotency_key` (UNIQUE) |
| `transaction_items` | `direction` out/in, `qty`, link to model; movements the triggers apply |
| `stock_adjustments` | owner's direct corrections: `delta` (≠ 0), `reason`, `type` restock/correction |
| `stock_requests` | attendant's pending asks: `type` create_model/adjust_stock, `status`, `delta`, reason, snapshot fields |
| `swapped_phones` | swap-in inventory with its own `status` lifecycle |
| `login_logs` | successful sign-ins (email, ip, user agent, device) |
| `stock_logs` | append-only audit of every stock-relevant action |
| `transaction_events` | append-only history: review/void with actor + reason |
| `daily_closes` | per-shop/day totals, `locked_at` |
| `stock_counts` + `stock_count_items` | physical counts and per-model expected/actual |

Infrastructure (created by Laravel defaults): `cache`, `cache_locks`, `jobs`,
`job_batches`, `failed_jobs`, `migrations`.

FK delete rules are mostly `RESTRICT`; `users.shop_id` is `SET NULL`, matching
the original's auth-user removal semantics.

## 5. Enums & CHECK Constraints

- `users.role`: `owner | attendant` (default `attendant`)
- `phone_models.condition`: `new | used`
- `transactions.type`: `sale | swap | repair`
- `transactions.payment_method`: `cash | mobile_money | card | bank_transfer | other`
- `transactions.status`: `completed | pending_review | voided | rejected`
- `transaction_items.direction`: `out | in`
- `stock_adjustments.type`: `restock | correction`, `CHECK (delta <> 0)`
- `stock_requests.type`: `create_model | adjust_stock`; `status`: `pending | approved | rejected`
- Prices/quantities: `CHECK (>= 0)` everywhere, `available >= 0`,
  `transaction_items.qty > 0`.

## 6. Database Triggers (12)

`database/migrations/0000_00_00_000002_create_stock_triggers.php`:

| Trigger | When | Job |
|---|---|---|
| `model_stock_normalize` | BEFORE INSERT `phone_models` | derives `available` from opening/bought-in; honours the restore flag (`@mrjeff_no_stock_effects`) so a backup restore may choose it |
| `item_shop_match` / `_upd` | BEFORE INSERT/UPDATE `transaction_items` | item's model must exist and belong to the transaction's shop |
| `adjustment_shop_match` / `_upd` | BEFORE INSERT/UPDATE `stock_adjustments` | same guard for adjustments |
| `request_shop_match` | BEFORE INSERT `stock_requests` | same guard for requests |
| `item_stock_change_ai/_au/_ad` | AFTER INSERT/UPDATE/DELETE `transaction_items` | applies ±qty to `available`; **`SIGNAL` 'Insufficient stock: only N available for this model'** on oversell; skipped when `@mrjeff_no_stock_effects = 1` |
| `stock_adjustment_change_ai/_au/_ad` | AFTER INSERT/UPDATE/DELETE `stock_adjustments` | applies ±delta; **`SIGNAL` 'Insufficient stock to correct: only N available'** |

Invariant (also re-checked by the backup restore):
`available = opening_stock + bought_in + Σ(in) − Σ(out) − Σ(|negative adjustments|)`.

## 7. Service Layer (ports of the 18 PL/pgSQL functions)

Services live in `app/Services/`; every public method takes the **input array
exactly as the original action did** (field names in `PORTING-CONTRACT.md` §5b)
and returns `['ok' => bool, 'error'? => string, ...]` with the original
messages verbatim.

| Service | Functions ported |
|---|---|
| `TransactionService` | `record_transaction`, `review_transaction`, `void_transaction`, `update_swapped_phone_status` |
| `StockService` | `adjust_stock`, `bulk_adjust_stock`, model create/update (incl. attendant → request) |
| `StockRequestService` | `approve_stock_request`, `reject_stock_request`, `approve_all_stock_requests` |
| `ShopService` | `submit_daily_close`, `lock_daily_close`, `submit_stock_count`, shop create/delete |
| `ReconciliationService` | `submit_stock_count` reconciliation, `apply_stock_count_correction`, count approve/apply |
| `StaffService` | create / deactivate / reactivate / reset password (owner only) |
| `BackupService` | `restore_backup` + the settings backup download (same 6 tables as the original export) + `wipe` (empty all business tables, keep accounts, typed `WIPE` confirm) |
| `AuditLogService` | `stock_logs` / `transaction_events` / `login_logs` writes |

Patterns every write path follows:

- `DB::transaction()` around the whole operation; model rows
  `lockForUpdate()` sorted **by id** (deadlock avoidance, port of the RPCs).
- Input through `App\Support\Input` (money/qty/date/uuid parsing — port of
  `parseMoney`, `parseTxDate`, …), errors returned with the original wording.
- `idempotencyKey` (client-generated UUID) checked before inserting: a retry
  returns the existing transaction instead of deducting stock twice. The
  `transactions.idempotency_key` UNIQUE column is the backstop.
- `QueryException` → `dbError()` extracts `errorInfo[2]` — the clean SIGNAL
  message — so users see exactly what Postgres used to say.
- `App\Support\Format` for all output formatting (money, number, date/time,
  dash) — no hand-rolled formatting anywhere.

## 8. Authorization (replaces Row Level Security)

RLS had no direct equivalent in Laravel, so the port is layered:

1. **Middleware** (`app/Http/Middleware/`, aliased in `bootstrap/app.php`):
   - `setup.state` — no owner ⇒ force `/setup`; owner exists ⇒ bounce
     signed-in users off `/setup` & `/login` (port of `src/proxy.ts`).
   - `auth` — session required, else redirect `/login`.
   - `active` — deactivated users are signed out immediately (403 path in
     mid-session requests).
   - `throttle:30,1` on login as an IP backstop; the **8 attempts / 10 min
     per ip+email** limit lives in `AuthController@login` via `RateLimiter`,
     with the original message.
2. **Owner-only GETs** throw `AccessDeniedHttpException` (403) from the query
   class before any view renders (settings, logs, devices) — verified by the
   HTTP smoke as both roles.
3. **Owner-only writes** are re-checked inside each service
   (`$me->role !== 'owner'` → `['ok' => false, 'error' => 'Only the owner …']`),
   so even a crafted POST cannot bypass it.
4. **Attendant scoping** happens in the query layer: every list query pins
   `shop_id` to the session user's shop and **fails closed** when it is null;
   cross-shop URLs return 403/404, never data. The client-supplied `shopId`
   is always compared against the session profile before use.
5. **Staff capabilities** (`perm_approve_requests`, `perm_adjust_stock`,
   `perm_reconcile` on `users`, owner-granted in Settings) lift specific
   owner-only writes for one shop only: services re-check the flag *and*
   the row's `shop_id` against the holder's, and the shop page gates the
   matching controls on `$canApproveRequests` / `$canReconcile` /
   `$canEditStock`. Settings, staff, backup, wipe, reviews and voids stay
   owner-only.

## 9. Authentication and Roles

- Email-or-phone-number + password (bcrypt) session login — the original was
  magic-link passwordless; the cPanel host has no mail guarantee, so the port
  adds credentials while keeping every role rule identical. `/setup` (guarded by
  `OWNER_SETUP_SECRET`) creates the single owner. Staff accounts need only a
  name + password; email and phone are optional identifiers (`users.phone`,
  unique, server-wins on restore like email).
- Roles: **owner** — everything; **attendant** — own shop's stock, POS,
  own transactions; cannot see settings/logs/devices, cannot adjust stock
  directly (files a request instead), no cross-shop access.
- Deactivation (`StaffService`) flips `active`; middleware ends their session.
- Password change (`AccountController@changePassword`) requires the current
  password; owner can reset a staff password from settings.

## 10. Pages and Routes

46 routes = the 42 in `PORTING-CONTRACT.md` §3 + the `signup` → `login`
redirect the original proxy performed + the 3 post-parity settings additions
(CSV import, template download, data wipe — speced in §3). Inventory:

```
/setup, /login, /logout, signup                 auth (setup.state / auth)
/                                              DashboardController@index  ?period&shop
/shops/{shop}                                  ShopController@show        ?date
POST /shops/{shop}/models | /{model} | /{model}/adjust | /models/bulk   StockController
POST /shops/{shop}/close | /close/{close}/lock | /counts                ShopController
/transactions/new (?type=sale|swap|repair, sidebar-picked), POST /transactions, /{transaction}                   TransactionController
POST /transactions/{transaction}/review | /void, /swapped-phones/{phone}/status
/devices, POST /devices/models/bulk            DeviceController (owner)
POST /requests/{stockRequest}/approve|reject, /requests/approve-all     StockRequestController
/reports, /reports/export, POST /reports/counts/{count}/approve|apply   ReportController
/settings + 12 actions (shops/staff/models/backup/import/wipe)   SettingsController (owner)
/logs (owner), /account, POST /account/password                         Log/AccountController
```

`signup` is registered as a redirect route; everything else is
`Route::middleware(['auth', 'active'])` except the auth group.

## 11. Controllers (thin by design)

`app/Http/Controllers/` — one class per contract section. A controller:

1. merges `$request->except(['_token', '_method'])` with the route-bound ids
   (the service parses the keys it needs — contract §5b),
2. calls exactly one service/query method,
3. returns `respond($request, $result, $redirectTo?)` from the shared base:
   - `expectsJson()` ⇒ JSON: success 200 `{ok: true, …}`, failure 422
     `{ok: false, error}` (what the offline queue reads),
   - otherwise redirect back with `success` flash (or `withErrors(['action'
     => …])`), preserving input; optional `$redirectTo` for flows the
     original pushed elsewhere (POS → shop page).

No `isOwner()` checks in controllers — enforcement lives in middleware,
queries and services so it cannot be forgotten on a new route.

## 12. Query Layer

`app/Services/Queries/` — read models returning view-ready payload arrays:

- `QuerySupport` — shared row builders (`transactions()`, `stock()`,
  `summary()`, `stockRequests()`, …) used by the page classes and by the CSV
  export.
- Page classes: `DashboardQueries`, `ShopQueries`, `TransactionQueries`,
  `DeviceQueries`, `ReportQueries`, `SettingsQueries`, `LogQueries`.
- GET controllers pass the payload straight to `view()`
  (`view('x', $payload)`); views consume the keys verbatim (contract §7).

Scoping is applied inside these classes (§8.4) — a query class never trusts a
filter it did not pin itself.

## 13. Views & Components

`resources/views/` (22 files):

- Layouts: `layouts/app.blade.php` (sidebar + shop switcher + flash/offline
  partials; `@section('content')` convention), `layouts/guest.blade.php`.
- Pages: `dashboard`, `shops/show`, `transactions/new`, `transactions/show`,
  `reports/index`, `settings/index`, `devices/index`, `logs/index`,
  `account/index`, `auth/login`, `auth/setup`.
- Components: `icon`, `dash-card`, `shop-card`, `shop-modal`,
  `shop-recon-card`, `shop-product-modal` (attribute bags, no JS state).
- Partials: `flash` (success / action-error / warnings), `offline-banner`.

Alpine owns all interactivity (qty steppers, filters, modals, sidebar
collapse) as small factories pushed through `@push('scripts')`. **Watch out:
on a Blade component tag `:class` is a PHP expression — Alpine bindings must
be written `x-bind:class`** (a bare Alpine name would be read as a PHP
constant). Blade's `@directive` also needs a non-word character before `@`:
never chain `@endif@if` or end a word directly against `@endif`.

Tailwind 4 theme tokens (`ink`, `paper`, `brand`, `lowstock`, …) live in
`resources/css/app.css` and match the original `globals.css` `@theme`.

## 14. Caching

`App\Support\DataCache` replaces `unstable_cache`: file-store wrapper with a
generation counter (bumped on every mutation) and ~30 s TTL for owner reads.
Query classes cache behind it; mutations never read stale rows because the
write path runs after `DB::transaction()` commits.

## 15. Formatting & Input

- `App\Support\Format` — `money()` (`GHS 1,234.56`), `number()`, `date()`,
  `dateTime()` (time-only for today, Ghana UTC+0 via `gmdate`), `dash()`.
  Unit-tested byte-for-byte against the original `format.ts`.
- `App\Support\Input` — `money()`, `count()`, `qty()`, `txDate()` (business
  day anchored at 12:00 UTC so the calendar day survives timezones),
  `idempotencyKey()`, `isUuid()`, `safeNext()`.

## 16. Offline Behaviour & PWA

- Only repair charges (never move stock) queue offline:
  `resources/js/offline-queue.js` keeps them in `localStorage` with the
  idempotency key and replays oldest-first; `partials/offline-banner.blade.php`
  shows sync state.
- POSTs sent as JSON get `{ok:false, error}` + 422 when rejected, so the
  queue surfaces the original message instead of silently retrying.
- `public/sw.js`: network-first navigations, precached shell/assets, downloads
  (CSV, backup) never cached. `manifest.webmanifest` carries `id`, categories,
  `any` + `maskable` icons and Sale/Swap shortcuts; both layouts link it and
  declare iOS standalone metas. Bump `CACHE` in `sw.js` whenever the precached
  set changes so installed clients refresh.

## 17. Testing (73 tests / 415 assertions, real MySQL)

`phpunit.xml` points `DB_DATABASE` at `mrjeff_test`; `RefreshDatabase`
re-migrates per test because SQLite cannot run the triggers.

| File | Covers |
|---|---|
| `Feature/AuthFlowTest` (17) | setup guard, login/logout, rate limit (8/10 min), deactivation mid-session, auth-page bounce, signup redirect, phone-number sign-in, phone-only staff creation + duplicate guards |
| `Feature/StockTriggerTest` (11) | every invariant: derived `available`, oversell SIGNAL texts, shop-match guards, idempotent restore flag, FK rules |
| `Feature/TransactionFlowTest` (7) | POS form → stock moves → redirect+flash; JSON replay dedupe; oversell on both response shapes; attendant request vs owner adjust; approve; owner-only guard |
| `Feature/ReportExportTest` (3) | CSV bytes (BOM, 10 columns, `esc()` formula prefix, LF joins, filename) + attendant scoping + 403 |
| `Feature/BackupRoundTripTest` (3) | download (raw shape) → `mrjeff:import-backup` / UI upload restore, incl. verbatim error strings |
| `Feature/SettingsImportWipeTest` (6) | CSV import (parse → `bulkCreate` reuse, skip/warn, owner/shop guards, template 403) + data wipe (empty tables, accounts kept, typed `WIPE` confirm, role guard) |
| `Unit/FormatTest`, `Unit/InputTest` | formatting + parsing byte parity |
| `Feature/StaffPermissionsTest` (8) | owner grants/revokes `perm_*` (never on owners, never by staff); granted attendant approves/rejects/approve-alls within their shop, adjusts directly, locks closes and approves/applies counts; cross-shop reads as missing; original refusals intact |
| `Feature/ExampleTest` | signed-out `/` redirects to `/login` |

A scripted HTTP smoke (`21 checks`) additionally runs against
`php artisan serve` with both roles: every page 200, owner-only 403,
cross-shop 403, CSV/backup downloads, signed-out bounce.

## 18. Deployment (cPanel)

1. Build locally (`npm run build`), commit `public/build/` — no Node on host.
2. Upload with web root at `public/` (`public/.htaccess` ships rewrite rules).
3. `.env`: `APP_URL`, `DB_*`, `OWNER_SETUP_SECRET`, file session/cache, sync
   queue; `php artisan key:generate`.
4. `php artisan migrate --force` via SSH/cron.
5. No queue workers, no websockets, no Redis; `schedule:run` cron only if
   scheduled work is ever added.

## 19. Cutover & Deletions (M6)

Old app stays live until UAT sign-off. Status of the plan §10 deletions:

- **Done (2026-10-06):** all Next.js application files — `src/`, `supabase/`,
  `next.config.ts`, `next-env.d.ts`, `tsconfig.json`, `tsconfig.tsbuildinfo`,
  `package.next.json`, `README.nextjs.md`, `eslint.config.mjs`,
  `.env.local.example` / `.env.local.nextbackup`, `postcss.config.mjs`,
  `.next/`, `.freebuff/`, `supabase/.temp/` and the
  `.github/workflows/keep-supabase-awake.yml` workflow. Git history retains
  the original app as the behavioural reference.
- **Pending:** real-data import (`mrjeff:import-backup` with the production
  backup + row-count/totals reconciliation against the old app), cPanel UAT,
  DNS/URL cutover — plan M6.
