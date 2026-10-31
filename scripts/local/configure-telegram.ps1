param(
    [string]$ProjectPath = 'C:\Users\alire\Projects\NR-PRO-PASARGAD'
)

$ErrorActionPreference = 'Stop'
$envFile = Join-Path $ProjectPath '.env'
if (-not (Test-Path -LiteralPath $envFile)) {
    throw '.env was not found; no changes made.'
}

Write-Host 'Enter your Telegram bot token. Input is hidden.' -ForegroundColor Cyan
$secureToken = Read-Host -AsSecureString 'TELEGRAM_BOT_TOKEN'
$ptr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secureToken)
try {
    $token = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($ptr)
} finally {
    [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($ptr)
}

if ($token -notmatch '^[0-9]+:[A-Za-z0-9_-]{20,}$') {
    $token = $null
    throw 'The token format is invalid; .env unchanged.'
}

$raw = [System.IO.File]::ReadAllText($envFile)
$matches = [regex]::Matches($raw, '(?m)^\s*TELEGRAM_BOT_TOKEN\s*=.*$')
if ($matches.Count -gt 1) {
    $token = $null
    throw 'Duplicate TELEGRAM_BOT_TOKEN entries found; .env unchanged.'
}

$ending = if ($raw.Contains("`r`n")) { "`r`n" } else { "`n" }
if ($matches.Count -eq 1) {
    $start = $matches[0].Index
    $length = $matches[0].Length
    $replacement = 'TELEGRAM_BOT_TOKEN=' + $token
    $updated = $raw.Remove($start, $length).Insert($start, $replacement)
} else {
    $updated = $raw.TrimEnd("`r", "`n") + $ending + 'TELEGRAM_BOT_TOKEN=' + $token + $ending
}

# Backup outside the Git working directory, because .env contains secrets.
$backupDirectory = Join-Path $env:LOCALAPPDATA 'NR-PRO-PASARGAD\env-backups'
New-Item -ItemType Directory -Path $backupDirectory -Force | Out-Null
$backupFile = Join-Path $backupDirectory ('.env-' + (Get-Date -Format 'yyyyMMdd-HHmmss') + '.bak')
Copy-Item -LiteralPath $envFile -Destination $backupFile -ErrorAction Stop

$utf8 = [System.Text.UTF8Encoding]::new($false)
[System.IO.File]::WriteAllText($envFile, $updated, $utf8)
$token = $null
$updated = $null
Write-Host '[OK] TELEGRAM_BOT_TOKEN saved in .env (value hidden).' -ForegroundColor Green
Write-Host '[INFO] An .env backup containing secrets was saved outside the project; protect it.' -ForegroundColor Yellow
