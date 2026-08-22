<#
.SYNOPSIS
    Stops the Gymie development stack started by start-dev.ps1
    (web server + Reverb + queue worker + optional Vite).
#>

$root = Split-Path -Parent $MyInvocation.MyCommand.Path
$pidsFile = Join-Path $root 'storage\logs\.gymie-dev.pids.json'

if (-not (Test-Path $pidsFile)) {
    Write-Host 'No Gymie dev stack pid file found - stopping by port lookup...' -ForegroundColor Yellow
}

$pids = @{}
if (Test-Path $pidsFile) {
    $pids = Get-Content $pidsFile -Raw | ConvertFrom-Json
}

foreach ($prop in $pids.PSObject.Properties) {
    $pidValue = [int]$prop.Value
    $cim = Get-CimInstance Win32_Process -Filter "ProcessId = $pidValue" -ErrorAction SilentlyContinue
    if (-not $cim) {
        Write-Host "[$($prop.Name)] not running (pid $pidValue)" -ForegroundColor Yellow
        continue
    }
    if ($cim.CommandLine -match 'artisan|npm run dev') {
        Stop-Process -Id $pidValue -Force -ErrorAction SilentlyContinue
        Write-Host "[$($prop.Name)] stopped (pid $pidValue)" -ForegroundColor Green
    } else {
        Write-Host "[$($prop.Name)] pid $pidValue is no longer a Gymie process - skipped" -ForegroundColor Yellow
    }
}

# Also kill anything still holding our ports (covers stale instances the pid
# file never knew about, e.g. a server started manually in a terminal).
foreach ($port in 8000, 8080) {
    $conns = Get-NetTCPConnection -LocalPort $port -State Listen -ErrorAction SilentlyContinue
    foreach ($c in $conns) {
        $proc = Get-CimInstance Win32_Process -Filter "ProcessId = $($c.OwningProcess)" -ErrorAction SilentlyContinue
        if ($proc -and $proc.CommandLine -match 'artisan (serve|reverb)|php.* -S ') {
            Stop-Process -Id $c.OwningProcess -Force -ErrorAction SilentlyContinue
            Write-Host "[cleanup] stopped stale listener on :$port (pid $($c.OwningProcess))" -ForegroundColor Green
        }
    }
}

Remove-Item $pidsFile -Force -ErrorAction SilentlyContinue
Write-Host 'Done.'