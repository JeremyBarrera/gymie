# One-shot production bootstrap for the Toro GYM server PC.
# Run ONCE from an ELEVATED PowerShell:
#   powershell -ExecutionPolicy Bypass -File scripts\register-production-tasks.ps1
#
# Registers: inbound firewall rules (80/443) + the DuckDNS updater task.
# Idempotent — safe to re-run.

$ErrorActionPreference = 'Stop'

if (-not ([Security.Principal.WindowsPrincipal] [Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    Write-Error 'Run this script from an elevated (Administrator) PowerShell.'
}

& (Join-Path $PSScriptRoot 'register-firewall-rules.ps1')
& (Join-Path $PSScriptRoot 'register-duckdns-updater.ps1')

Write-Output ''
Write-Output 'Bootstrap complete.'
