# Shared helper: parses a Laravel-style .env file into a hashtable.
# Dot-sourced by the server transfer scripts.

function Get-DotEnvValues {
    param([string]$Path)

    $map = @{}
    foreach ($line in Get-Content $Path) {
        $trimmed = $line.Trim()
        if ($trimmed -match '^([A-Za-z_][A-Za-z0-9_]*)=(.*)$') {
            $value = $Matches[2].Trim().Trim('"').Trim("'")
            $map[$Matches[1]] = $value
        }
    }

    return $map
}
