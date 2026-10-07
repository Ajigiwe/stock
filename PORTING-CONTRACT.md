# PHP Port — Build Contract

Everything in this file is binding. Source of truth for behaviour remains the
original Next.js app; this file only fixes **names, shapes and routes** so the
port stays consistent across files.

## 0. Environment

- Laravel 12, PHP 8.2, MariaDB 10.4 (`mrjeff` db, `mrjeff_test` for tests).
- Migrations `0000_00_00_000001` (tables) and `...000002` (12 triggers) are DONE.
  Never write `available` from application code — triggers maintain it.
- SQLite is **never** used. Tests run on `mrjeff_test` (MySQL).
- UUIDs are `Str::uuid()->toString()`. Timestamps UTC.

## 1. Behavioural rules (parity with the original)

1. **Error messages are copied verbatim** from `src/lib/actions.ts` /
   the SQL RPCs. Users see the same words.
2. **Role checks are fail-closed**: every action re-reads the caller's profile
   from the DB (never trusts input); owner-only actions return
   `"Only the owner can ..."` errors exactly as the original does.
3. Every write action mirrors one TS action 1:1 (same validation order, same
   guard clauses). Read `src/lib/actions.ts` (1469 lines) for the logic and
   `supabase/schema.sql` + `supabase/migrations/0004_*.sql` for the RPC bodies.
4. Stock/transaction writes go through `DB::transaction()`; lock
   `phone_models` rows with `lockForUpdate()` in `phone_model_id` ASC order
   before inserting line items (same as the original `FOR UPDATE` reads).
5. Audit rows: actions that log in the original (`stock_logs`,
   `transaction_events`, `login_logs`) must log here too.

## 2. Action result convention

Every ported action returns the same shape:

```php
/** @return array{ok: bool, error?: string, id?: string, warnings?: array<int,string>} */
```

A controller turns that into an HTTP response with one shared helper
(`App\Http\Controllers\Controller::respond()`):

- `expectsJson()` (the offline queue replays via `fetch`) → `response()->json($result, $result['ok'] ? 200 : 422)`
- otherwise → redirect back with `session()->flash('ok'| 'error', ...)` (+ `old()` input on error).

Success redirects back to the referring page (or to a `?saved=1` target when
the original navigated), never to a dead URL.

## 3. Routes (`routes/web.php`) — complete inventory

Guest:
```
GET  /setup                        AuthController@setupForm
POST /setup                        AuthController@setup
GET  /login                        AuthController@loginForm
POST /login                        AuthController@login      (RateLimiter: 8 tries / 10 min per ip+email)
POST /logout                       AuthController@logout
```
Authenticated (`auth`, `active`):
```
GET  /                             DashboardController@index      ?period=today|7d|30d &shop={uuid}
GET  /shops/{shop}                 ShopController@show            ?date=YYYY-MM-DD
POST /shops/{shop}/models          StockController@create
POST /shops/{shop}/models/{model}  StockController@update
POST /shops/{shop}/models/{model}/adjust   StockController@adjust
POST /shops/{shop}/models/bulk              StockController@bulkAdjust
POST /shops/{shop}/close                    ShopController@submitClose    (submitDailyClose)
POST /shops/{shop}/close/{close}/lock       ShopController@lockClose      (lockDailyClose)
POST /shops/{shop}/counts                   ShopController@submitCount    (submitStockCount)
GET  /transactions/new             TransactionController@create   ?shop={uuid}
POST /transactions                 TransactionController@store    (recordTransaction)
GET  /transactions/{transaction}   TransactionController@show
POST /transactions/{transaction}/review     TransactionController@review
POST /transactions/{transaction}/void       TransactionController@void
POST /swapped-phones/{phone}/status         TransactionController@swappedStatus
GET  /devices                      DeviceController@index
POST /devices/models/bulk          DeviceController@bulkCreate    (bulkCreateModels, owner)
POST /requests/{request}/approve   StockRequestController@approve
POST /requests/{request}/reject    StockRequestController@reject
POST /requests/approve-all         StockRequestController@approveAll
GET  /reports                      ReportController@index
GET  /reports/export               ReportController@export        (CSV, formula-injection escaping kept)
POST /reports/counts/{count}/approve        ReportController@approveCount
POST /reports/counts/{count}/apply          ReportController@applyCount
GET  /settings                     SettingsController@index       (owner only)
POST /settings/shops               SettingsController@createShop
POST /settings/shops/{shop}/delete SettingsController@deleteShop
POST /settings/staff               SettingsController@createStaff
POST /settings/staff/{user}/deactivate    SettingsController@deactivate
POST /settings/staff/{user}/reactivate    SettingsController@reactivate
POST /settings/staff/{user}/reset-password SettingsController@resetPassword
POST /settings/staff/{user}/permissions SettingsController@updatePermissions   (owner grants staff capabilities)
POST /settings/staff/{user}/shop         SettingsController@moveShop           (owner moves attendant shops)
POST /settings/models/bulk         SettingsController@bulkCreateModels
POST /settings/models/import       SettingsController@importModels    (CSV upload; see §5b)
GET  /settings/models/import/template SettingsController@importTemplate (CSV template download, owner)
GET  /settings/backup/download     SettingsController@downloadBackup
POST /settings/backup/restore      SettingsController@restoreBackup
POST /settings/wipe               SettingsController@wipe            (delete all business data, keep accounts)
GET  /logs                         LogController@index
GET  /account                      AccountController@index
POST /account/password             AccountController@changePassword
```
No `/signup` (accounts are owner-created). Routes are named (`login`, `setup`,
`dashboard`, `shop.show`, …) so views never hardcode URLs.

