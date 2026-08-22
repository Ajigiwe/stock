# Mr Jeff Stock - System Design Document

Multi-shop phone stock and sales management for Ghana phone shops.
Built with Next.js 16.3.1, React 19, Supabase (PostgreSQL + Auth + Realtime), Tailwind CSS 4.

---

## 1. Architecture Overview

```
Browser (PWA)
  Next.js 16 App Router + React 19 + Tailwind v4
        |
Next.js Server (Node)
  Server Components + Server Actions + RSC
  middleware.ts (src/proxy.ts)
  cache: no-store on all Supabase reads
  unstable_cache + updateTag for owner/global reads
        |
  Supabase JS (anon key, RLS)  |  Admin API (service role key, bypasses RLS)
        |
  Supabase PostgreSQL
    10 tables + 7 RPCs + Triggers + RLS + Realtime
```

Key decisions:
- headers() is async (Next.js 16)
- force-dynamic on all data pages
- Server Actions handle all mutations; no API routes
- Stock invariant enforced at DB level via triggers
- Repairs are SERVICE-ONLY: no stock movement

---

## 2. Tech Stack

| Package | Version | Purpose |
|---|---|---|
| next | 16.3.1 | Framework |
| react / react-dom | 19.2.8 | UI |
| @supabase/ssr | ^0.12.4 | Supabase SSR auth |
| @supabase/supabase-js | ^2.112.3 | Supabase client |
| tailwindcss | ^4 | Styling (@theme tokens) |
| typescript | ^5 | Type safety |
| eslint | ^9 | Linting |

---

## 3. Environment Variables

| Variable | Scope | Purpose |
|---|---|---|
| NEXT_PUBLIC_SUPABASE_URL | Client+Server | Supabase project URL |
| NEXT_PUBLIC_SUPABASE_ANON_KEY | Client+Server | Supabase anon key |
| SUPABASE_SERVICE_ROLE_KEY | Server only | Bypasses RLS |
| OWNER_SETUP_SECRET | Server only | Bootstrap owner via /setup |
| SUPABASE_PROJECT_URL | Actions | Keep-awake REST URL |
| SUPABASE_ANON_KEY | Actions | Keep-awake ping |
| SUPABASE_ACCESS_TOKEN | Actions | Management API token |
| SUPABASE_PROJECT_REF | Actions | Project ref |

---

## 4. Database Schema (10 tables)

### shops
id uuid PK, name text, location text, phone text, created_at timestamptz

### users
id uuid PK -> auth.users(id) CASCADE, name text, role user_role, shop_id uuid -> shops(id) SET NULL, can_edit_stock boolean default false, created_at timestamptz
Trigger: handle_new_user() auto-creates row on auth.users insert

### phone_models
id uuid PK, shop_id uuid FK CASCADE, model_name text, condition phone_condition, cost_price numeric(12,2), sale_price numeric(12,2), opening_stock int, bought_in int, available int CHECK>=0 (computed by triggers), low_stock_threshold int default 5, created_at timestamptz
UNIQUE (shop_id, model_name, condition)
Stock invariant: available = opening_stock + bought_in + SUM(in) - SUM(out)

### transactions
id uuid PK, shop_id uuid FK, staff_id uuid FK, customer_name text, customer_phone text, type tx_type, payment_method, amount numeric(12,2), date timestamptz, created_at timestamptz
INDEXES: (shop_id, date DESC), (type)

### transaction_items
id uuid PK, transaction_id uuid FK CASCADE, phone_model_id uuid FK RESTRICT, direction item_direction, qty int CHECK>0

### stock_adjustments
id uuid PK, shop_id uuid FK, phone_model_id uuid FK, staff_id uuid FK, type adjustment_type, delta int, reason text, date timestamptz

### stock_requests
id uuid PK, shop_id uuid FK, staff_id uuid FK, type text (create_model/adjust_stock), status text (pending/approved/rejected), model_name, condition, cost_price, sale_price, low_stock_threshold, opening_stock, phone_model_id FK, delta, reason, created_at, decided_at, decided_by, error_note

### swapped_phones
id uuid PK, shop_id uuid FK, transaction_id uuid FK SET NULL, staff_id uuid FK, model_name text, condition, customer_name, customer_phone, status text (in_stock/sold/returned), notes, created_at

### login_logs
id uuid PK, user_id uuid FK, email, name, ip, user_agent, device (iPhone/iPad/Android/Windows/Mac/Linux), created_at

### stock_logs
id uuid PK, shop_id uuid FK, phone_model_id uuid FK SET NULL, staff_id uuid FK, action text (create_model/update_model/adjust_stock/bulk_create), model_name, condition, details jsonb, created_at

---

## 5. Enums

| Enum | Values |
|---|---|
| user_role | owner, attendant |
| phone_condition | new, used |
| tx_type | sale, swap, repair |
| payment_method | cash, mobile_money, card, bank_transfer, other |
| item_direction | out, in |
| adjustment_type | restock, correction |

---

## 6. Database Triggers

