# Deployment — Private Copy Template

Copy this file to `DEPLOYMENT.md` (ignored by git) and fill in host-specific values. Do not commit the filled copy.

```bash
cp DEPLOYMENT.example.md DEPLOYMENT.md
```

## Host

- Host:
- OS:
- Repo path:

## Credentials

- APP_URL:
- DB_PASSWORD:
- OWNER_EMAIL / OWNER_PASSWORD:
- REVERB_APP_KEY / SECRET:
- DUCKDNS_DOMAIN / DUCKDNS_TOKEN:
- LETSENCRYPT_EMAIL:
- FUNNEL_HOST:

## Steps

```bash
cp .env.example .env
# fill .env from values above
docker volume create gymie_db-data
docker compose -f docker-compose.yml -f docker-compose.https.yml --profile https up -d
docker compose exec app php artisan migrate --force
```

Keep this file local only.
