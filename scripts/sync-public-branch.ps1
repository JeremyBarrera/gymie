#Requires git filter-repo (pip install git-filter-repo) or falls back to git rm
# Usage: powershell -File scripts/sync-public-branch.ps1
# Keeps `public` as a filtered view of `dev` without internal docs.

$ErrorActionPreference = "Stop"

if ((git rev-parse --abbrev-ref HEAD) -ne "dev") {
    Write-Host "Switching to dev..."
    git checkout dev
}

Write-Host "Updating public from dev..."
git checkout public
git merge dev --no-edit --no-ff | Out-Null

# If filter-repo is available, do a proper history rewrite for the public branch
if (Get-Command git-filter-repo -ErrorAction SilentlyContinue) {
    Write-Host "filter-repo found — rewriting public history to exclude internal files..."
    # This rewrites the last merge commit on public to drop the files, preserving dev history
    git filter-repo --invert-paths --path AGENTS.md --path TORO_GYM_PLAN.md --path LOCATION_DRIVEN_RBAC_PLAN.md --path MEMBER_STATUS_PLAN.md --path RECEPTION_SYSTEM_PLAN.md --path docs/features/subscription-quantity-chaining.md --path docs/planning/ban-modes-v2.md --force --refs public
} else {
    Write-Host "filter-repo not found — falling back to git rm (no history rewrite)..."
    $toRemove = @(
        "AGENTS.md","TORO_GYM_PLAN.md","LOCATION_DRIVEN_RBAC_PLAN.md","MEMBER_STATUS_PLAN.md","RECEPTION_SYSTEM_PLAN.md",
        "docs/features/subscription-quantity-chaining.md","docs/planning/ban-modes-v2.md"
    ) | Where-Object { Test-Path $_ }
    if ($toRemove) {
        git rm --cached $toRemove 2>$null | Out-Null
        git rm $toRemove 2>$null | Out-Null
        if ((git status --porcelain)) {
            git commit -m "chore(public): sync from dev, exclude internal docs"
        }
    }
}

Write-Host "Public branch now at $(git rev-parse --short HEAD) — push with: git push origin public --force-with-lease"
Write-Host "Private dev remains source of truth at $(git rev-parse --short dev)"
git checkout dev
