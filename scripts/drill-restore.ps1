# Restore drill: proves the backup/transfer pipeline actually works, in
# complete isolation from the live stack.
#
#   powershell -ExecutionPolicy Bypass -File scripts\drill-restore.ps1
#
# 1. Dumps the LIVE database (--single-transaction) and packs storage/app.
# 2. Boots a THROWAWAY compose project (-p toro-drill: own network/volumes,
#    no published host ports) with an empty MySQL.
# 3. Imports the dump - pure restore, no migrations, exactly like disaster
#    recovery.
# 4. Compares row counts against the live stack and extracts the uploads,
#    counting photo files.
# 5. Tears everything down including volumes.

$ErrorActionPreference = 'Stop'
$repo = Split-Path -Parent $PSScriptRoot
Set-Location $repo

$project = 'toro-drill'
$tmp = Join-Path $env:TEMP ('toro-drill-' + (Get-Date -Format 'yyyyMMdd-HHmmss'))
New-Item -ItemType Directory -Force -Path $tmp | Out-Null

function Step([string]$message) { Write-Output "`n=== $message ===" }

Step '1/5 capturing LIVE row counts'
function Get-LiveCount([string]$table) {
    docker compose exec -T db sh -c "MYSQL_PWD=`$MYSQL_ROOT_PASSWORD exec mysql --user=root -N -e 'SELECT COUNT(*) FROM $table' `$MYSQL_DATABASE" 2>$null
}
$liveCounts = (Get-LiveCount 'users'), (Get-LiveCount 'members'), (Get-LiveCount 'invoices') -join '|'


Step '2/5 dumping live DB + packing uploads'
docker compose exec db sh -c 'MYSQL_PWD=$MYSQL_ROOT_PASSWORD exec mysqldump --user=root --single-transaction $MYSQL_DATABASE' |
    Out-File -Encoding ascii (Join-Path $tmp 'drill-db.sql')
if ($LASTEXITCODE -ne 0) { Write-Error 'live mysqldump failed' }
tar -czf (Join-Path $tmp 'drill-storage.tar.gz') -C storage 'app'

Step '3/5 booting isolated MySQL + importing dump (pure restore)'
docker compose -p $project up -d db | Out-Null
$deadline = (Get-Date).AddMinutes(2)
while ((Get-Date) -lt $deadline) {
    docker compose -p $project exec -T db mysqladmin ping -h localhost --silent 2>$null
    if ($LASTEXITCODE -eq 0) { break }
    Start-Sleep -Seconds 3
}
Get-Content (Join-Path $tmp 'drill-db.sql') -Raw |
    docker compose -p $project exec -T db sh -c 'MYSQL_PWD=$MYSQL_ROOT_PASSWORD exec mysql --user=root $MYSQL_DATABASE'
if ($LASTEXITCODE -ne 0) { docker compose -p $project down -v; Write-Error 'restore import failed' }

Step '4/5 comparing restored counts + extracting uploads'
function Get-RestoredCount([string]$table) {
    docker compose -p $project exec -T db sh -c "MYSQL_PWD=`$MYSQL_ROOT_PASSWORD exec mysql --user=root -N -e 'SELECT COUNT(*) FROM $table' `$MYSQL_DATABASE" 2>$null
}
$restored = ((Get-RestoredCount 'users'), (Get-RestoredCount 'members'), (Get-RestoredCount 'invoices')) -join '|'
Write-Output "restored                      : $restored"

docker run --rm -v "${project}_app-storage:/data" -v "${tmp}:/pkg" alpine sh -c '
    mkdir -p /data/app &&
    tar -xzf /pkg/drill-storage.tar.gz -C /data &&
    echo "photo files restored         : $(find /data/app -type f \( -name \"*.jpg\" -o -name \"*.png\" -o -name \"*.jpeg\" -o -name \"*.webp\" \) | wc -l)"
'

if ($restored -eq $liveCounts) {
    Write-Output "`nRESULT: PASS - restored counts match live exactly."
} else {
    Write-Output "`nRESULT: FAIL - counts differ!"
    exit 1
}

Step '5/5 tearing down drill project (volumes included)'
docker compose -p $project down -v | Out-Null
Remove-Item $tmp -Recurse -Force
