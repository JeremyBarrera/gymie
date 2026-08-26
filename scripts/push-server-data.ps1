# THE one-command data handoff: dumps the database, packs uploads, ships
# both to the NEW server over the tailnet, and triggers the restore there.
#
# Run ON THE OLD SERVER (this laptop) AFTER the new desktop has been through
# scripts\provision-new-server.ps1:
#
#   powershell -ExecutionPolicy Bypass -File scripts\push-server-data.ps1 `
#       -TargetHost torogym-gym.tailXXXX.ts.net -TargetUser <windows-user-on-new-box>
#
# Requirements on the new box: OpenSSH Server (the provisioner enables it)
# and the repository cloned at C:\gymie.

param(
    [Parameter(Mandatory = $true)][string]$TargetHost,
    [Parameter(Mandatory = $true)][string]$TargetUser,
    [string]$TargetRepo = 'C:/gymie'
)

$ErrorActionPreference = 'Stop'
$repo = Split-Path -Parent $PSScriptRoot
$stamp = Get-Date -Format 'yyyyMMdd-HHmm'

Write-Output '[push 1/4] dumping database...'
docker compose exec db sh -c 'exec mysqldump -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" --single-transaction "$MYSQL_DATABASE"' | Out-File -Encoding ascii (Join-Path $env:TEMP 'toro-db.sql')
if ($LASTEXITCODE -ne 0) { Write-Error 'mysqldump failed.' }

tar -czf (Join-Path $env:TEMP 'toro-db.sql.gz') -C $env:TEMP 'toro-db.sql'
Remove-Item (Join-Path $env:TEMP 'toro-db.sql')

Write-Output '[push 2/4] packing member photos / uploads...'
tar -czf (Join-Path $env:TEMP 'toro-storage.tar.gz') -C storage 'app'

Copy-Item (Join-Path $repo '.env') (Join-Path $env:TEMP 'toro-source.env') -Force

Write-Output '[push 3/4] shipping package over the tailnet...'
ssh "$TargetUser@$TargetHost" "mkdir -p /c/gymie 2>/dev/null; mkdir -p '$($TargetRepo -replace '\\','/')' 2>/dev/null" | Out-Null
foreach ($file in 'toro-db.sql.gz', 'toro-storage.tar.gz', 'toro-source.env') {
    scp (Join-Path $env:TEMP $file) "${TargetUser}@${TargetHost}:${TargetRepo}/$file"
    if ($LASTEXITCODE -ne 0) { Write-Error "scp failed for $file." }
    Remove-Item (Join-Path $env:TEMP $file)
}

Write-Output '[push 4/4] restoring on the new server...'
ssh "$TargetUser@$TargetHost" "cd /c/gymie && powershell -ExecutionPolicy Bypass -File scripts\\restore-server-data.ps1"

Write-Output ''
Write-Output 'Handoff finished. Verify the new server, then retire this one.'
