# Syncs the nightly MySQL dumps (backups volume) and uploaded member photos
# (storage/app) to Google Drive via rclone. Requires a one-time interactive
# setup first:  rclone config   → name the remote "gdrive".
#
# Schedule daily after the container's 03:00 dump window, e.g.:
#   schtasks /Create /TN GymieOffsiteBackup /SC DAILY /ST 04:00 ^
#     /TR "powershell -NoProfile -ExecutionPolicy Bypass -File C:\gymie\scripts\backup-to-gdrive.ps1"
#
# Restore drill (do it once for real):
#   1. rclone copy gdrive:gymie/backups <local dir>
#   2. gunzip < latest .sql.gz
#   3. docker compose exec -T db mysql -u$DB_USERNAME -p$DB_PASSWORD $DB_DATABASE < dump.sql
#   4. rclone copy gdrive:gymie/storage storage/app/

$ErrorActionPreference = 'Stop'

$repoRoot = Split-Path -Parent $PSScriptRoot
$remote = 'gdrive'
$stamp = Get-Date -Format 'yyyy-MM-dd'

$mounts = @(
    @{ Name = 'backups'; Local = (Join-Path $env:TEMP 'gymie-backups'); Target = "$remote`:gymie/backups" },
    @{ Name = 'storage'; Local = (Join-Path $env:TEMP 'gymie-storage'); Target = "$remote`:gymie/storage" }
)

foreach ($mount in $mounts) {
    New-Item -ItemType Directory -Force -Path $mount.Local | Out-Null

    docker compose cp "backups:/backups/." $mount.Local
    if ($LASTEXITCODE -ne 0) { Write-Error "docker compose cp failed for $($mount.Name)" }

    # Photos live on the host bind; only copy if the volume path was used.
    if ($mount.Name -eq 'storage') {
        $hostStorage = Join-Path $repoRoot 'storage\app'
        if (Test-Path $hostStorage) {
            Copy-Item -Path (Join-Path $hostStorage '*') -Destination $mount.Local -Recurse -Force -ErrorAction SilentlyContinue
        }
    }

    rclone sync $mount.Local $mount.Target --backup-dir "$($mount.Target)/previous/$stamp" --log-file (Join-Path $repoRoot 'storage\logs\rclone.log') --log-level INFO

    if ($LASTEXITCODE -eq 0) {
        Write-Output "$($mount.Name): synced to $($mount.Target)"
    } else {
        Write-Output "$($mount.Name): rclone FAILED — check storage\logs\rclone.log"
    }
}