Three routes post-date the Next.js app and have no original to match — they
are speced here: `POST /settings/models/import` (CSV → bulk-add rows, errors
`CSV file is missing or too large.` / `CSV must include a model_name column.`
/ inherited `bulkCreate` messages), `GET /settings/models/import/template`
(owner-only CSV header + one example row), and `POST /settings/wipe`
(`confirm` must be `WIPE`, else `Type WIPE to confirm.`; non-owner gets
`Only the owner can wipe data.`). Success strings: `N devices imported.` /
`All data wiped (N rows removed).`

Middleware aliases (register in `bootstrap/app.php`):
- `auth` (Laravel default, redirect → `route('login')`)
- `active` → `App\Http\Middleware\EnsureUserIsActive` (log out deactivated users — port of `src/proxy.ts`)
- `setup.state` → `App\Http\Middleware\EnsureSetupState` (no owner → force `/setup`; owner exists → `/setup` redirects to `/login`)

## 4. Models (`app/Models/`) — one class per table

`Shop, User, PhoneModel, Transaction, TransactionItem, StockAdjustment,
StockRequest, SwappedPhone, LoginLog, StockLog, TransactionEvent, DailyClose,
StockCount, StockCountItem`

- `User extends Authenticatable` implements `MustVerifyEmail` **not** used;
  casts: `active => boolean`, `role => string`; `hidden: password, remember_token`.
  Helpers: `isOwner(): bool`, `scopeOwners()`.
- `$timestamps` only where `updated_at` exists (users); everything else
  `const UPDATED_AT = null` + `CREATED_AT = 'created_at'` with
  `DEFAULT CURRENT_TIMESTAMP`.
- No mass-assignment of `available` (app never sets it). Keep `$guarded = []`
  **off**: use explicit `$fillable` everywhere.
- Relations mirror the FKs (see migration `0000_00_00_000001`).

## 5. Services (`app/Services/`)

Port names follow the TS action, StudlyCased. Signatures:

```
TransactionService::record(array $input, User $actor): array
TransactionService::review(string $txId, string $decision, ?string $reason, User $actor): array   // decision: approve|reject
TransactionService::void(string $txId, string $reason, User $actor): array
TransactionService::setSwappedStatus(string $id, string $status, User $actor): array

StockService::create(array $input, User $actor): array          // owner-direct path
StockService::update(string $id, array $input, User $actor): array
StockService::adjust(array $input, User $actor): array
StockService::bulkAdjust(array $input, User $actor): array
StockService::bulkCreate(array $rows, User $actor): array       // settings bulk add

StockRequestService::approve(string $id, User $actor): array
StockRequestService::reject(string $id, User $actor): array
StockRequestService::approveAll(User $actor): array

ShopService::create(array $input, User $actor): array
ShopService::delete(string $id, User $actor): array

StaffService::create(array $input, User $actor): array
StaffService::deactivate(string $id, User $actor): array
StaffService::reactivate(string $id, User $actor): array
StaffService::resetPassword(string $id, User $actor): array
StaffService::setPermissions(string $id, array $input, User $actor): array   // owner grants perm_* to an attendant
StaffService::moveShop(string $id, array $input, User $actor): array         // owner moves an attendant (shopId, blank parks)

ReconciliationService::submitClose(array $input, User $actor): array
ReconciliationService::lockClose(string $id, User $actor): array
ReconciliationService::submitCount(array $input, User $actor): array
ReconciliationService::approveCount(string $id, User $actor): array
ReconciliationService::applyCount(string $id, User $actor): array

BackupService::export(User $actor): array                       // backup payload
BackupService::restore(array $data, User $actor): array         // sets @mrjeff_no_stock_effects, reconciles `available`
BackupService::wipe(array $input, User $actor): array           // confirm=WIPE; empties WIPE_ORDER (DELETE_ORDER minus users), keeps accounts

AuditLog::stock(User $actor, string $action, ?string $modelId, ?string $modelName, ?string $condition, array $details): void
AuditLog::event(string $txId, User $actor, string $action, array $details): void
```

