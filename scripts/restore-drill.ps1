# Backup restore drill: proves the archive db:backup produces can actually be
# replayed, by replaying it into a scratch database and comparing the result
# against the live one.
#
# The live database is never written to. The restore targets the
# 'restore_target' connection (config/database.php), whose name comes from
# DB_RESTORE_DATABASE in .env, and runs with --database-only so the shared
# storage/app/public tree is left alone.
#
# Verification is row counts *and* CHECKSUM TABLE. Counts alone would pass a
# restore that brought back the right number of rows with the wrong contents
# (a truncated text column, a mangled charset). The checksum is what turns
# "it ran without erroring" into "the data came back".
#
# Comparison goes through the mysql client rather than Laravel, deliberately:
# the point is to inspect what is actually stored in each database, not to ask
# the same framework layer that just wrote it.
#
# ASCII ONLY, on purpose. Windows PowerShell 5.1 reads a .ps1 with no BOM
# using the system ANSI code page, and a UTF-8 em dash decodes there into a
# curly double quote, which PowerShell treats as a real string delimiter. The
# result is a parse error pointing at a line nowhere near the actual dash.
# Keep every character in this file plain ASCII.
#
#   cd G:\ShadApp-phase4\shadapp-backend-mysql
#   .\scripts\restore-drill.ps1
#
# Pass -Yes to skip db:restore's own confirmation. Not the default: seeing the
# target database named out loud before agreeing is the check that catches a
# misconfigured DB_RESTORE_DATABASE.

param(
    [switch]$Yes
)

$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent $PSScriptRoot
Set-Location $root

# Read every name out of .env rather than hardcoding it, so this script cannot
# drift from what the application is actually using.
function Get-EnvValue([string]$key) {
    $line = Select-String -Path (Join-Path $root '.env') -Pattern "^\s*$key\s*=" | Select-Object -First 1
    if (-not $line) { return $null }
    return ($line.Line -split '=', 2)[1].Trim().Trim('"')
}

$liveDb   = Get-EnvValue 'DB_DATABASE'
$drillDb  = Get-EnvValue 'DB_RESTORE_DATABASE'
$mysqlBin = Get-EnvValue 'DB_DUMP_BINARY_PATH'
$dbUser   = Get-EnvValue 'DB_USERNAME'
$dbPass   = Get-EnvValue 'DB_PASSWORD'

# mysql.exe falls back to localhost:3306, which is not necessarily where
# DB_HOST/DB_PORT point. Creating the scratch database on a different server
# than the one Laravel then restores into would fail confusingly.
$dbHost = Get-EnvValue 'DB_HOST'
$dbPort = Get-EnvValue 'DB_PORT'
if (-not $dbHost) { $dbHost = '127.0.0.1' }
if (-not $dbPort) { $dbPort = '3306' }

if (-not $drillDb) {
    Write-Host "DB_RESTORE_DATABASE is not set in .env." -ForegroundColor Red
    exit 1
}

# The one mistake this script must make impossible.
if ($drillDb -eq $liveDb) {
    Write-Host "DB_RESTORE_DATABASE and DB_DATABASE are both '$liveDb'." -ForegroundColor Red
    Write-Host "The drill would overwrite the live database. Refusing." -ForegroundColor Red
    exit 1
}

$mysql = if ($mysqlBin) { Join-Path $mysqlBin 'mysql.exe' } else { 'mysql' }

# Single-quoted throughout: these strings reach mysql.exe as one argument
# each, and a double quote anywhere inside would end the PowerShell argument
# early (which is how an earlier version of this script broke).
$tableList = 'users, clients, sub_users, workspaces, contracts, payments, approvals, chat_messages, files, meetings'
$countSql = @'
SELECT 'users', COUNT(*) FROM users
UNION ALL SELECT 'clients', COUNT(*) FROM clients
UNION ALL SELECT 'sub_users', COUNT(*) FROM sub_users
UNION ALL SELECT 'workspaces', COUNT(*) FROM workspaces
UNION ALL SELECT 'contracts', COUNT(*) FROM contracts
UNION ALL SELECT 'payments', COUNT(*) FROM payments
UNION ALL SELECT 'approvals', COUNT(*) FROM approvals
UNION ALL SELECT 'chat_messages', COUNT(*) FROM chat_messages
UNION ALL SELECT 'files', COUNT(*) FROM files
UNION ALL SELECT 'meetings', COUNT(*) FROM meetings;
'@

