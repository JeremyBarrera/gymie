# Updates the DuckDNS DNS record for DUCKDNS_DOMAIN with this machine's
# current public IP. Reads DUCKDNS_DOMAIN and DUCKDNS_TOKEN from .env, so no
# secret lives in this script or the Task Scheduler definition.
#
# Install (once, as admin):
#   powershell -ExecutionPolicy Bypass -File scripts\register-duckdns-updater.ps1
#
# Manual run:
#   powershell -ExecutionPolicy Bypass -File scripts\update-duckdns.ps1

$ErrorActionPreference = 'Stop'

$repoRoot = Split-Path -Parent $PSScriptRoot
$logPath = Join-Path $repoRoot 'storage\logs\duckdns-update.log'

function Write-Log([string]$message) {
    "$((Get-Date).ToString('s')) $message" | Add-Content -Path $logPath
}

$envLines = Get-Content (Join-Path $repoRoot '.env')
$value = {
    param([string]$key)
    $line = $envLines | Where-Object { $_ -match "^$key=(.*)$" } | Select-Object -First 1
    if ($null -eq $line) { return '' }
    $Matches[1].Trim()
}

$domain = & $value 'DUCKDNS_DOMAIN'
$token = & $value 'DUCKDNS_TOKEN'

if ([string]::IsNullOrWhiteSpace($domain) -or [string]::IsNullOrWhiteSpace($token)) {
    Write-Log 'SKIP: DUCKDNS_DOMAIN or DUCKDNS_TOKEN missing in .env'
    exit 1
}

try {
    $response = Invoke-WebRequest -UseBasicParsing -TimeoutSec 20 `
        -Uri "https://www.duckdns.org/update?domains=$domain&token=$token&verbose=1&ip="

    # PS 5.1 hands back a byte array when the response lacks charset info.
    $body = if ($response.Content -is [byte[]]) {
        [System.Text.Encoding]::UTF8.GetString($response.Content)
    } else {
        $response.Content
    }

    # DuckDNS verbose responses look like "OK\n<ip>".
    $lines = $body -split "`n"
    $result = $lines[0].Trim()
    $ip = if ($lines.Count -gt 1) { $lines[1].Trim() } else { '?' }

    if ($result -eq 'OK') {
        Write-Log "OK: $domain.duckdns.org -> $ip"
        exit 0
    }

    Write-Log "FAIL: unexpected response '$($body.Trim())'"
    exit 1
} catch {
    Write-Log "ERROR: $($_.Exception.Message)"
    exit 1
}
