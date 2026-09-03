# Copies live torogym DB to gymie_staging on the staging host (laptop) — tailnet-only, no anonymize per user choice.
# Usage: powershell -File scripts/restore-staging-from-prod.ps1
# Requires: docker compose -p gymie (prod) and -p gymie-staging (staging) both defined, .env and .env.staging present.

$ErrorActionPreference = "Stop"

$prodDb = "torogym"
$stageDb = "gymie_staging"
$stagePass = (Get-Content .env.staging | Select-String "DB_PASSWORD").ToString().Split("=")[1]

Write-Host "Dumping $prodDb from prod db container (single-transaction, no write to prod)..."
docker compose -p gymie exec db mysqldump -u root -p$env:DB_PASSWORD --single-transaction --quick $prodDb | Set-Content -Path "$env:TEMP\stage.sql" -Encoding Byte

Write-Host "Restoring into $stageDb on staging db..."
# Staging DB must exist; create if not
docker compose -p gymie-staging exec db-staging mysql -u root -p$stagePass -e "CREATE DATABASE IF NOT EXISTS $stageDb"
Get-Content "$env:TEMP\stage.sql" | docker compose -p gymie-staging exec -T db-staging mysql -u root -p$stagePass $stageDb

Write-Host "Restore complete — staging now has production-like data. No PII scrub (per user choice, internal tailnet-only)."
Write-Host "Verify: docker compose -p gymie-staging exec db-staging mysql -u root -p$stagePass -e 'SELECT COUNT(*) FROM gymie_staging.users; SELECT COUNT(*) FROM gymie_staging.members;'"