function Invoke-Mysql([string]$database, [string]$sql) {
    $env:MYSQL_PWD = $dbPass
    try {
        & $mysql "--host=$dbHost" "--port=$dbPort" "--user=$dbUser" "--database=$database" '--batch' '--skip-column-names' "--execute=$sql"
    } finally {
        Remove-Item Env:MYSQL_PWD -ErrorAction SilentlyContinue
    }
}

# CHECKSUM TABLE prefixes each row with the database name, which differs
# between the two by definition, so it is stripped before comparing.
function Get-Fingerprint([string]$database) {
    $counts = Invoke-Mysql $database $countSql
    if ($LASTEXITCODE -ne 0) { return $null }

    $sums = Invoke-Mysql $database "CHECKSUM TABLE $tableList;"
    if ($LASTEXITCODE -ne 0) { return $null }

    return @($counts; ($sums | ForEach-Object { $_ -replace '^[^.\t]+\.', '' }))
}

Write-Host ''
Write-Host '=== 1/5  Creating the scratch database if it is not there ===' -ForegroundColor Cyan
$env:MYSQL_PWD = $dbPass
& $mysql "--host=$dbHost" "--port=$dbPort" "--user=$dbUser" "--execute=CREATE DATABASE IF NOT EXISTS ``$drillDb`` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
$createFailed = $LASTEXITCODE -ne 0
Remove-Item Env:MYSQL_PWD -ErrorAction SilentlyContinue

if ($createFailed) {
    Write-Host "Could not create $drillDb on ${dbHost}:${dbPort}." -ForegroundColor Red
    Write-Host "If that said 'Can not connect' (error 2003), MySQL is not running. Start WAMP, then re-run." -ForegroundColor Yellow
    exit 1
}
Write-Host "  $drillDb ready on ${dbHost}:${dbPort}."

Write-Host ''
Write-Host "=== 2/5  Backing up the live database ($liveDb) ===" -ForegroundColor Cyan
php artisan db:backup
if ($LASTEXITCODE -ne 0) { Write-Host 'db:backup failed, so there is nothing to restore from.' -ForegroundColor Red; exit 1 }

Write-Host ''
Write-Host '=== 3/5  Fingerprinting the live database ===' -ForegroundColor Cyan
$liveFingerprint = Get-Fingerprint $liveDb
if (-not $liveFingerprint) { Write-Host "Could not read $liveDb." -ForegroundColor Red; exit 1 }
$liveFingerprint | ForEach-Object { Write-Host "  $_" }

Write-Host ''
Write-Host "=== 4/5  Restoring that archive into $drillDb ===" -ForegroundColor Cyan
if ($Yes) {
    php artisan db:restore --connection=restore_target --database-only --skip-snapshot --force
} else {
    php artisan db:restore --connection=restore_target --database-only --skip-snapshot
}
if ($LASTEXITCODE -ne 0) { Write-Host 'Restore failed, see above. The backup is NOT proven.' -ForegroundColor Red; exit 1 }

Write-Host ''
Write-Host '=== 5/5  Fingerprinting the restored database ===' -ForegroundColor Cyan
$drillFingerprint = Get-Fingerprint $drillDb
if (-not $drillFingerprint) { Write-Host "Could not read $drillDb." -ForegroundColor Red; exit 1 }
$drillFingerprint | ForEach-Object { Write-Host "  $_" }

Write-Host ''
$liveText  = ($liveFingerprint  | Where-Object { $_ }) -join "`n"
$drillText = ($drillFingerprint | Where-Object { $_ }) -join "`n"

if ($liveText -eq $drillText -and $liveText -ne '') {
    Write-Host 'PASS: every table came back with the same row count and the same checksum.' -ForegroundColor Green
    Write-Host 'This backup is proven restorable. Re-run the drill after any change to the' -ForegroundColor Green
    Write-Host 'backup path, after a MySQL upgrade, and on a routine schedule.' -ForegroundColor Green
} else {
    Write-Host 'MISMATCH: the restored database does not match the live one.' -ForegroundColor Red
    Write-Host 'Lines that differ:' -ForegroundColor Red
    Compare-Object ($liveText -split "`n") ($drillText -split "`n") |
        ForEach-Object { Write-Host ('  {0} {1}' -f $_.SideIndicator, $_.InputObject) }
    Write-Host 'Do not trust these backups until this is understood.' -ForegroundColor Red
    exit 1
}
