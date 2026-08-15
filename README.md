# Mr Jeff Stock

Multi-shop phone stock & sales management built for a Ghana iPhone reseller. Track inventory, sales, swaps, and repairs across multiple shop locations from any phone browser — in real time.

Built with [Next.js](https://nextjs.org) (App Router, React 19) and [Supabase](https://supabase.com) (Postgres, Auth, Row Level Security).

---

## Features

- **Multi-shop support** — independent stock per shop, with a cross-shop roll-up dashboard for the owner.
- **Three transaction types** — `sale`, `swap`, and `repair`.
  - Swaps move stock both ways: the customer's phone comes in as `used` stock, the shop's phone goes out, and a cash top-up is recorded.
- **Remote, realtime visibility** — the owner can check any shop's stock and daily activity from anywhere.
- **Model-level stock tracking** — stock is a count per model (e.g. "iPhone 13 128GB"), not per-unit/IMEI.
- **Daily closing report** — units out per model, broken down by sale vs swap, per shop.
- **Role-based access** — owners see and manage everything; shop attendants are scoped to their own shop and cannot edit or delete past transactions.
- **Reports** — filterable by shop, date range, payment method, and type; exportable.
- **Bulk device import** — add many phone models to a shop at once by pasting CSV rows or uploading a file (Settings → Bulk add devices). Duplicates in the shop are skipped automatically.
- **Backup & restore** — download a full JSON backup of all shops, devices, transactions, and adjustments, and restore it later (Settings → Backup & restore). Restore is atomic: a bad file rolls back completely, and stock counts are preserved exactly.

## Stock integrity (enforced by the database)

`available` stock is derived and can never be hand-edited or go below 0:

```
available = opening_stock + bought_in + Σ(swap-ins) − Σ(sales + swap-outs)
```

- Sales and swap-outs decrease stock; swap-ins increase it; repairs move nothing.
- Restocking and corrections go through an audited `stock_adjustments` table.
- Transactions are recorded atomically via a Postgres RPC — a swap can never leave a half-written transaction.

## Tech Stack

| Layer | Technology |
|---|---|
| Frontend / Backend | Next.js 16 (App Router), React 19, Tailwind CSS 4 |
| Database | Supabase (Postgres) |
| Auth | Supabase Auth + RLS policies scoped by `shop_id` |
| Realtime | Supabase realtime subscriptions |
| Language | TypeScript |

## Getting Started

### Prerequisites

- Node.js 20+ and npm
- A Supabase project ([create one here](https://supabase.com), free tier works)

### 1. Set up the database

Open the Supabase Dashboard → **SQL Editor** → **New query**, then paste and run the contents of `supabase/schema.sql`.

This creates the tables, RLS policies, triggers, and helper RPCs (`record_transaction`, `adjust_stock`, `delete_transaction`, etc.).

### 2. Configure environment variables

Copy `.env.local.example` to `.env.local` and fill in:

| Variable | Description |
|---|---|
| `NEXT_PUBLIC_SUPABASE_URL` | Your project URL (Project Settings → API) |
| `NEXT_PUBLIC_SUPABASE_ANON_KEY` | Your anon/public key |
| `SUPABASE_SERVICE_ROLE_KEY` | Server-only key, used to create staff accounts from Settings |
| `OWNER_SETUP_SECRET` | A secret phrase that guards the one-time owner setup at `/setup` |

> Never expose `SUPABASE_SERVICE_ROLE_KEY` to the client. It is only used in server-side code.

### 3. Install and run

```bash
npm install
npm run dev
```

### 4. Create the owner account

There is no public sign-up — the owner account is created once, by you:

1. Set `OWNER_SETUP_SECRET` in `.env.local` to any passphrase.
2. Open [http://localhost:3000/setup](http://localhost:3000/setup) and enter the passphrase plus the owner's name, email, and password.
3. Sign in, then add shops and staff from **Settings** (staff accounts are created by the owner and cannot sign up on their own).

### Scripts

```bash
npm run dev     # Start the dev server
npm run build   # Production build
npm run start   # Run the production build
npm run lint    # Lint with ESLint
```

## Roles

| Action | Owner | Attendant |
|---|---|---|
| View all shops | Yes | No (own shop only) |
| Record transactions | Yes | Yes (own shop only) |
| Edit / delete past transactions | Yes | No |
| Add / remove shops & staff | Yes | No |
| Restock / adjust stock | Yes | Yes (own shop only) |

## Project Structure

```
src/
  app/
    (app)/            # Authenticated pages (dashboard, shops, reports, settings)
    (auth)/           # Login & one-time owner setup
    reports/export/   # CSV export route
  components/         # UI components & forms
  lib/
    actions.ts        # Server actions
    data.ts           # Data access
    supabase/         # Client & server Supabase clients
supabase/
  schema.sql          # Source of truth for the database schema
```

Full design rationale and implementation decisions live in [`design.md`](design.md).

## Keeping Supabase awake on the free tier

Supabase free-tier projects auto-pause after ~1 week with no API activity. A scheduled GitHub Action (`.github/workflows/keep-supabase-awake.yml`) is included to keep it alive. Add `SUPABASE_PROJECT_URL` and `SUPABASE_ANON_KEY` as repository secrets to enable it — or upgrade to Supabase Pro at launch.

## Roadmap

- [x] Multi-shop stock, sales, swaps, and repairs
- [x] Owner dashboard with cross-shop roll-up and daily closing
- [x] Role-based access (owner / attendant)
- [x] CSV report export
- [ ] Low-stock alerts / notifications
- [ ] Revenue breakdown by payment method

---

© Mr Jeff Stock. Developed for internal business use.
