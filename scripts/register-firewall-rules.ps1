# Adds inbound firewall rules for the gym app (HTTP 80 for ACME challenges,
# HTTPS 443 for traffic). Run once from an ELEVATED PowerShell:
#   powershell -ExecutionPolicy Bypass -File scripts\register-firewall-rules.ps1

$ErrorActionPreference = 'Stop'

if (-not ([Security.Principal.WindowsPrincipal] [Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    Write-Error 'Run this script from an elevated (Administrator) PowerShell.'
}

foreach ($port in 80, 443) {
    $existing = Get-NetFirewallRule -DisplayName "Gymie $port" -ErrorAction SilentlyContinue

    if ($existing) {
        Write-Output "Rule 'Gymie $port' already exists - skipping."
        continue
    }

    New-NetFirewallRule `
        -DisplayName "Gymie $port" `
        -Direction Inbound `
        -Protocol TCP `
        -LocalPort $port `
        -Action Allow `
        -Profile Any | Out-Null

    Write-Output "Created inbound TCP $port rule."
}
