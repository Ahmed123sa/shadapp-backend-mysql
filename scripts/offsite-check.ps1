# Proves the off-site copy works, and that its failure is visible.
#
# Two runs. The first is the happy path: db:backup writes the archive locally
# and mirrors it to BACKUP_OFFSITE_PATH, verifying size and SHA-256. The
# second renames the destination away first, which is what an unplugged disk
# or an unmounted share looks like from PHP, and checks that db:backup says so
# and exits non-zero while leaving the local archive alone.
#
# The second run is the one worth watching. A backup system whose off-site leg
# fails quietly is indistinguishable from one that works, until the day it
# matters.
#
# ASCII only: see the note in restore-drill.ps1 about PowerShell 5.1, BOM-less
# .ps1 files and curly quotes.
#
#   cd G:\ShadApp-phase4\shadapp-backend-mysql
#   .\scripts\offsite-check.ps1

$root = Split-Path -Parent $PSScriptRoot
Set-Location $root

function Get-EnvValue([string]$key) {
    $line = Select-String -Path (Join-Path $root '.env') -Pattern "^\s*$key\s*=" | Select-Object -First 1
    if (-not $line) { return $null }
    return ($line.Line -split '=', 2)[1].Trim().Trim('"')
}

$offsite = (Get-EnvValue 'BACKUP_OFFSITE_PATH') -replace '/', '\'

if (-not $offsite) {
    Write-Host 'BACKUP_OFFSITE_PATH is not set in .env.' -ForegroundColor Red
    exit 1
}

Write-Host ''
Write-Host "=== 1/3  Normal run: the destination is there ===" -ForegroundColor Cyan
Write-Host "Destination: $offsite"
Write-Host ''

$before = @(Get-ChildItem -Path $offsite -Filter '*.zip' -ErrorAction SilentlyContinue).Count

php artisan db:backup
$normalExit = $LASTEXITCODE

$after = @(Get-ChildItem -Path $offsite -Filter '*.zip' -ErrorAction SilentlyContinue).Count

Write-Host ''
if ($normalExit -eq 0 -and $after -gt $before) {
    Write-Host "OK: exit code 0, and the off-site copy count went from $before to $after." -ForegroundColor Green
} else {
    Write-Host "UNEXPECTED: exit code $normalExit, off-site zips $before -> $after." -ForegroundColor Red
    Write-Host 'Fix this before continuing; the second run assumes the first one works.' -ForegroundColor Red
    exit 1
}

Write-Host ''
Write-Host "=== 2/3  Failure run: the destination is gone ===" -ForegroundColor Cyan
Write-Host 'Renaming the destination away to stand in for an unplugged disk.'
Write-Host ''

$parked = $offsite + '-unplugged'
Rename-Item -Path $offsite -NewName (Split-Path $parked -Leaf)

try {
    $localBefore = @(Get-ChildItem -Path (Join-Path $root 'storage\app\backups') -Filter '*.zip').Count

    php artisan db:backup
    $failExit = $LASTEXITCODE

    $localAfter = @(Get-ChildItem -Path (Join-Path $root 'storage\app\backups') -Filter '*.zip').Count
} finally {
    # Always put it back, even if the run above threw.
    Rename-Item -Path $parked -NewName (Split-Path $offsite -Leaf)
}

Write-Host ''
Write-Host "=== 3/3  What that proved ===" -ForegroundColor Cyan

$ok = $true

if ($failExit -ne 0) {
    Write-Host "  Exit code was $failExit (non-zero). A scheduler can see this." -ForegroundColor Green
} else {
    Write-Host '  Exit code was 0. The off-site failure would be invisible to a scheduler.' -ForegroundColor Red
    $ok = $false
}

if ($localAfter -gt $localBefore) {
    Write-Host '  The local archive was still written and kept.' -ForegroundColor Green
} else {
    Write-Host '  No local archive was produced. A missing off-site disk should not cost you the local copy.' -ForegroundColor Red
    $ok = $false
}

if (Test-Path $offsite) {
    Write-Host '  The destination was restored.' -ForegroundColor Green
} else {
    Write-Host "  The destination was NOT restored. Rename $parked back by hand." -ForegroundColor Red
    $ok = $false
}

Write-Host ''
if ($ok) {
    Write-Host 'PASS: the off-site copy works, and its failure is loud.' -ForegroundColor Green
    Write-Host ''
    Write-Host 'Still to do: BACKUP_OFFSITE_PATH is on G:, the same disk as the local' -ForegroundColor Yellow
    Write-Host 'backups. Point it at a different physical disk to make it protect anything.' -ForegroundColor Yellow
} else {
    Write-Host 'Something above did not behave as intended. Do not rely on this yet.' -ForegroundColor Red
    exit 1
}
