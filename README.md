# Mr Jeff Stock

Multi-shop phone stock & sales management for small Ghanaian phone dealers:
per-shop stock, a three-step point of sale (sale / swap / repair), stock
requests, physical counts with reconciliation, daily closes, owner review of
attendant transactions, audit logs and CSV reporting.

This repository is the **PHP rewrite** of the original Next.js 16 + Supabase
app. Feature parity is the goal; the original's behaviour (including error
wording) is the specification — see `PLAN-php-migration.md` (milestones) and
`PORTING-CONTRACT.md` (binding build contract).

## Stack

| Layer | Choice | Why |
|---|---|---|
| Framework | Laravel 12 (PHP 8.2+) | runs on shared cPanel hosting |
| Database | MySQL 8 / MariaDB 10.6+ (utf8mb4) | CHECK constraints + triggers enforce the stock invariant |
| Views | Blade + Tailwind 4 (Vite) + Alpine.js | no SPA, no client-side data fetching |
| Sessions / cache / queue | file / file / sync | no Redis on shared hosting |

## Local setup

Requirements: PHP ≥ 8.2 with `pdo_mysql`, Composer, Node 20+, MySQL or MariaDB.

```bash
composer install
cp .env.example .env          # then set DB_* and OWNER_SETUP_SECRET
php artisan key:generate
php artisan migrate           # 14 business tables + 12 stock triggers
php artisan db:seed           # demo data (2 shops, 9 models, 19 sales)
npm install && npm run build  # compiles resources/css + resources/js
php artisan serve             # http://localhost:8000
```

Demo sign-in after seeding: **owner@example.com / password123** (also
`kofi@example.com`, `ama@example.com` — same password).

A fresh install (no owner row) redirects every page to `/setup`, which
requires `OWNER_SETUP_SECRET` from `.env` and can only ever create one owner.

## Testing

```bash
php artisan test              # Pest/PHPUnit against the mrjeff_test schema
```

Tests run on a real MySQL schema (`mrjeff_test`, configured in `phpunit.xml`)
because SQLite cannot run the triggers and CHECK constraints the data rules
depend on. Re-create it with `DB_DATABASE=mrjeff_test php artisan migrate`.

## How the money stays right

- `phone_models.available` is **never written by application code**. Twelve
  MySQL triggers keep it: normalize-on-insert, shop-match guards, guarded
  stock movements for every `transaction_items`/`stock_adjustments` change,
  and an oversell/over-correction `SIGNAL` that surfaces as a user-facing
  error. The invariant:
  `available = opening_stock + bought_in + Σ(in) − Σ(out) − Σ(|negative adjustments|)`.
- Multi-statement writes run inside `DB::transaction()` with the involved
  model rows locked `FOR UPDATE` in id order (port of the Postgres RPCs).
- Every mutation carries a client-generated `idempotencyKey`; the DB dedupes
  on it so a retried submission cannot deduct stock twice.
- Owners-only actions re-check the role server-side on every request
  (replacing Supabase RLS); attendants are scoped to their own shop by the
  query layer, which fails closed.

## Offline behaviour

Sales and swaps require a live connection. Only **repair charges** (which
never move stock) may be queued on the device — `resources/js/offline-queue.js`
keeps them in `localStorage` with their idempotency key and replays them
oldest-first when the connection returns (`resources/views/partials/offline-banner.blade.php`
shows the state). The service worker (`public/sw.js`) caches the app shell and
static assets only; downloads such as the CSV export and backup are never
cached.

## Deploying to cPanel

1. Build locally (`npm run build`) and commit `public/build/` — the host does
   not need Node.
2. Upload the app with the web root pointed at `public/` (Laravel's standard
   layout; `public/.htaccess` ships with the rewrite rules).
3. Set `.env` (`APP_URL`, `DB_*`, `OWNER_SETUP_SECRET`,
   `SESSION_DRIVER=file`, `CACHE_STORE=file`, `QUEUE_CONNECTION=sync`) and run
   `php artisan migrate --force` from SSH or cron:
   `php /home/USER/app/artisan migrate --force`.
4. Point a cron job at `artisan schedule:run` only if you add scheduled work;
   the app needs no queue workers or websockets.

## Repository notes

- `PLAN-php-migration.md` — the migration plan (M0—M6) this rewrite executes.
- `PORTING-CONTRACT.md` — binding conventions: routes, service/query
  signatures, form field names, view inventory.
- `design.md` — system design document for this Laravel app (the Next.js
  original was rewritten in place per plan §10).
- The original Next.js/Supabase app (`src/`, `supabase/`, `next.config.ts`,
  `tsconfig.json`, `package.next.json`, `README.nextjs.md`, keep-awake
  workflow) was removed in M6 — git history retains it as the behavioural
  reference.
- `README.nextjs.md` / `package.next.json` are the archived originals of the
  files this rewrite replaced.
