# Developer Guide — Gymie

Notes for people working on the Gymie codebase. End-user installation and
usage lives in [README.md](README.md).

## Running the app during development

The app is three always-running processes. Run each in its own terminal:

```powershell
php artisan serve --host=0.0.0.0 --no-reload   # web server
php artisan reverb:start                       # websocket server (live reception popup)
php artisan queue:work                         # queue worker (broadcasts, notifications)
```

Or on Windows, launch all three in the background once:

```powershell
.\start-dev.ps1   # stops with .\stop-dev.ps1
```

> `php artisan serve` starts the app. It is **not** an installation step — the
> database and env must already be set up. It is the only command you run every
> time; `reverb` and `queue` are required for the realtime reception features.

## Frontend assets (Vite)

- `npm run dev` — Vite dev server with hot reload. Use this while working on
  JS/CSS.
- `npm run build` — compiles `resources/js` + `resources/css` into
  `public/build`. This is a **build step**, not a run step: the browser loads
  the compiled bundle via `@vite`, so the app needs a `public/build` output
  whenever it isn't served through the Vite dev server. Run it after changing
  frontend code (or `VITE_*` env vars) before deploying; in production there is
  no `npm run dev`.

## Resetting the database (development)

The local database accumulates fake/test data. To start clean:

```powershell
php artisan migrate:fresh
php artisan db:seed --class=ShieldSeeder
php artisan db:seed --class=UserSeeder
```

This rebuilds the schema and leaves **only** the owner account.

> Do **not** run `composer run setup-demo` or the full `DatabaseSeeder` in a
> project you're actively building — they seed demo members, subscriptions and
> invoices that are exactly the stale test data we try to avoid.

## Owner account

- The owner credentials come from `OWNER_NAME` / `OWNER_EMAIL` /
  `OWNER_PASSWORD` in `.env` (defaults: `test@example.com` / `test`).
- These are **bootstrap-only**: `UserSeeder` creates the owner if absent and
  never modifies an existing account. Editing the vars does not change a
  running install — only a clean reset picks them up.
- To create/adjust an owner manually without touching `.env`:

  ```powershell
  php artisan gymie:create-owner admin@example.com --name="Admin" --password="secret"
  ```

## Tests

```powershell
php artisan test
```

The suite uses its own test database (`RefreshDatabase`), so it never touches
your development data.

## Code style

- Formatting: `vendor/bin/pint` (Laravel Pint).
- Follow `AGENTS.md` at the repo root — it is the authoritative set of project
  conventions (i18n, Filament styling, no legacy accommodation, dev-data
  policy).