Input arrays use camelCase keys identical to the TS action inputs
(`shopId`, `customerName`, `outItems`, `swapIn`, `idempotencyKey`, …).

## 5b. Form field names (the view ↔ controller interface)

A `<form>` posts exactly the keys of the corresponding TS action input, read
from the `type XxxInput = {...}` blocks in `src/lib/actions.ts` — that file is
the single source of truth, so a view author and a controller author who both
read it produce matching names:

- **Scalar fields** use the TS key verbatim (camelCase): `customerName`,
  `paymentMethod`, `amount`, `date`, `modelName`, `costPrice`, `salePrice`,
  `openingStock`, `delta`, `reason`, `currentPassword`, …
- **Row arrays** use PHP bracket syntax: `outItems[0][modelId]`,
  `outItems[0][qty]`, `inItems[0][mode]`, `inItems[0][name]`,
  `swapIn[0][name]`, and likewise for bulk-add/stock-count rows. Alpine adds
  and removes rows by index; PHP receives `$request->input('outItems')` as a
  list of assoc arrays in the same shape as `TxOutItem[]`/`TxInItem[]`.
- **Staff accounts need no email.** `POST /settings/staff` takes `name` +
  `password` (≥8 chars) with optional `email` and optional `phone`
  (`Input::phone()` normalises spacing/dashes); either identifier signs in
  at `/login` (the form field stays `email`). Duplicates are refused with
  `That email address is already in use.` / `That phone number is already
  in use.`
- **Product variants.** `simType` (one of `StockService::SIM_TYPES`, blank = unspecified) and `color` (≤64 chars, `colour` accepted as an alias) ride on create/update/bulk/CSV/request rows (`rows[i][sim_type]`, `rows[i][color]`); the duplicate key is name + condition + variant
- **Staff capabilities.** `POST /settings/staff/{user}/permissions` takes
  checkbox fields `perm_approve_requests`, `perm_adjust_stock`,
  `perm_reconcile` (absent means off). Holders act inside their own shop
  only: the shop page shows them approve/reject, lock/apply and direct
  adjust controls (`$canApproveRequests`, `$canReconcile`, `$canEditStock`
  in `ShopQueries::show`). Controllers must declare every route parameter
  they consume — Laravel fills action arguments positionally, so a missing
  `Shop $shop` slides the shop id into the next argument
  (`close.lock` did exactly this).
- **Route-bound ids are NOT in the form.** `shopId`, `modelId`, `txId`,
  `requestId`… come from the URL; controllers merge them into the input array
  before calling the service (e.g. `$input['modelId'] = $model->id;`).
- **`idempotencyKey`**: the POS view generates
  `crypto.randomUUID()` when the form mounts and again after every successful
  submit (port of `resetForm()`), so a retry of the same submission reuses the
  key. If absent, `Input::idempotencyKey()` derives a stable one server-side.
- Numeric inputs are strings (they come from `<input>`), exactly like the TS
  `amount: string` / `delta: string` — services parse them with
  `Input::money()`/`Input::count()`.
- All forms are `<form method="POST" action="{{ route(...) }}">` with `@csrf`.
  The offline queue may POST the same keys as JSON; `respond()` already
  answers JSON clients with JSON.
- **Post-parity forms** (added after the port): the CSV import posts `csv`
  (the file) + `shopId`; the wipe posts `confirm` (must equal `WIPE`).
  Files are read in the controller like `restoreBackup`'s `backup` upload —
  `SettingsController::parseCsv()` normalises the header (BOM/`;`-delimited/
  `Name Case` tolerated) and emits the bulk-add `rows[i][snake_case]` shape,
  so `StockService::bulkCreate` validates both entry points.

## 6. Queries (`app/Services/Queries/`)

Port of `src/lib/data.ts` — same method names, StudlyCased, returning plain
arrays shaped for Blade (associative keys = the fields the React components
rendered):

