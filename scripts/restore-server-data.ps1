# Restores a Toro GYM data package produced by push-server-data.ps1.
# Runs ON THE NEW SERVER from the cloned repository root:
#
#   powershell -ExecutionPolicy Bypass -File scripts\restore-server-data.ps1
#
# Expects toro-db.sql.gz + toro-storage.tar.gz in the repo root. Keeps THIS
# machine's database credentials (the MySQL volume was initialized with
# them) and adopts the SOURCE machine's .env for everything else - most
# importantly APP_KEY, without which any encrypted payloads would be
# unreadable.

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot '_dot-env.ps1')

$repo = Split-Path -Parent $PSScriptRoot
$dbDump = Join-Path $repo 'toro-db.sql.gz'
$storagePack = Join-Path $repo 'toro-storage.tar.gz'

foreach ($file in $dbDump, $storagePack) {
    if (-not (Test-Path $file)) { Write-Error "Missing $file - run push-server-data.ps1 on the old server first." }
}

$sourceEnv = Get-DotEnvValues (Join-Path $PSScriptRoot 'toro-source.env')
$targetEnv = Get-DotEnvValues (Join-Path $repo '.env')

# Credentials that belong to THIS machine's MySQL volume stay; everything
# identity-bearing transfers.
$keepLocal = @('DB_PASSWORD', 'MYSQL_ROOT_PASSWORD', 'MYSQL_DATABASE', 'DB_USERNAME',
    'APP_URL', 'QR_BASE_URL', 'REVERB_HOST', 'FUNNEL_HOST')

$newLines = foreach ($key in $sourceEnv.Keys) {
    if ($keepLocal -contains $key) {
        "$key=$($targetEnv[$key])"
    } else {
        "$key=$($sourceEnv[$key])"
    }
}
# Preserve any target-only keys not present upstream (e.g. DUCKDNS_*).
foreach ($key in $targetEnv.Keys) {
    if (-not ($sourceEnv.ContainsKey($key)) -and $key -ne '') {
        $newLines += "$key=$($targetEnv[$key])"
    }
}

Set-Content (Join-Path $repo '.env') -Value ($newLines | Sort-Object -Unique) -Encoding ASCII

Write-Output '[restore] restarting app containers to pick up the merged .env...'
docker compose stop app queue scheduler reverb webserver | Out-Null
docker compose up -d --force-recreate app | Out-Null

$deadline = (Get-Date).AddMinutes(3)
while ((Get-Date) -lt $deadline) {
    docker compose exec -T app php artisan --version *>$null
    if ($LASTEXITCODE -eq 0) { break }
    Start-Sleep -Seconds 5
}

Write-Output '[restore] importing database...'
Get-Content $dbDump -Raw | docker compose exec -T db sh -c 'exec mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"'
if ($LASTEXITCODE -ne 0) { Write-Error 'mysql import failed.' }

Write-Output '[restore] extracting member photos / uploads...'
docker compose cp $storagePack app:/tmp/toro-storage.tar.gz | Out-Null
docker compose exec -T app sh -c 'mkdir -p /var/www/storage/app && tar -xzf /tmp/toro-storage.tar.gz -C /var/www/storage/app && rm /tmp/toro-storage.tar.gz'

Write-Output '[restore] bringing the full stack back up...'
docker compose up -d
Start-Sleep -Seconds 12

$users = docker compose exec -T app php artisan tinker --execute='echo \App\Models\User::count();' 2>$null
$members = docker compose exec -T app php artisan tinker --execute='echo \App\Models\Member::count();' 2>$null

Write-Output ''
Write-Output "================ TRANSFER COMPLETE ================"
Write-Output "users: $users | members: $members"
Write-Output 'Sanity-check a photo renders and one invoice opens, then'
Write-Output 'decommission the old server.'
