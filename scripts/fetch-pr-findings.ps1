param(
    [Parameter(Mandatory = $true)]
    [int]$PrNumber,
    [string]$Repo = "JeremyBarrera/gymie"
)

$ErrorActionPreference = "Stop"

if (-not (Get-Command gh -ErrorAction SilentlyContinue)) {
    throw "gh CLI is required but was not found. Install it and authenticate first."
}

$parts = $Repo.Split("/")
$owner = $parts[0]
$name = $parts[1]
$query = 'query($o:String!,$r:String!,$n:Int!){repository(owner:$o,name:$r){pullRequest(number:$n){title,state,reviewThreads(first:100){totalCount,nodes{path,line,isResolved,comments(first:1){nodes{author{login},body}}}}}}}'
$raw = gh api graphql -f query=$query -f o=$owner -f r=$name -F n=$PrNumber | Out-String
$pr = ($raw | ConvertFrom-Json).data.repository.pullRequest

if ($null -eq $pr) {
    throw "PR #$PrNumber not found in $Repo."
}

Write-Host "Findings for $Repo#$PrNumber : $($pr.title) [$($pr.state)]"

$open = @()
$resolved = 0
foreach ($t in $pr.reviewThreads.nodes) {
    $c = $t.comments.nodes | Select-Object -First 1
    if ($null -eq $c) { continue }
    if ($c.author.login -ne "coderabbitai") { continue }
    if ($t.isResolved) { $resolved++; continue }
    $open += [pscustomobject]@{ Thread = $t; Body = [string]$c.body }
}

Write-Host "Open CodeRabbit threads: $($open.Count) (already resolved: $resolved, of $($pr.reviewThreads.totalCount) total threads)"

$n = 0
foreach ($item in $open) {
    $n++
    $body = $item.Body
    $sev = "unknown"
    $sm = [regex]::Match($body, '_[^\x00-\x7F]*\s*(Critical|Major|Minor|Trivial|Info)_')
    if ($sm.Success) { $sev = $sm.Groups[1].Value }
    $title = ""
    $m = [regex]::Match($body, '\*\*(.+?)\*\*')
    if ($m.Success) { $title = $m.Groups[1].Value.Trim() }
    if ($title.Length -gt 160) { $title = $title.Substring(0, 160) }
    Write-Host ""
    Write-Host "### [$n] $($item.Thread.path):$($item.Thread.line) [$sev] $title"
    $p = [regex]::Match($body, 'Prompt for AI Agents</summary>\s+```\s*(.*?)\s*```', 'Singleline')
    if ($p.Success) {
        Write-Host $p.Groups[1].Value.Trim()
    } else {
        $flat = ($body -split "`n" | Where-Object { $_.Trim() -ne "" } | Select-Object -First 6) -join "`n"
        Write-Host $flat
    }
}

Write-Host ""
Write-Host "End of findings ($($open.Count) open). Verify each against current code before fixing; do not mark threads resolved until the fix has landed and been validated."
