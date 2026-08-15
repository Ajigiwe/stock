# Phone Stock Management System — Design Document

## Context

Client sells and swaps iPhones in Ghana across **2–3 shop locations**. The owner
travels frequently and needs to monitor and manage all shops remotely — stock
levels, sales, swaps, and daily activity — without being physically present.

Source reference (original tracking format, informal paper/text log):

```
Sales log: name | phone type | type of sale | qty | phone_no | payment method | amount
Stock log: phone type | stock | bought | available
```

This system digitizes and formalizes that into a multi-shop, cloud-synced
platform with role-based access.

---

## Core Requirements

1. **Multi-shop support** — 2–3 shops, each with independent stock, but
   visible together to the owner.
2. **Remote visibility** — owner can check any shop's stock and activity from
   anywhere, in real time, via phone browser.
3. **Stock tracked at model level** — not per-unit/IMEI. E.g. "iPhone 13 –
   128GB" is a single stock count, not individual serials.
4. **Three transaction types**: Sale, Swap, Repair.
5. **Swaps move stock in both directions** — customer's used phone comes in
   (added to stock as a new/used-condition model entry), shop's phone goes
   out.
6. **Swaps include a cash top-up** — the shop's phone is generally worth more
   than the customer's, so the customer also pays a cash difference. This
   top-up is the amount recorded for swap transactions (not a full sale
   price).
7. **Daily closing report** — at the end of each day, staff/owner need to see
   how many units of each model went out that day, broken down by sale vs
   swap.
8. **Role-based access** — Owner sees everything across all shops; shop
   attendants only see and record for their own shop, and cannot edit or
   delete past transactions (protects log integrity when the owner isn't
   watching).

---

## Data Model

### `shops`
| field | type | notes |
|---|---|---|
| id | uuid | PK |
| name | text | e.g. "Takoradi Market Circle" |
| location | text | free text address/area |
| phone | text | shop contact number |

### `users`
| field | type | notes |
|---|---|---|
| id | uuid | PK, use Supabase Auth |
| name | text | |
| role | enum | `owner`, `attendant` |
| shop_id | uuid FK → shops | null for owner (owner is cross-shop) |

### `phone_models`
Per-shop stock ledger. One row per model **per shop** (same model name can
exist in multiple shops with different stock counts).

| field | type | notes |
|---|---|---|
| id | uuid | PK |
| shop_id | uuid FK → shops | |
| model_name | text | e.g. "iPhone 13 128GB", or "iPhone 11 (used, swap-in)" |
| condition | enum | `new`, `used` — used matters for swap-in phones |
| cost_price | numeric | what the shop paid/valued it at |
| sale_price | numeric | asking price |
| opening_stock | int | baseline count when system started tracking |
| bought_in | int | running total added via restocking |
| available | int | **computed/running balance** — never hand-edited directly; updated only via transactions, so it can't drift out of sync with reality |

### `transactions`
One row per customer interaction.

| field | type | notes |
|---|---|---|
| id | uuid | PK |
| shop_id | uuid FK → shops | |
| staff_id | uuid FK → users | who recorded it |
| customer_name | text | |
| customer_phone | text | customer's contact number |
| type | enum | `sale`, `swap`, `repair` |
| payment_method | enum | cash, mobile money, card, etc. — confirm exact list with client |
| amount | numeric | **for `sale`: full sale price. For `swap`: cash top-up only, not the phone's value.** |
| date | timestamp | |

### `transaction_items`
Line items per transaction — this is what lets a swap move stock two
directions within one transaction record.

| field | type | notes |
|---|---|---|
| id | uuid | PK |
| transaction_id | uuid FK → transactions | |
| phone_model_id | uuid FK → phone_models | |
| direction | enum | `out` (leaving shop stock) or `in` (entering shop stock) |
| qty | int | almost always 1, but support >1 for bulk sales |

