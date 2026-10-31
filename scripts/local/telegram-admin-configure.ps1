param(
    [string]$ProjectPath = (Get-Location).Path
)

$ErrorActionPreference = "Stop"

Write-Host ""
Write-Host "=== NR PRO BOT ADMIN CONFIGURATION ===" -ForegroundColor Cyan

# PROJECT DIRECTORY
$root = (Resolve-Path -LiteralPath $ProjectPath).Path

$envFile = Join-Path $root ".env"

# CHECK ENV
if (-not (Test-Path -LiteralPath $envFile)) {
    throw "[ERROR] .env file not found."
}

# CHECK GIT
if (-not (Get-Command git -ErrorAction SilentlyContinue)) {
    throw "[ERROR] Git is not installed."
}

# SAFE GIT TRACKING CHECK
$trackedFiles = @(
    & git -C $root ls-files -- .env
)

if ($LASTEXITCODE -ne 0) {
    throw "[ERROR] Git tracking check failed."
}

if ($trackedFiles -contains ".env") {
    throw "[ERROR] .env is tracked by Git. Refusing to edit."
}

# CHECK GIT IGNORE
& git -C $root check-ignore -q -- .env

if ($LASTEXITCODE -ne 0) {
    throw "[ERROR] .env is not ignored by Git."
}

Write-Host "[OK] ENV SECURITY VERIFIED" -ForegroundColor Green

# TELEGRAM ADMIN ID
Write-Host ""
Write-Host "Enter your personal Telegram numeric ID:" -ForegroundColor Yellow

$adminId = (Read-Host "TELEGRAM_ADMIN_ID").Trim()

if ($adminId -notmatch '^[1-9][0-9]{0,18}$') {
    throw "[ERROR] Invalid Telegram Admin ID."
}

# READ ENV
$content = [System.IO.File]::ReadAllText($envFile)

# CHECK DUPLICATE SETTINGS
$pattern = '(?m)^[ \t]*TELEGRAM_ADMIN_ID[ \t]*=.*$'

$matches = [regex]::Matches($content, $pattern)

if ($matches.Count -gt 1) {
    throw "[ERROR] Duplicate TELEGRAM_ADMIN_ID settings."
}

# UPDATE OR CREATE
if ($matches.Count -eq 1) {

    $updated = [regex]::Replace(
        $content,
        $pattern,
        "TELEGRAM_ADMIN_ID=$adminId"
    )

} else {

    $newline = if ($content.Contains("`r`n")) {
        "`r`n"
    } else {
        "`n"
    }

    if (
        $content.Length -gt 0 -and
        -not $content.EndsWith("`n")
    ) {
        $content += $newline
    }

    $updated = $content +
        "TELEGRAM_ADMIN_ID=$adminId" +
        $newline
}

if ($updated -ceq $content) {
    Write-Host "[OK] Admin ID already configured." -ForegroundColor Green
    exit 0
}

# BACKUP OUTSIDE PROJECT
$backupDir = Join-Path $env:LOCALAPPDATA "NR-PRO-BOT\backups"

New-Item -ItemType Directory `
    -Path $backupDir `
    -Force | Out-Null

$backupFile = Join-Path $backupDir (
    "admin-env-" + (Get-Date -Format "yyyyMMdd-HHmmss") + ".bak"
)

Copy-Item -LiteralPath $envFile -Destination $backupFile

# SAVE ENV
try {

    $utf8 = New-Object System.Text.UTF8Encoding($false)

    [System.IO.File]::WriteAllText(
        $envFile,
        $updated,
        $utf8
    )

    Write-Host ""
    Write-Host "[OK] TELEGRAM_ADMIN_ID CONFIGURED" -ForegroundColor Green

    Write-Host "[OK] ENV BACKUP CREATED" -ForegroundColor Green

    Write-Host "[OK] NO SECRETS DISPLAYED" -ForegroundColor Green

    Write-Host ""
    Write-Host "[SUCCESS] NR PRO BOT ADMIN READY" -ForegroundColor Green

} catch {

    Copy-Item `
        -LiteralPath $backupFile `
        -Destination $envFile `
        -Force

    throw "[ERROR] Configuration failed. Original ENV restored."
}