### apply_item_stock_change()
Fires AFTER INSERT/UPDATE/DELETE on transaction_items.
INSERT: checks stock, updates available. DELETE: reverses. UPDATE: reverses old + applies new.

### apply_stock_adjustment()
Fires AFTER INSERT/UPDATE/DELETE on stock_adjustments.
INSERT: checks stock for negative deltas, updates available + bought_in. DELETE: reverses. UPDATE: reverses old + applies new.

---

## 7. RPC Functions (7)

### record_transaction
Atomic: creates transaction + items. Enforces shop scope. Checks stock. Auto-creates new models for in_items. Returns uuid.

### adjust_stock
Checks owner or can_edit_stock. Validates model belongs to shop. Creates adjustment row.

### delete_transaction
Owner only. Cascading delete reverses stock via triggers.

### approve_stock_request
Owner only. Creates model or applies adjustment. Updates status.

### reject_stock_request
Owner only. Sets status to rejected.

### approve_all_stock_requests
Owner only. Iterates pending, applies each, catches errors per-request.

### restore_backup
Owner only. Disables triggers, deletes all data, re-inserts from backup JSON, re-enables triggers.

---

## 8. Row Level Security

Helper: current_user_profile() returns (id, role, shop_id)

| Table | Owner | Attendant |
|---|---|---|
| shops | full access | SELECT own shop |
| users | full access | read own + read same shop |
| phone_models | full access | full access own shop |
| transactions | full access | INSERT + SELECT own shop |
| transaction_items | full access | via parent transaction shop |
| stock_adjustments | full access | INSERT + SELECT own shop |
| stock_requests | full access | INSERT + SELECT own shop |
| swapped_phones | full access | INSERT + SELECT own shop |
| login_logs | SELECT all | SELECT own + INSERT own |
| stock_logs | full access | INSERT + SELECT own shop |

---

## 9. Authentication and Roles

### Owner
- Single account via /setup with OWNER_SETUP_SECRET
- Full CRUD on all data
- Manages shops, staff, stock privileges
- Approves/rejects stock requests
- Access: Dashboard (all), Devices, Reports, Logs, Settings, Account

### Attendant
- Created by owner in Settings > Staff
- Assigned to one shop
- Records transactions for own shop only
- can_edit_stock: true = direct stock editing; false = stock_requests approval flow
- Access: Dashboard (own shop), Record, Shop page, Account

---

## 10. Pages and Routes

| Route | Access | Description |
|---|---|---|
| /login | public | Email/password login |
| /setup | public | Bootstrap owner account |
| / | authenticated | Dashboard with stats, charts, alerts |
| /shops/[id] | authenticated | Shop detail, stock table, transactions |
| /devices | owner | Model x shop matrix |
| /transactions/new | authenticated | 3-step record wizard |
| /transactions/[id] | authenticated | Receipt with share/print |
| /reports | authenticated | Filtered reports + CSV export |
| /logs | owner | Login + stock edit audit logs |
| /settings | owner | Shops, staff, bulk import, backup |
| /account | authenticated | Profile + change password |

---

## 11. Components

### Layout
- app-shell.tsx: Mobile ink header + bottom 4-tab nav + menu sheet. Desktop sidebar.
- shop-switcher.tsx: Owner shop context switcher
- logout-button.tsx: Sign out + redirect

### Dashboard
- dashboard.tsx: Stat cards, charts, recent txs, low stock alerts, pending requests
- dashboard-charts.tsx: Revenue trend + sales by type + top models
- period-toggle.tsx: Today / 7d / 30d toggle
- shop-filter.tsx: Owner shop filter dropdown

### Transaction Recording
- transaction-form.tsx: 3-step wizard (Type -> Phones -> Pay). QtySteppers, multi-line, swap trade-ins, suggested price.
- model-picker.tsx: Autocomplete with stock hints, keyboard nav

### Devices
- devices-table.tsx: Desktop matrix + mobile compact rows, detail modal, sold history

### Stock Management
- stock-table.tsx: Per-shop stock with search/filters
- product-edit-modal.tsx: Edit model + stock adjustment + history
- bulk-stock-modal.tsx: Set target quantities for all models
- add-model-form.tsx: Add new model (direct or via request)

### Settings
- shop-manager.tsx: Create/delete shops
- staff-manager.tsx: Create/remove staff, reset passwords, toggle stock privilege
- backup-restore.tsx: Download/upload JSON backup
- bulk-add-models.tsx: Paste JSON/CSV to import models

### Approval
- stock-requests-panel.tsx: Pending requests, approve/reject/bulk approve

### Swap
- swapped-phones-list.tsx: Trade-in iPhones, status updates

### Receipt
- receipt-actions.tsx: WhatsApp share + copy + print
- delete-transaction-button.tsx: Owner-only delete

### Auth
- auth-forms.tsx: Login form component
- setup-owner-form.tsx: Owner setup form
- change-password-form.tsx: Password change form

