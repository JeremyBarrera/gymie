# Security Policy

## Supported Versions

| Version | Supported          |
| ------- | ------------------ |
| 3.x   | :white_check_mark: |
| 1.x   | :x:                |

## Reporting a Vulnerability

If you discover any security related issues, please email to info@lubus.in instead of using the issue tracker.

## Production Operator Checklist

The code enforces the controls it can; the rest are operator obligations on
the server. These were reviewed in the 2026-08 pre-deployment audit.

### Environment (`.env` on the server)

- `APP_DEBUG=false` — the container entrypoint bakes config into the
  production cache at boot, so a debug `true` leaks stack traces and env
  values to every visitor of every door.
- `APP_ENV=production`.
- `REVERB_APP_SECRET` / `REVERB_APP_KEY` must be changed from the
  `.env.example` placeholders (`reverb-secret` / `reverb-key`).
- `DB_PASSWORD` must be a strong random value; it is also MySQL's root
  password inside the compose project.
- `SESSION_SECURE_COOKIE=true` is recommended for tailnet/admin use where
  all doors are https.

### Secret history & rotation

- A full-history scan (gitleaks) found only two dead credentials from the
  2018 upstream import: an `APP_KEY` fallback and a `JWT_SECRET`, both for
  a Laravel 5 codebase that no longer exists in this repository. Nothing
  from the current application has ever been committed.
- If any real secret was ever shared outside `.env` (screenshots, tickets,
  chat), rotate it: `php artisan key:generate` re-encrypts nothing —
  rotating `APP_KEY` also invalidates the government-ID blind index and
  any encrypted casts, so coordinate a data migration if that happens.

### Backups & restore

- Nightly dumps land in the `backups` volume at 03:00; retention prunes
  files older than ~14 days at 03:30.
- Verify the pipeline periodically with
  `powershell -ExecutionPolicy Bypass -File scripts\drill-restore.ps1`
  — it restores into an isolated throwaway project and compares row
  counts against the live database.

### Known accepted risks

- The LAN/private admin door serves plain HTTP behind a placeholder
  certificate by design; only the funnel door and port-81 admin door
  terminate TLS (Tailscale/Let's Encrypt). Never expose port 80 of the
  host beyond the tailnet.
- Sanctum API tokens do not expire (`SANCTUM expiration = null`). Issue
  tokens per device and revoke via logout; consider setting an expiration
  if long-lived unattended tablets become a concern.

### Privacy / legal (operator actions)

- Publish a privacy policy + terms covering member data collected
  (government ID, health notes, photos) and the retention/deletion path.
- Member deletion: soft delete keeps data restorable; permanent delete
  cascades subscriptions/check-ins and removes the photo file from disk.
