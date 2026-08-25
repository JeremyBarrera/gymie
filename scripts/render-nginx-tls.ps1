# Renders docker/nginx-tls.rendered.conf from nginx-tls.conf.template by
# substituting ${DUCKDNS_DOMAIN} with the value from .env. Run before bringing
# up the https compose profile (see docker-compose.https.yml header).

$ErrorActionPreference = 'Stop'

$repoRoot = Split-Path -Parent $PSScriptRoot
$envFile = Join-Path $repoRoot '.env'
$templatePath = Join-Path $repoRoot 'docker\nginx-tls.conf.template'
$renderedPath = Join-Path $repoRoot 'docker\nginx-tls.rendered.conf'

if (-not (Test-Path $envFile)) {
    Write-Error ".env not found at $envFile"
}

$domain = (Select-String -Path $envFile -Pattern '^DUCKDNS_DOMAIN=(.+)$').Matches.Groups[1].Value.Trim()

if ([string]::IsNullOrWhiteSpace($domain)) {
    Write-Error 'DUCKDNS_DOMAIN is empty in .env — fill it in before rendering.'
}

$rendered = (Get-Content $templatePath -Raw) -replace '\$\{DUCKDNS_DOMAIN\}', $domain
Set-Content -Path $renderedPath -Value $rendered -Encoding ASCII -NoNewline

Write-Output "Rendered $renderedPath for ${domain}.duckdns.org"
