#!/usr/bin/env bash
#
# Pull a SANITIZED production data copy into the dev environment (Phase 10.5).
#
# Assumes BOTH hosts run the Phase 10 docker compose stack. Never points dev
# at the live database - the dump is transferred and loaded into dev's own
# MySQL container, then PII is masked before anyone touches it.
#
# Usage:
#   ./scripts/refresh-dev-data.sh user@prod-host [dev-host]
#
# If [dev-host] is omitted the script loads into the LOCAL dev stack.

set -euo pipefail

PROD_HOST="${1:?Usage: $0 user@prod-host [dev-host]}"
DEV_HOST="${2:-}"
DB_NAME="${DB_NAME:-gymie}"
STAMP="$(date +%Y%m%d-%H%M%S)"
DUMP="gymie-prod-${STAMP}.sql.gz"

echo "==> [1/5] Dumping production database on ${PROD_HOST}"
ssh "$PROD_HOST" "docker compose -f /opt/gymie/docker-compose.yml exec -T db \
    mysqldump -u root --single-transaction --quick '${DB_NAME}' | gzip > /tmp/${DUMP}"

echo "==> [2/5] Transferring ${DUMP}"
scp "${PROD_HOST}:/tmp/${DUMP}" "/tmp/${DUMP}"
ssh "$PROD_HOST" "rm /tmp/${DUMP}"

load_target() {
    if [ -n "$DEV_HOST" ]; then
        ssh "$DEV_HOST" "docker compose -f /opt/gymie/docker-compose.yml exec -T db mysql -u root '${DB_NAME}'"
    else
        docker compose exec -T db mysql -u root "${DB_NAME}"
    fi
}

echo "==> [3/5] Loading into dev (fresh database - dev data is disposable)"
if [ -n "$DEV_HOST" ]; then
    ssh "$DEV_HOST" "cd /opt/gymie && docker compose exec -T db mysql -u root -e 'DROP DATABASE IF EXISTS ${DB_NAME}; CREATE DATABASE ${DB_NAME};'"
    scp "/tmp/${DUMP}" "${DEV_HOST}:/tmp/${DUMP}"
    ssh "$DEV_HOST" "docker compose -f /opt/gymie/docker-compose.yml exec -T db sh -c 'gunzip -c /tmp/${DUMP} | mysql -u root ${DB_NAME}' && rm /tmp/${DUMP}"
else
    docker compose exec -T db mysql -u root -e "DROP DATABASE IF EXISTS ${DB_NAME}; CREATE DATABASE ${DB_NAME};"
    gunzip -c "/tmp/${DUMP}" | load_target
fi

sanitize_sql() {
    cat <<'SQL'
-- Mask every piece of member PII the dev team does not need.
UPDATE members SET contact = CONCAT('555', LPAD(id, 7, '0')),
                   government_id = CONCAT('DEV-', LPAD(id, 6, '0')),
                   email = NULL,
                   emergency_contact = NULL,
                   ban_reason = NULL;
TRUNCATE notifications;
TRUNCATE sessions;
SQL
}

echo "==> [4/5] Sanitizing PII"
if [ -n "$DEV_HOST" ]; then
    sanitize_sql | ssh "$DEV_HOST" "docker compose -f /opt/gymie/docker-compose.yml exec -T db mysql -u root ${DB_NAME}"
else
    sanitize_sql | docker compose exec -T db mysql -u root "${DB_NAME}"
fi

rm -f "/tmp/${DUMP}"

echo "==> [5/5] Running dev migrations against the copy"
if [ -n "$DEV_HOST" ]; then
    ssh "$DEV_HOST" "cd /opt/gymie && docker compose exec -T app php artisan migrate --force"
else
    docker compose exec -T app php artisan migrate --force
fi

echo "Done. Dev now holds a sanitized production copy with migrations applied."
