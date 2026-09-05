# Toro GYM - new Windows server provisioner.
#
# Run ON THE NEW DESKTOP, from the cloned repository root, in an ELEVATED
# PowerShell:
#
#   powershell -ExecutionPolicy Bypass -File scripts\provision-new-server.ps1 -MachineName gym-server
#
# Installs Git/Docker Desktop/Tailscale if missing, joins the tailnet,
# scaffolds .env, builds the stack, runs migrations, and prints the exact
# funnel/serve commands left to approve. Idempotent - safe to re-run.

param(
    [Parameter(Mandatory = $true)]
    [string]$MachineName
)

$ErrorActionPreference = 'Stop'
$ts = 'C:\Program Files\Tailscale\tailscale.exe'

function Ensure-WingetPackage([string]$id, [string]$name) {
    winget list --id $id --exact 2>$null | Out-Null
    if ($LASTEXITCODE -ne 0) {
        Write-Output "Installing $name..."
        winget install --id $id --exact --silent --accept-package-agreements --accept-source-agreements | Out-Null
    } else {
        Write-Output "$name already installed."
    }
}

if (-not ([Security.Principal.WindowsPrincipal] [Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    Write-Error 'Run from an elevated (Administrator) PowerShell.'
}

Ensure-WingetPackage 'Git.Git' 'Git'
Ensure-WingetPackage 'Docker.DockerDesktop' 'Docker Desktop'
Ensure-WingetPackage 'tailscale.tailscale' 'Tailscale'

Write-Output 'Starting Docker Desktop and waiting for the engine...'
Start-Process 'C:\Program Files\Docker\Docker\Docker Desktop.exe' -ErrorAction SilentlyContinue
$deadline = (Get-Date).AddMinutes(5)
while ((Get-Date) -lt $deadline) {
    docker info *>$null
    if ($LASTEXITCODE -eq 0) { break }
    Start-Sleep -Seconds 10
}
if ($LASTEXITCODE -ne 0) { Write-Error 'Docker engine did not come up within 5 minutes.' }

Write-Output 'Setting Tailscale hostname and waiting for you to LOG IN (browser opens)...'
& $ts set --hostname=$MachineName 2>$null | Out-Null
& $ts up --timeout=300s 2>&1 | Out-Null
$deadline = (Get-Date).AddMinutes(5)
$suffix = ''
while ((Get-Date) -lt $deadline) {
    try {
        $json = & $ts status --json 2>$null | ConvertFrom-Json
        if ($json.BackendState -eq 'Running' -and $json.MagicDNSSuffix) {
            $suffix = $json.MagicDNSSuffix
            break
        }
    } catch {}
    Start-Sleep -Seconds 5
}
if (-not $suffix) { Write-Error 'Tailscale login did not complete within 5 minutes.' }

$fqdn = "$MachineName.$suffix"
Write-Output "This server is now: $fqdn"

Write-Output 'Enabling OpenSSH Server (lets the old server push its data with one command)...'
$sshCap = Get-WindowsCapability -Online -Name 'OpenSSH.Server*' -ErrorAction SilentlyContinue
if ($sshCap -and $sshCap[0].State -ne 'Installed') {
    Add-WindowsCapability -Online -Name $sshCap[0].Name | Out-Null
}
Set-Service sshd -StartupType Automatic -ErrorAction SilentlyContinue
Start-Service sshd -ErrorAction SilentlyContinue
if (-not (Get-NetFirewallRule -DisplayName 'OpenSSH Server (Toro)' -ErrorAction SilentlyContinue)) {
    New-NetFirewallRule -DisplayName 'OpenSSH Server (Toro)' -Direction Inbound -Protocol TCP -LocalPort 22 -Action Allow -Profile Any | Out-Null
}

if (-not (Test-Path '.env')) {
    Copy-Item .env.example .env
    Write-Output @'

===========================================================
  .env created from the example. EDIT IT NOW before the
  stack starts, then re-run this script:

    notepad .env

  Required lines:
    DB_PASSWORD= / MYSQL_ROOT_PASSWORD=   -> or run:
      powershell -ExecutionPolicy Bypass -File scripts\set-db-password.ps1
    OWNER_NAME / OWNER_EMAIL / OWNER_PASSWORD (first admin login)
    APP_KEY=                               -> leave blank; the script
      generates it after the stack boots.
===========================================================
'@
    exit 0
}

function Add-EnvLine([string]$file, [string]$line) {
    $key = ($line -split '=')[0]
    $lines = Get-Content $file
    $idx = [array]::FindIndex($lines, [Predicate[string]] { param($l) $l.StartsWith("$key=") })
    if ($idx -ge 0) { $lines[$idx] = $line } else { $lines += $line }
    Set-Content $file -Value $lines -Encoding ASCII
}

Add-EnvLine '.env' "APP_URL=https://$fqdn"
Add-EnvLine '.env' "QR_BASE_URL=https://$fqdn"
Add-EnvLine '.env' "REVERB_HOST=$fqdn"
Add-EnvLine '.env' "FUNNEL_HOST=$fqdn"

Write-Output 'Building the app image (first run takes several minutes)...'
docker compose -f docker-compose.yml -f docker-compose.https.yml --profile https build app | Out-Null

Write-Output 'Starting the stack...'
docker compose -f docker-compose.yml -f docker-compose.https.yml --profile https up -d

$appKey = (Select-String -Path '.env' -Pattern '^APP_KEY=(.+)$')
if (-not $appKey -or [string]::IsNullOrWhiteSpace($appKey.Matches.Groups[1].Value)) {
    docker compose exec app php artisan key:generate --force | Out-Null
    docker compose restart app queue scheduler reverb | Out-Null
    Start-Sleep -Seconds 15
}

Write-Output 'Running migrations...'
docker compose exec app php artisan migrate --force

Write-Output 'Publishing assets to the webserver volume...'
docker compose restart webserver | Out-Null
Start-Sleep -Seconds 6

Write-Output ''
Write-Output '================ LAST MANUAL STEPS ================'
Write-Output '1. Approve Funnel for THIS node (opens the right page):'
& $ts funnel --bg 80 2>&1 | Select-String 'login.tailscale|Available|proxy'
Write-Output '2. Then expose both doors:'
& $ts funnel --bg 80 2>&1 | Select-Object -First 5
& $ts serve --bg --https=8443 http://127.0.0.1:81 2>&1 | Select-Object -First 4
Write-Output '3. From any phone (WiFi OFF) open:'
Write-Output "   https://$fqdn/contact-front-desk"
Write-Output '4. Admin panel (enrolled devices only):'
Write-Output "   https://$fqdn`:8443"
Write-Output '===================================================='
