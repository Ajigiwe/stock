# Agent notes — Mr Jeff Stock (Laravel 12 + MySQL)

- The original Next.js 16 + Supabase app was removed in M6; its behaviour is
  the specification. Read before changing anything:
  - `PORTING-CONTRACT.md` — binding: routes, service/query signatures,
    form field names, view inventory, verbatim error strings.
  - `PLAN-php-migration.md` — milestones M0–M6 and status.
  - `design.md` — architecture, invariants, and porting gotchas.
- `phone_models.available` is never written by application code — 12 MySQL
  triggers derive it and SIGNAL the original error texts. Do not bypass them.
- Tests run against real MySQL (`mrjeff_test`, set in `phpunit.xml`);
  SQLite cannot run the triggers or CHECK constraints: `php artisan test`.
- Blade gotchas that already bit once: on a component tag `:class` is PHP
  (Alpine needs `x-bind:class`), and Blade's `@directive` regex requires a
  non-word character before `@` (never `@endif@if` or `word@endif`).
- Laravel 12 loads `.env.{APP_ENV}`, not `.env.local` — never create one.
