$ErrorActionPreference = "Stop"

if ((git rev-parse --abbrev-ref HEAD) -ne "dev") {
    Write-Host "Switching to dev..."
    git checkout dev
}

Write-Host "Updating public-clean from dev..."
git checkout public-clean
git merge dev --no-edit --no-ff | Out-Null

$filterRepoArgs = @("--invert-paths", "--path", "AGENTS.md", "--path", "TORO_GYM_PLAN.md", "--path", "LOCATION_DRIVEN_RBAC_PLAN.md", "--path", "MEMBER_STATUS_PLAN.md", "--path", "RECEPTION_SYSTEM_PLAN.md", "--path-glob", "*_PLAN.md", "--path", "docs/features/subscription-quantity-chaining.md", "--path", "docs/planning", "--force", "--refs", "public-clean")
$filterRepoExe = Get-Command git-filter-repo -ErrorAction SilentlyContinue
python -m git_filter_repo --version 2>$null | Out-Null
$filterRepoModule = $?
if ($filterRepoExe) {
    Write-Host "filter-repo found - rewriting public-clean history to exclude internal files..."
    git filter-repo @filterRepoArgs
} elseif ($filterRepoModule) {
    Write-Host "filter-repo module found - rewriting public-clean history to exclude internal files..."
    python -m git_filter_repo @filterRepoArgs
} else {
    throw "git-filter-repo is required to sync public-clean (found neither the binary nor the python module). Aborting before any push so public history is never published without the rewrite."
}

$publicShort = git rev-parse --short HEAD
$devShort = git rev-parse --short dev
Write-Host "Public-clean branch now at $publicShort"
Write-Host "For agent-review PR push branch with: git push origin public-clean"
Write-Host "To publish directly use: git push origin public-clean:main --force"
Write-Host "Private dev remains source of truth at $devShort"
git checkout dev