### Shared UI (ui.tsx)
- Button, ButtonSecondary, ButtonDanger
- Input, Select, Textarea
- Label, Field (with required indicator)
- Card (with title/subtitle)
- Badge (gray/green/amber/red/blue tones)
- ErrorNote
- EmptyState
- Modal (with title/subtitle, backdrop)
- useToast (success/error/info)
- useConfirm (danger confirmation dialog)

### Feedback (feedback.tsx)
- Toast notifications (success/error/info) with auto-dismiss
- Confirmation dialog with danger variant

---

## 12. Server Actions (src/lib/actions.ts)

### Auth
- login(): Sign in with retry, log login (IP, device, user-agent)
- logout(): Sign out + redirect
- changePassword(): Update own password
- setupOwner(): Create owner account with secret

### Transactions
- recordTransaction(): Validate + call RPC + log swap phones + invalidate cache
- deleteTransaction(): Call RPC + invalidate cache
- updateSwappedPhoneStatus(): Update trade-in status (owner)

### Stock
- createModel(): Direct insert or stock_request for non-privileged
- updateModel(): Edit model details (owner/privileged)
- adjustStock(): Direct RPC or stock_request for non-privileged
- bulkAdjustStock(): Set target quantities, compute deltas, apply or request
- approveStockRequest(): Call RPC
- rejectStockRequest(): Call RPC
- approveAllStockRequests(): Call RPC (bulk)

### Admin
- createShop(), deleteShop()
- createStaff(), removeStaff(), resetStaffPassword()
- setStaffStockPrivilege(): Toggle can_edit_stock
- restoreBackup(): Call RPC
- bulkCreateModels(): Batch insert models (owner)

All actions: validate input, check role, call Supabase, invalidateAllData() via updateTag.

---

## 13. Data Layer (src/lib/data.ts)

### Types
- SessionUser, TransactionWithDetails, DailyRow, ShopDailySummary
- DeviceCell, DeviceSale, DeviceRow, DevicesData
- StockRequestWithDetails, DashboardData, DashboardTotals, DailyPoint
- LoginLog, StockLogEntry

### Direct Queries (with Supabase client)
- getSession(), requireSession()
- getShops(), getStock(shopId?)
- getTransactions(opts), getTransaction(id)
- getDailySummary(shopId, date), getShopSummary(shopId, from, to)
- getAdjustments(shopId?, limit)
- getSwappedPhones(opts)
- getStockRequests(opts)
- getDevicesData(): Aggregated model x shop matrix
- getDashboardData(period, shopFilter): Unified dashboard for owner/attendant
- getLoginLogs(limit), getStockLogs(limit)

### Hydration
- hydrateTransactions(): Enriches transactions with shop names, staff names, item details

---

## 14. Caching Strategy

- DATA_CACHE_REVALIDATE_SECONDS = 30
- DATA_CACHE_TAGS: shops, stock, transactions, requests, adjustments, swaps, logs, users
- getCachedAdminClient(): Service-role client without no-store
- All owner/global reads use unstable_cache with tags
- All mutations call invalidateAllData() -> updateTag for each tag
- Pages: export const dynamic = force-dynamic

Cached functions:
- getCachedShops, getCachedStock, getCachedTransactions
- getCachedStockRequests, getCachedAdjustments, getCachedSwappedPhones
- getCachedShopSummary, getCachedLoginLogs, getCachedStockLogs

---

## 15. Formatting (src/lib/format.ts)

- formatMoney(n): GHS XX.XX
- formatNumber(n): raw number string
- formatDateTime(iso): Smart format (time only if same day, else DD Mon YYYY, HH:MM)
- todayISO(): YYYY-MM-DD for today
- addDays(iso, days): Date arithmetic

---

## 16. PWA and Deployment

- Service worker at /sw.js
- Web manifest at /manifest.webmanifest
- Installable on mobile devices
- Deployed via Vercel (Next.js)

---

## 17. Design Tokens (globals.css @theme)

| Token | Value | Usage |
|---|---|---|
| ink | #14162B | Primary text |
| paper | #F4F5FA | Page/card background |
| line | #E4E5EF | Borders, dividers |
| mute | #767B94 | Secondary text |
| brand | #4338CA | Primary accent |
| brand-deep | #312896 | Hover states |
| brand-tint | #E8E7FB | Light brand bg |
| ledger | #B8791F | Currency highlights |
| instock | #1E7A4C | Positive/good stock |
| instock-tint | #E6F5ED | Light green bg |
| lowstock | #B4402A | Warning/danger |
| lowstock-tint | #FBEDE8 | Light red bg |

---

## 18. Supabase Keep-Awake

GitHub Actions workflow (.github/workflows/keep-supabase-awake.yml):
- Runs every 3 hours
- Non-blocking resume via Supabase Management API
- REST ping to verify the project is responsive
- Secrets: SUPABASE_PROJECT_URL, SUPABASE_ANON_KEY, SUPABASE_ACCESS_TOKEN, SUPABASE_PROJECT_REF

---

## 19. Git and Deployment

- Remote: github.com/Ajigiwe/stock
- Branch: main
- Lint: npx eslint src --max-warnings=0
- Build: npm run build
- All mutations invalidate cache via updateTag
