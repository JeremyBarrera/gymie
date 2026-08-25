# Registers a Windows scheduled task that runs update-duckdns.ps1 every 5
# minutes as SYSTEM, so torogym.duckdns.org tracks this machine's rotating
# public IP without anyone remembering to click "update ip".

$ErrorActionPreference = 'Stop'

$repoRoot = Split-Path -Parent $PSScriptRoot
$scriptPath = Join-Path $PSScriptRoot 'update-duckdns.ps1'

$action = New-ScheduledTaskAction `
    -Execute 'powershell.exe' `
    -Argument "-NoProfile -WindowStyle Hidden -ExecutionPolicy Bypass -File `"$scriptPath`"" `
    -WorkingDirectory $repoRoot

$trigger = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1)
$repetition = (New-ScheduledTaskTrigger -Once -At (Get-Date) -RepetitionInterval (New-TimeSpan -Minutes 5)).Repetition
$trigger.Repetition = $repetition

$principal = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -RunLevel Highest

Register-ScheduledTask `
    -TaskName 'GymieDuckDNSUpdater' `
    -Action $action `
    -Trigger $trigger `
    -Principal $principal `
    -Description 'Keeps torogym.duckdns.org pointed at this machine''s current public IP.' `
    -Force | Out-Null

Write-Output "Registered task 'GymieDuckDNSUpdater' (every 5 min, as SYSTEM)."
Write-Output "It stays idle until DUCKDNS_DOMAIN + DUCKDNS_TOKEN are filled in .env."
