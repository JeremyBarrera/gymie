<#
.SYNOPSIS
    One-time HTTPS setup for the Gymie dev stack: installs mkcert + Caddy if
    missing, creates a locally-trusted certificate for localhost and the LAN
    IP, and prints the .env lines to enable it.

.DESCRIPTION
    Phones refuse camera access on plain http:// (secure context). This script
    prepares everything needed to serve the dev stack over https://:8443 via a
    Caddy TLS reverse proxy (web + websocket upgrades) using an mkcert
    locally-trusted certificate. Run .\start-dev.ps1 -Https afterwards.

    Tools are installed automatically via winget when missing
    (FiloSottile.mkcert, CaddyServer.Caddy). Re-run any time the LAN IP
    changes - the certificate is regenerated in place.

.EXAMPLE
    .\setup-dev-https.ps1
#>

$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent $MyInvocation.MyCommand.Path
$certDir = Join-Path $root 'storage\certs'
if (-not (Test-Path $certDir)) {
    New-Item -ItemType Directory -Path $certDir -Force | Out-Null
}

function Install-Tool([string]$Name, [string]$WingetId) {
    $existing = Get-Command $Name -ErrorAction SilentlyContinue
    if ($existing) {
        Write-Host "[$Name] found: $($existing.Source)" -ForegroundColor Green

        return
    }

    Write-Host "[$Name] not found - installing via winget..." -ForegroundColor Yellow
    winget install --id $WingetId --accept-source-agreements --accept-package-agreements --silent
    if ($LASTEXITCODE -ne 0) {
        Write-Error "winget failed to install $WingetId. Install it manually (scoop/choco/website), then re-run this script."
        exit 1
    }

    # Refresh PATH for the current session so the new binary is found.
    $env:Path = [Environment]::GetEnvironmentVariable('Path', 'Machine') + ';' + [Environment]::GetEnvironmentVariable('Path', 'User')
    if (-not (Get-Command $Name -ErrorAction SilentlyContinue)) {
        Write-Error "$Name was installed but is not on PATH yet. Open a new terminal and re-run this script."
        exit 1
    }

    Write-Host "[$Name] installed" -ForegroundColor Green
}

Install-Tool 'mkcert' 'FiloSottile.mkcert'
Install-Tool 'caddy' 'CaddyServer.Caddy'

# --- Trust the mkcert local CA (one-time per machine) ---
mkcert -install

# --- Resolve the LAN IP (site-local IPv4, no loopback/APIPA) ---
$lanIp = (Get-NetIPAddress -AddressFamily IPv4 -ErrorAction SilentlyContinue |
    Where-Object { $_.IPAddress -notlike '127.*' -and $_.IPAddress -notlike '169.254.*' } |
    Select-Object -First 1).IPAddress

$hosts = @('localhost', '127.0.0.1', '::1')
if ($lanIp) {
    $hosts += $lanIp
}

Write-Host ''
Write-Host "Generating certificate for: $($hosts -join ', ')" -ForegroundColor Cyan

mkcert -cert-file (Join-Path $certDir 'localhost.pem') `
    -key-file (Join-Path $certDir 'localhost-key.pem') `
    @hosts

Write-Host ''
Write-Host 'HTTPS dev setup ready.' -ForegroundColor Green
Write-Host ''
Write-Host 'Add/adjust these lines in .env, then restart the stack:' -ForegroundColor Cyan
if ($lanIp) {
    Write-Host "  APP_URL=https://$lanIp`:8443"
    Write-Host "  QR_BASE_URL=https://$lanIp`:8443"
} else {
    Write-Host '  APP_URL=https://localhost:8443'
    Write-Host '  QR_BASE_URL=https://localhost:8443'
}
Write-Host '  REVERB_HOST=localhost'
Write-Host '  REVERB_SCHEME=https'
Write-Host ''
Write-Host 'Then run:  .\start-dev.ps1 -Https' -ForegroundColor Cyan
Write-Host 'Phones on the same network open: https://' + $(if ($lanIp) { $lanIp } else { 'localhost' }) + ':8443'
