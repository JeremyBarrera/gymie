<p align="center"><img width="160" src=".github/assets/logo.svg"></p>

![Gymie](.github/assets/banner.png)

## Overview
Laravel based web application for gym & club management. Currently being used by many fitness centers. For more information, visit - https://www.gymie.in

Gymie handles members, subscriptions, invoices, check-ins, and a live reception desk. Public QR codes let members check in or apply to join; staff claim and resolve the queue in real time.

## Requirements
- PHP >= 8.2
- Laravel Framework ^12.0
- Filament Admin Panel 5.x
- Livewire ^3.0
- nnjeim/world ^1.1
- barryvdh/laravel-dompdf ^3.1
- Laravel Herd _(optional for local development)_

## Quick Start (from zero)
```bash
git clone git@github.com:JeremyBarrera/gymie
cd gymie
composer install
composer run prepare-env
# configure .env — set OWNER_NAME, OWNER_EMAIL, OWNER_PASSWORD, database, APP_URL
composer run setup
php artisan serve --host=0.0.0.0 --no-reload
php artisan reverb:start
php artisan queue:work
```

`reverb` and `queue` are required for the live reception flow. On Windows use `.\start-dev.ps1` / `.\stop-dev.ps1` instead of three terminals.

## Startup & First-Run Setup
The owner account is created automatically from `.env` (`OWNER_NAME` / `OWNER_EMAIL` / `OWNER_PASSWORD`) during `composer run setup`. No manual owner-creation step needed.

## Self-Hosting with Docker (Recommended)

[Docker Desktop](https://www.docker.com/products/docker-desktop/) is the easiest way to run Gymie in production — no PHP, Node, or MySQL installed on the host.

```bash
git clone git@github.com:JeremyBarrera/gymie
cd gymie
cp .env.example .env
```

Edit `.env` — set at minimum:
- `APP_URL` — your server's public URL (e.g. `http://192.168.1.100`)
- `OWNER_NAME`, `OWNER_EMAIL`, `OWNER_PASSWORD` — your admin login
- `DB_PASSWORD` — a strong random string

Then:

```bash
docker compose up -d
docker compose exec app php artisan migrate --force
docker compose exec app php artisan db:seed --class=WorldSeeder
docker compose exec app php artisan db:seed --class=UserSeeder
docker compose exec app php artisan shield:generate --all --panel=admin
```

Open `APP_URL` in your browser and log in with the owner credentials you set.

**What's running:** nginx (web), PHP-FPM (app), MySQL, Redis, Reverb (websockets), queue worker, scheduler, and nightly backups — all managed by Docker Compose.

### HTTPS

For production with TLS via DuckDNS + Let's Encrypt:

```bash
# Add to .env: DUCKDNS_DOMAIN, DUCKDNS_TOKEN, LETSENCRYPT_EMAIL
powershell -ExecutionPolicy Bypass -File scripts\render-nginx-tls.ps1
docker compose -f docker-compose.yml -f docker-compose.https.yml --profile https up -d
```

## How It Works
A location token powers public pages (`/checkin/{token}`, `/signup/{token}`) where members submit check-ins or sign-up forms. Each submission creates a queue entry that appears instantly on the Reception page; staff claim it so no two people handle the same guest, then approve or deny. Overrides, subscription use limits, and follow-up alerts are handled through the same flow.

Details on feature flags, QR codes, and the public API are in [DEVELOPERS.md](DEVELOPERS.md).

## Development
```bash
php artisan test
vendor/bin/pint
npm run dev     # hot reload while working on JS/CSS
npm run build   # compiled bundle for production
```

See [DEVELOPERS.md](DEVELOPERS.md) for resetting the database, owner handling, Reverb proxy, Docker, and production supervision.

## License
Gymie is open-sourced under the [MIT license](LICENSE).
