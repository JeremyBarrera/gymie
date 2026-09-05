$ErrorActionPreference = "Stop"

$prodDb = "torogym"
$stageDb = "gymie_staging"
$prodPass = (Get-Content .env | Select-String "DB_PASSWORD").ToString().Split("=")[1]
$stagePass = (Get-Content .env.staging | Select-String "DB_PASSWORD").ToString().Split("=")[1]
$stageSql = "$env:TEMP\stage.sql"
$stagingCompose = @("--env-file", ".env.staging", "-f", "docker-compose.yml", "-f", "docker-compose.staging.yml", "-p", "gymie-staging")

try {
    Write-Host "Dumping $prodDb from prod db container (single-transaction, no write to prod)..."
    docker compose -p gymie exec db mysqldump -u root -p$prodPass --single-transaction --quick $prodDb | Set-Content -Path $stageSql -Encoding Byte

    Write-Host "Restoring into $stageDb on staging db..."
    docker compose @stagingCompose exec -T db-staging mysql -u root -p$stagePass -e "CREATE DATABASE IF NOT EXISTS $stageDb"
    Get-Content $stageSql | docker compose @stagingCompose exec -T db-staging mysql -u root -p$stagePass $stageDb

    Write-Host "Restore complete."
    Write-Host "Verify with the staging DB password from .env.staging (never printed here): docker compose -p gymie-staging exec db-staging mysql -u root -p --execute 'SELECT COUNT(*) FROM gymie_staging.users; SELECT COUNT(*) FROM gymie_staging.members;'"
} finally {
    if (Test-Path $stageSql) {
        Remove-Item $stageSql -Force
    }
}
