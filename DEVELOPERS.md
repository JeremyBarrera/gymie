# Developer Guide — Gymie

Notes for people working on the Gymie codebase. End-user installation lives in [README.md](README.md).

## Running the app during development

Three always-running processes — one terminal each:

```powershell
php artisan serve --host=0.0.0.0 --no-reload
php artisan reverb:start
php artisan queue:work
```

Windows one-command alternative:

```powershell
.\start-dev.ps1
.\stop-dev.ps1
```

Scheduler for time-based tasks:

```bash
php artisan schedule:work
```

## Frontend assets (Vite)

- `npm run dev` — dev server with hot reload
- `npm run build` — compiles `resources/js` + `resources/css` into `public/build`

Rebuild after changing `VITE_*` vars.

## Resetting the database (development)

```powershell
php artisan migrate:fresh
php artisan db:seed --class=ShieldSeeder
php artisan db:seed --class=UserSeeder
```

Leaves only the owner account. Do not run `setup-demo` or full `DatabaseSeeder` while actively building.

## Owner account

- Source: `OWNER_NAME` / `OWNER_EMAIL` / `OWNER_PASSWORD` in `.env` (defaults `test@example.com` / `test`).
- Bootstrap-only: `UserSeeder` creates the owner if absent and never modifies an existing account — the env values are never re-applied to a running install.
- Manual creation:

```powershell
php artisan gymie:create-owner admin@example.com --name="Admin" --password="secret"
```

## Tests and code style

```powershell
php artisan test
vendor/bin/pint
```

Suite uses `RefreshDatabase` and never touches dev data. Follow `AGENTS.md` for i18n, styling, transactions, and other conventions.

## Reception (Check-in & Sign-up Desk)

Filament page `Reception` has two tabs: **Check-in** (contact / government ID scan) and **Sign-Up** (walk-in applications). Staff claim entries (`claimed` prevents double handling), then approve or deny with a reason. Overrides respect `checkin.override` flag and notify via `FollowUpAlert`.

QR codes are generated per location from the Reception page (PNG/SVG, configurable size).

Public pages:

- `GET /checkin/{token}` — check-in scan
- `GET /signup/{token}` — sign-up form
- `POST /checkin/submit` — creates queue entry
- `GET /waiting/{uuid}` — live queue status

Feature flags in `PennantServiceProvider`: `checkin.override`, `api.checkin.lookup`, `api.signup.apply`. Scoped per location with global fallback.

## Queue clearing

```bash
php artisan gymie:clear-queue
php artisan gymie:clear-queue --location=3 --force
```

Deletes `waiting` / `attending` entries, keeps history, broadcasts expiry for live updates.

## Reverb behind a reverse proxy

Reverb listens on 8080. Proxy must forward upgrade headers:

```nginx
location /app/ {
    proxy_pass http://127.0.0.1:8080;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header Host $host;
}
```

Terminate TLS at the proxy; browsers connect via `wss://`.

## Production environment variables

| Variable | Value |
|---|---|
| `REVERB_HOST` | public hostname |
| `REVERB_SCHEME` | `https` |
| `REVERB_PORT` | `443` |
| `VITE_REVERB_HOST` | same as `REVERB_HOST` |
| `VITE_REVERB_SCHEME` | `https` |
| `REVERB_SCALING_ENABLED` | `false` single server, `true` multi-node with shared Redis |

Rebuild frontend after changing `VITE_*`.

## Scaling

- Single server: `REVERB_SCALING_ENABLED=false`, no Redis needed.
- Multiple nodes: `true` plus shared `REDIS_HOST` for all web, queue, and Reverb nodes.

## Docker deployment

```bash
cp .env.example .env
# Edit .env: APP_URL, OWNER_NAME/EMAIL/PASSWORD, DB_PASSWORD
docker compose up -d
docker compose exec app php artisan migrate --force
docker compose exec app php artisan db:seed --class=WorldSeeder
docker compose exec app php artisan db:seed --class=UserSeeder
docker compose exec app php artisan shield:generate --all --panel=admin
```

Migrations are never automatic. Nightly backups land in the `backups` volume at 03:00. HTTPS via DuckDNS + Let's Encrypt:

```bash
powershell -ExecutionPolicy Bypass -File scripts\render-nginx-tls.ps1
docker compose -f docker-compose.yml -f docker-compose.https.yml --profile https up -d
```

## Production supervision

Do not run from a terminal. Supervise each process:

| Process | Command |
|---|---|
| Web | `php artisan serve` / Nginx / PHP-FPM |
| Queue | `php artisan queue:work --sleep=1 --tries=3` |
| Reverb | `php artisan reverb:start` |

Supervisor example in README history; systemd equivalent uses `Restart=always`.

One-time server bootstrap (elevated PowerShell):

```powershell
powershell -ExecutionPolicy Bypass -File scripts\register-production-tasks.ps1
```

Registers firewall rules and the `GymieDuckDNSUpdater` task (reads `DUCKDNS_DOMAIN` / `DUCKDNS_TOKEN` from `.env`). Rclone offsite backups: `rclone config` as `gdrive`, then daily `schtasks` invoking `scripts\backup-to-gdrive.ps1` at 04:00.

## Environment separation & data refresh

Production and dev never share a live database:

```bash
./scripts/refresh-dev-data.sh user@prod-host
./scripts/refresh-dev-data.sh user@prod-host user@dev-host
```

Sanitizes PII, runs migrations. Dev data is disposable.

Validate restores with:

```powershell
powershell -ExecutionPolicy Bypass -File scripts\drill-restore.ps1
```

Isolated throwaway project that proves dump → import without touching live.

## API (JSON, v1)

- `POST /api/v1/auth/login`, `GET /api/v1/me`, `POST /api/v1/auth/logout`
- Rich filtering on index endpoints: `?q`, `?page`, `?per_page`, `?sort=-created_at,name`, `?trashed=with|only`, `?include=service,subscription.member`, `?filter[field]=value` with `..` range for dates
- Allowlists in `app/Services/Api/Schemas/*Schema.php`

## Troubleshooting

- `memory_limit = 512M` in php.ini for seeders
- Seeders slow: avoid full seeding in production; seed only `WorldSeeder` when needed
