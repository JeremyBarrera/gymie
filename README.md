<p align="center"><img width="160" src=".github/assets/logo.svg"></p>

![Gymie](.github/assets/banner.png)

## Overview
Laravel based web application for gym & club management. Currently being used by many fitness centers. For more information, visit - https://www.gymie.in

Gymie handles members, subscriptions, invoices, check-ins, and a live reception desk. Public QR codes let members check in or apply to join; staff claim and resolve the queue in real time.

For developer setup, architecture, and advanced commands see [DEVELOPERS.md](DEVELOPERS.md). For a private deployment with real credentials, copy `DEPLOYMENT.example.md` to `DEPLOYMENT.md` (ignored by git) and fill in your host-specific values.

## Requirements
- PHP >= 8.2
- Laravel Framework ^12.0
- Filament Admin Panel 5.x
- Livewire ^3.0
- nnjeim/world ^1.1
- barryvdh/laravel-dompdf ^3.1
- Laravel Herd _(optional for local development)_

## Installation
```bash
git clone git@github.com:JeremyBarrera/gymie
cd gymie
composer install
composer run prepare-env
```

`prepare-env` copies `.env.example` to `.env` (if missing), clears config cache, generates `APP_KEY`, and links storage. Then configure `.env` database credentials and `APP_URL`.

Database setup:

```bash
composer run setup        # blank production setup (migrations + world data + Shield)
composer run setup-demo   # demo data (erases existing data — local only)
```

`setup` runs migrations, world data, Shield permissions, and creates the owner from `.env`. Demo credentials are `test@example.com` / `test`.

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
