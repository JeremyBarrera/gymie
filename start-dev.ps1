<#
.SYNOPSIS
    Starts the Gymie development stack with one command:
    web server + Reverb websocket server + queue worker (background daemons).

.DESCRIPTION
    Launches `php artisan serve`, `php artisan reverb:start` and
    `php artisan queue:work` as hidden background processes, logging to
    storage/logs. Processes already running are detected and skipped, so the
    script is safe to run repeatedly. Use stop-dev.ps1 to stop everything.

.PARAMETER Vite
    Also start `npm run dev` (Vite hot reload). Off by default (built assets
    from `npm run build` are used).

.PARAMETER BindHost
    Host the web server binds to. Default 0.0.0.0 (reachable from other
    devices on the LAN, e.g. a tablet at the reception desk).

.EXAMPLE
    .\start-dev.ps1
    .\start-dev.ps1 -Vite
#>
[CmdletBinding()]
param(
    [switch]$Vite,
    [string]$BindHost = '0.0.0.0'
)

$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent $MyInvocation.MyCommand.Path
$logDir = Join-Path $root 'storage\logs'
if (-not (Test-Path $logDir)) {
    New-Item -ItemType Directory -Path $logDir -Force | Out-Null
}

$php = (Get-Command php -ErrorAction SilentlyContinue).Source
if (-not $php) {
    Write-Error 'php was not found on PATH. Install PHP first.'
    exit 1
}

$pids = @{}
$pidsFile = Join-Path $logDir '.gymie-dev.pids.json'
if (Test-Path $pidsFile) {
    try { $pids = Get-Content $pidsFile -Raw | ConvertFrom-Json -AsHashtable } catch { $pids = @{} }
}

function Save-Pids {
    $pids | ConvertTo-Json | Set-Content -Path $pidsFile -Encoding UTF8
}

Write-Host 'Gymie dev stack' -ForegroundColor Cyan

# --- Kill stale php listeners on our ports (php -S allows multiple binds; a
# stale instance on 8000/8080 silently hijacks a share of connections and
# causes "server stopped responding" flakiness) ---
function Kill-StaleListeners([int]$Port) {
    $conns = Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction SilentlyContinue
    foreach ($c in $conns) {
        $proc = Get-CimInstance Win32_Process -Filter "ProcessId = $($c.OwningProcess)" -ErrorAction SilentlyContinue
        if ($proc -and $proc.CommandLine -match 'artisan (serve|reverb)|php.* -S ') {
            Stop-Process -Id $c.OwningProcess -Force -ErrorAction SilentlyContinue
            Write-Host "[cleanup] killed stale listener on :$Port (pid $($c.OwningProcess))" -ForegroundColor Yellow
        }
    }
}
Kill-StaleListeners 8000
Kill-StaleListeners 8080
Start-Sleep -Seconds 1

# --- Web server (php artisan serve, port 8000) ---
if (Get-NetTCPConnection -LocalPort 8000 -State Listen -ErrorAction SilentlyContinue) {
    Write-Host '[serve]  already running on :8000 - skipped' -ForegroundColor Yellow
} else {
    $p = Start-Process -FilePath $php `
        -ArgumentList @('artisan', 'serve', "--host=$BindHost", '--port=8000', '--no-reload') `
        -WorkingDirectory $root -WindowStyle Hidden `
        -RedirectStandardOutput (Join-Path $logDir 'gymie-serve.log') `
        -RedirectStandardError (Join-Path $logDir 'gymie-serve-error.log') `
        -PassThru
    Start-Sleep -Seconds 2
    $served = Get-NetTCPConnection -LocalPort 8000 -State Listen -ErrorAction SilentlyContinue
    if (-not $served) {
        Write-Host '[serve]  FAILED to start - see gymie-serve-error.log' -ForegroundColor Red
    } else {
        $pids['serve'] = $p.Id
        Save-Pids
        Write-Host "[serve]  started (pid $($p.Id), http://localhost:8000)" -ForegroundColor Green
    }
}

# --- Reverb websocket server (port 8080) ---
if (Get-NetTCPConnection -LocalPort 8080 -State Listen -ErrorAction SilentlyContinue) {
    Write-Host '[reverb] already running on :8080 - skipped' -ForegroundColor Yellow
} else {
    $p = Start-Process -FilePath $php `
        -ArgumentList @('artisan', 'reverb:start') `
        -WorkingDirectory $root -WindowStyle Hidden `
        -RedirectStandardOutput (Join-Path $logDir 'gymie-reverb.log') `
        -RedirectStandardError (Join-Path $logDir 'gymie-reverb-error.log') `
        -PassThru
    $pids['reverb'] = $p.Id
    Save-Pids
    Write-Host "[reverb] started (pid $($p.Id), ws://localhost:8080)" -ForegroundColor Green
}

# --- Queue worker ---
$workers = Get-CimInstance Win32_Process -Filter "Name = 'php.exe'" |
    Where-Object { $_.CommandLine -match 'artisan (queue:work|queue:listen)' }
if ($workers) {
    Write-Host '[queue]  worker already running - skipped' -ForegroundColor Yellow
} else {
    $p = Start-Process -FilePath $php `
        -ArgumentList @('artisan', 'queue:work', '--sleep=1', '--tries=3') `
        -WorkingDirectory $root -WindowStyle Hidden `
        -RedirectStandardOutput (Join-Path $logDir 'gymie-queue.log') `
        -RedirectStandardError (Join-Path $logDir 'gymie-queue-error.log') `
        -PassThru
    $pids['queue'] = $p.Id
    Save-Pids
    Write-Host "[queue]  started (pid $($p.Id))" -ForegroundColor Green
}

# --- Vite (optional, hot reload) ---
if ($Vite) {
    $npm = (Get-Command npm -ErrorAction SilentlyContinue).Source
    if ($npm) {
        $p = Start-Process -FilePath $npm `
            -ArgumentList @('run', 'dev') `
            -WorkingDirectory $root -WindowStyle Hidden `
            -RedirectStandardOutput (Join-Path $logDir 'gymie-vite.log') `
            -RedirectStandardError (Join-Path $logDir 'gymie-vite-error.log') `
            -PassThru
        $pids['vite'] = $p.Id
        Save-Pids
        Write-Host "[vite]   started (pid $($p.Id))" -ForegroundColor Green
    } else {
        Write-Host '[vite]   npm not found - skipped' -ForegroundColor Yellow
    }
}

Write-Host ''
Write-Host 'Running:'
Write-Host '  App:       http://localhost:8000'
Write-Host '  Reverb WS: ws://localhost:8080'
Write-Host '  Logs:      storage/logs/gymie-*.log'
Write-Host '  Stop all:  .\stop-dev.ps1'
Write-Host ''
Write-Host 'Note: Reverb + the queue worker are required for the live reception' -ForegroundColor DarkGray
Write-Host 'popup and the realtime check-in/signup flow. In production, run them' -ForegroundColor DarkGray
Write-Host 'under a process supervisor - see README "Production".' -ForegroundColor DarkGray