**How each transaction type populates `transaction_items`:**
- **Sale**: one `out` row (the phone sold).
- **Swap**: one `out` row (shop's phone to customer) + one `in` row
  (customer's phone into shop stock — if that model doesn't yet exist for
  this shop, create a new `phone_models` row for it, condition = `used`).
- **Repair**: no stock movement by default; only log the transaction record
  and amount charged. (Flag to confirm with client if repairs ever consume
  parts/stock — not currently in scope.)

**Stock update logic**: `available` on `phone_models` is recalculated (or
incremented/decremented via a DB trigger/function) whenever a
`transaction_items` row is inserted — `out` decreases `available`, `in`
increases it. Do not allow direct manual edits to `available` in the UI;
only allow manual **stock intake** (restocking) as its own recorded action
that increments `bought_in` and `available` together.

---

## Screens

### Owner Dashboard (cross-shop, mobile-friendly)
- Stock levels across all shops, side by side
- Today's sales/swaps count and total revenue, per shop
- Low-stock alerts (configurable threshold per model)
- Best-selling models across all shops
- Revenue breakdown by payment method
- **Daily closing summary**: per shop, per model, how many units went out
  today, split by sale vs swap — this should be front-and-center, not buried
  in a reports tab

### Shop View (attendant, scoped to their shop only)
- Current stock table for their shop
- "Record Transaction" form — type (sale/swap/repair), model(s) involved,
  customer details, payment method, amount
  - Swap form specifically needs two model pickers: "phone going out" and
    "phone coming in," plus the top-up amount
- Their shop's daily log / closing summary

### Reports
- Filterable by shop, date range, payment method, transaction type
- Exportable (CSV at minimum)

---

## Roles & Permissions

| action | owner | attendant |
|---|---|---|
| view all shops | yes | no (own shop only) |
| record transaction | yes | yes (own shop only) |
| edit/delete past transaction | yes | **no** |
| add/remove staff | yes | no |
| add/remove shops | yes | no |
| adjust stock (restock) | yes | yes (own shop only) — confirm with client if this should require owner approval |

---

## Tech Stack

- **Frontend/Backend**: Next.js (React) — single web app, responsive, works
  as the owner's remote dashboard from any phone browser without a native
  app build.
- **Database/Auth/Realtime**: Supabase (Postgres) — realtime subscriptions
  so stock updates reflect live across devices; built-in auth for
  owner/attendant roles via Row Level Security (RLS) policies scoped by
  `shop_id`.

### Known issue: Supabase free-tier idle pausing
Supabase free-tier projects auto-pause after ~1 week of zero API activity.
For a live production tool this is a real risk (owner opens dashboard to a
paused DB). Mitigations, in order of recommendation:
1. **Short term / during build**: scheduled keep-alive ping (e.g. free
   GitHub Action cron hitting the project every few days) to prevent
   pausing.
2. **At launch / production**: upgrade to Supabase Pro (~$25/mo) — no
   pausing, better backups and performance. Recommended once this is live
   in the shops and the owner depends on it daily.
3. **Alternative**: self-host Postgres (Railway/Render) to sidestep the
   idle policy entirely, similar cost.

Building agent should implement the keep-alive ping as a safety net
regardless of which DB tier is chosen at launch.

---

## Open Questions for Client (not yet resolved — confirm before finalizing)

- Exact list of accepted payment methods (cash, MoMo provider(s), card?).
- Should repairs ever consume parts/stock, or are they always service-only?
- Should attendant-initiated restocking require owner approval, or is direct
  entry fine?
- Low-stock alert threshold — same for all models, or configurable per
  model?
- Any need for multi-currency (Ghana cedis assumed only)?

---

## Build Priority (suggested order)

1. Supabase schema (tables above + RLS policies for shop-scoped access)
2. Auth + role setup (owner vs attendant login)
3. Transaction recording form (sale/swap/repair) with correct stock-side-effect logic
4. Shop view: stock table + daily closing summary
5. Owner dashboard: cross-shop rollup views
6. Reports/export
7. Keep-alive ping job for Supabase

---

## Resolved During Build (implementation decisions)

These resolve the inconsistencies/open items from above. The running schema is
in `supabase/schema.sql` — treat that as source of truth.

1. **Repairs are service-only.** The customer's phone comes in and goes back
   with them; no stock movement, only the transaction record + charge. The UI
   states this on the repair form.
2. **Stock invariant enforced in the DB.** `available` can never be hand-edited
   and can never go below 0:
   `available = opening_stock + bought_in + Σ(in) − Σ(out)`.
   - `transaction_items` rows drive it via a trigger (`out` decrements, `in`
     increments).
   - Manual intake goes through a separate `stock_adjustments` table (its own
     trigger), so restocks/corrections are audited and never touch
     `available` directly.
   - `record_transaction()` / `adjust_stock()` RPCs do the work atomically and
     raise a friendly error on insufficient stock.
3. **Transactions are recorded atomically** via a Postgres RPC
   (`record_transaction`) instead of multiple client-side inserts — a swap
   can't leave a half-written transaction.
4. **Swap-ins create `used` condition rows** keyed by `(shop_id, model_name,
   condition)`. A swap-in for an unknown model auto-creates the row.
5. **Payment methods**: `cash, mobile_money, card, bank_transfer, other`.
   Adjust the enum in `schema.sql` if the client's list differs.
6. **Low-stock threshold is configurable per model** (`low_stock_threshold`
   column, default 5), not global.
7. **Deleting a transaction** (owner only) reverses its stock effect via the
   item trigger. Edge case: if a swap-in phone was later sold, deleting the
   original swap would push `available` negative and the DB blocks it with a
   clear error — acceptable for now.
8. **Attendant restocking is allowed without owner approval** (per design
   default). Staff can add stock/corrections for their own shop only.
9. **Owner bootstrap**: first signup creates an `attendant` profile with no
   shop. The owner then runs `select public.claim_owner();` (or clicks the
   dashboard button) to activate the owner role. Staff accounts are created by
   the owner in Settings using the server-side service role key.