```
DashboardQueries::index(?string $period, ?string $shopId, User $actor): array
ShopQueries::show(string $shopId, ?string $date, User $actor): array
TransactionQueries::create(?string $shopId, User $actor): array   // POS page: shops, stock, defaultShopId, isOwner
TransactionQueries::show(string $txId, User $actor): array        // [] = not found / not visible
DeviceQueries::index(User $actor): array
ReportQueries::index(User $actor): array
ReportQueries::export(User $actor): array        // rows + headers for CSV
SettingsQueries::index(User $actor): array        // owner-only: shops + staff
LogQueries::index(User $actor): array
```
Every query must **scope by the caller's shop** unless the caller is the owner
(RLS parity: attendants see only their own shop; owner sees a selected shop or
all shops). Missing/unauthorised shop → fail closed (empty/redirect, never
another shop's data). Cache: wrap every read in
`App\Support\DataCache::remember($key, fn () => ...)` and call
`DataCache::flush()` after every successful write (services do the flush) —
parity with `unstable_cache`/`updateTag`/`invalidateAllData`.

**GET controllers pass the query payload straight through** —
`return view('dashboard', DashboardQueries::index(...));` — so page view
variables are exactly the keys listed above (no renaming, no re-shaping in
controllers). Views and controllers can therefore be built independently.

## 7. Views (`resources/views/`)

Blade + **Tailwind 4** classes copied from the React markup (same design
tokens: `ink`, `paper`, `line`, `mute`, `brand`, `ledger`, `instock`,
`lowstock`, `warnstock` — defined in `resources/css/app.css`).

```
layouts/app.blade.php          desktop sidebar + mobile header/bottom-nav + flash + shop switcher (port of app-shell.tsx)
layouts/guest.blade.php        centred card shell for login/setup
auth/login.blade.php           + auth/setup.blade.php
dashboard.blade.php            cards, charts (SVG/CSS bars), recent tx, low stock, pending requests, review panel
shops/show.blade.php           stock table, edit/adjust modals, add-model, bulk modal, tabs: recon, counts, daily close, swaps, requests
transactions/new.blade.php     POS terminal: catalog + running ticket (type comes from the sidebar ?type= link)
transactions/show.blade.php    receipt + review/void actions
devices/index.blade.php        device matrix + detail modal + sold history
reports/index.blade.php        period filters, daily rows, CSV export link, stock counts
settings/index.blade.php       shops, staff, bulk add models, CSV import + template, backup/restore, data wipe
logs/index.blade.php           login + stock log tables
account/index.blade.php        change password
```
Interactivity uses **Alpine.js** (bundled via `resources/js/app.js` — no CDN,
must work offline). No React, no client bundles: every mutation is a normal
`<form method="POST">` with `@csrf`, except the POS form which may use Alpine
for the multi-step UI.

Shared component classes are available in `resources/css/app.css` — use them
instead of repeating utility soup: `card`, `btn`, `btn-primary`,
`btn-secondary`, `btn-danger`, `btn-ghost`, `btn-sm`, `input`, `label`,
`field-error`, `badge`, `badge-ok`, `badge-warn`, `badge-danger`,
`badge-muted`, `badge-brand`, `table-base` (style rows with its `thead th` /
`tbody td` rules), `empty-state`, `section-title`, `tnum` (tabular numerals),
plus `<x-icon name="..." size="20"/>` for the shell's stroke icons
(dashboard, record, reports, devices, logs, settings, shop, account, menu,
close, logout, chevron, alert). Flash output lives in
`resources/views/partials/flash.blade.php` and is already included by both
layouts — page views never render flash themselves.

`@vite(['resources/css/app.css', 'resources/js/app.js'])` in layouts.

## 8. Source files to read before writing anything

| Port | Read |
|---|---|
| actions/services | `src/lib/actions.ts` (whole file) |
| queries | `src/lib/data.ts` |
| RPC details | `supabase/schema.sql`, `supabase/migrations/0004_fraud_controls_and_reconciliation.sql` |
| auth/proxy | `src/proxy.ts`, `src/lib/actions.ts:244-460` |
| views | the `.tsx` component named in §7 (same folder `src/components/`) |
| CSV export | `src/app/reports/export/route.ts` |
| offline queue | `src/lib/offline-queue.ts`, `public/sw.js` (kept as-is) |

## 9. Definition of done (each file group)

- `php artisan route:list` shows every route in §3.
- `php artisan test` green.
- No `dd()`, no TODO, no commented-out code.
- Behaviour differences from the original are impossible — if the original
  allowed it, this allows it; if the original rejected it, same message.
