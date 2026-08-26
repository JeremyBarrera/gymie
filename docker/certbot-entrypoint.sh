#!/bin/sh
# Certbot sidecar for the https compose profile. Issues the first
# certificate via webroot challenge, then renews forever. Runs as a mounted
# script so no shell logic lives inside YAML scalars.
#
# Requires env: DUCKDNS_DOMAIN, LETSENCRYPT_EMAIL (from .env via compose).

set -u
trap 'exit 0' TERM

DOMAIN="${DUCKDNS_DOMAIN:?DUCKDNS_DOMAIN not set}".duckdns.org

echo "[certbot] requesting certificate for ${DOMAIN}..."

certbot certonly \
    --webroot -w /var/www/certbot \
    -d "$DOMAIN" \
    --email "$LETSENCRYPT_EMAIL" \
    --agree-tos --no-eff-email --non-interactive \
|| echo "[certbot] issuance failed this round — the renew loop below keeps retrying every 12h"

echo "[certbot] entering renew loop"
while :; do
    sleep 12h &
    wait "$!"
    certbot renew --webroot --non-interactive || echo "[certbot] renew attempt failed; retrying next cycle"
done
