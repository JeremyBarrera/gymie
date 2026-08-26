# Prompts for the MySQL password locally (hidden input) and writes it to both
# DB_PASSWORD and MYSQL_ROOT_PASSWORD in .env. The value never appears on
# screen, in this script, or anywhere else.
#
# Run from the repo root:
#   powershell -ExecutionPolicy Bypass -File scripts\set-db-password.ps1

$ErrorActionPreference = 'Stop'

$repoRoot = Split-Path -Parent $PSScriptRoot
$envFile = Join-Path $repoRoot '.env'

$secure = Read-Host 'Choose the MySQL password (input hidden)' -AsSecureString
$plain = [System.Net.NetworkCredential]::new('', $secure).Password

if ($plain.Length -lt 12) {
    Write-Error 'Too short — use at least 12 characters.'
}

$lines = Get-Content $envFile

foreach ($key in 'DB_PASSWORD', 'MYSQL_ROOT_PASSWORD') {
    $idx = [array]::FindIndex($lines, [Predicate[string]] { param($l) $l.StartsWith("$key=") })
    if ($idx -ge 0) {
        $lines[$idx] = "$key=$plain"
    } else {
        $lines += "$key=$plain"
    }
}

Set-Content $envFile -Value $lines -Encoding ASCII

Write-Output 'Done: DB_PASSWORD and MYSQL_ROOT_PASSWORD are set (identical values).'
Write-Output 'Next: docker compose -f docker-compose.yml -f docker-compose.https.yml --profile https up -d'
