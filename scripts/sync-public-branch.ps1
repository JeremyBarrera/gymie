# Note: requires git filter-repo (pip install git-filter-repo) or falls back to git rm
# Usage: powershell -File scripts/sync-public-branch.ps1
# Keeps public-clean as a filtered view of dev without internal docs.
# dev is source of truth, never edit public-clean directly.

$ErrorActionPreference = "Stop"

if ((git rev-parse --abbrev-ref HEAD) -ne "dev") {
    Write-Host "Switching to dev..."
    git checkout dev
}

Write-Host "Updating public-clean from dev..."
git checkout public-clean
git merge dev --no-edit --no-ff | Out-Null

# If filter-repo is available, do a proper history rewrite for the public branch
if (Get-Command git-filter-repo -ErrorAction SilentlyContinue) {
    Write-Host "filter-repo found — rewriting public-clean history to exclude internal files..."
    # This rewrites public-clean to drop the files, preserving dev history
    git filter-repo --invert-paths --path AGENTS.md --path TORO_GYM_PLAN.md --path LOCATION_DRIVEN_RBAC_PLAN.md --path MEMBER_STATUS_PLAN.md --path RECEPTION_SYSTEM_PLAN.md --path-glob "*_PLAN.md" --path docs/features/subscription-quantity-chaining.md --path docs/planning --force --refs public-clean
} else {
    Write-Host "filter-repo not found — falling back to git rm (no history rewrite)..."
    $toRemove = @(
        "AGENTS.md","TORO_GYM_PLAN.md","LOCATION_DRIVEN_RBAC_PLAN.md","MEMBER_STATUS_PLAN.md","RECEPTION_SYSTEM_PLAN.md",
        "docs/features/subscription-quantity-chaining.md","docs/planning"
    ) | Where-Object { Test-Path $_ }
    if ($toRemove) {
        git rm --cached $toRemove 2>$null | Out-Null
        git rm $toRemove 2>$null | Out-Null
        if ((git status --porcelain)) {
            git commit -m "chore(public): sync from dev, exclude internal docs"
        }
    }
}

$publicShort = git rev-parse --short HEAD
$devShort = git rev-parse --short dev
Write-Host "Public-clean branch now at $publicShort"
Write-Host "For agent-review PR push branch with: git push origin public-clean"
Write-Host "To publish directly use: git push origin public-clean:main --force"
Write-Host "Private dev remains source of truth at $devShort"
git checkout dev
