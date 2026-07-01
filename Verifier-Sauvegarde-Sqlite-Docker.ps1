param(
    [string]$BackupRoot,
    [string]$DriveBackupRoot,
    [int]$RetentionDays = 60
)

$ErrorActionPreference = "Stop"

$ProjectRoot = if ($PSScriptRoot) { $PSScriptRoot } else { Split-Path -Parent $MyInvocation.MyCommand.Path }
$BackupScriptPath = Join-Path $ProjectRoot "Sauvegarder-Sqlite-Docker.ps1"
$LogRoot = Join-Path $ProjectRoot ".backup-logs"
$TodayStamp = Get-Date -Format "yyyyMMdd"
$TodayOkMarker = Join-Path $LogRoot "sqlite-backup-ok-$TodayStamp.marker"

if (-not $BackupRoot) {
    $BackupRoot = if ($env:KAMORO_BACKUP_DIR) {
        $env:KAMORO_BACKUP_DIR
    } else {
        Join-Path $ProjectRoot "backups/sqlite"
    }
}

if (-not $DriveBackupRoot -and $env:KAMORO_DRIVE_BACKUP_DIR) {
    $DriveBackupRoot = $env:KAMORO_DRIVE_BACKUP_DIR
}

function Write-Log {
    param([string]$Message)

    New-Item -ItemType Directory -Force -Path $LogRoot | Out-Null
    $line = "[{0}] {1}" -f (Get-Date -Format "yyyy-MM-dd HH:mm:ss"), $Message
    Write-Host $line
    Add-Content -Path (Join-Path $LogRoot "sqlite-backup-check.log") -Value $line -Encoding UTF8
}

function Get-TodayBackup {
    param([string]$Root)

    if (-not $Root -or -not (Test-Path $Root)) {
        return $null
    }

    $todayPrefix = "kamoro-sqlite-{0}-" -f (Get-Date -Format "yyyyMMdd")

    return Get-ChildItem -Path $Root -Filter "$todayPrefix*.sqlite" -File -ErrorAction SilentlyContinue |
        Where-Object { $_.Length -gt 0 } |
        Sort-Object LastWriteTime -Descending |
        Select-Object -First 1
}

function Test-BackupIsComplete {
    param(
        [System.IO.FileInfo]$LocalBackup,
        [string]$DriveRoot
    )

    if (-not $LocalBackup -or -not (Test-Path $LocalBackup.FullName) -or $LocalBackup.Length -le 0) {
        return $false
    }

    if (-not $DriveRoot) {
        return $true
    }

    if (-not (Test-Path $DriveRoot)) {
        return $false
    }

    $driveBackupPath = Join-Path $DriveRoot $LocalBackup.Name
    if (-not (Test-Path $driveBackupPath)) {
        return $false
    }

    $driveBackup = Get-Item $driveBackupPath
    return $driveBackup.Length -eq $LocalBackup.Length
}

function Copy-ToDriveIfNeeded {
    param(
        [System.IO.FileInfo]$LocalBackup,
        [string]$DriveRoot
    )

    if (-not $DriveRoot -or -not $LocalBackup) {
        return
    }

    New-Item -ItemType Directory -Force -Path $DriveRoot | Out-Null
    $driveBackupPath = Join-Path $DriveRoot $LocalBackup.Name

    if ((Test-Path $driveBackupPath) -and ((Get-Item $driveBackupPath).Length -eq $LocalBackup.Length)) {
        return
    }

    Copy-Item -Path $LocalBackup.FullName -Destination $driveBackupPath -Force
    Write-Log "Copie Drive retentee : $driveBackupPath"
}

if (-not (Test-Path $BackupScriptPath)) {
    throw "Script de sauvegarde introuvable : $BackupScriptPath"
}

New-Item -ItemType Directory -Force -Path $LogRoot | Out-Null

Get-ChildItem -Path $LogRoot -Filter "sqlite-backup-ok-*.marker" -File -ErrorAction SilentlyContinue |
    Where-Object { $_.Name -ne (Split-Path $TodayOkMarker -Leaf) -and $_.LastWriteTime -lt (Get-Date).AddDays(-7) } |
    Remove-Item -Force

if (Test-Path $TodayOkMarker) {
    Write-Log "Sauvegarde du jour deja validee. Aucun controle supplementaire."
    exit 0
}

Write-Log "Verification de la sauvegarde du jour."

$localBackup = Get-TodayBackup -Root $BackupRoot
if ($localBackup) {
    Copy-ToDriveIfNeeded -LocalBackup $localBackup -DriveRoot $DriveBackupRoot
}

if (Test-BackupIsComplete -LocalBackup $localBackup -DriveRoot $DriveBackupRoot) {
    Set-Content -Path $TodayOkMarker -Value "OK $($localBackup.FullName)" -Encoding UTF8
    Write-Log "Sauvegarde du jour deja OK : $($localBackup.FullName)"
    exit 0
}

Write-Log "Sauvegarde du jour manquante ou incomplete. Nouvelle tentative."

$arguments = @(
    "-NoProfile",
    "-ExecutionPolicy", "Bypass",
    "-File", "`"$BackupScriptPath`"",
    "-BackupRoot", "`"$BackupRoot`"",
    "-RetentionDays", "$RetentionDays"
)

if ($DriveBackupRoot) {
    $arguments += @("-DriveBackupRoot", "`"$DriveBackupRoot`"")
}

Start-Process -FilePath "powershell.exe" -ArgumentList $arguments -WorkingDirectory $ProjectRoot -Wait -NoNewWindow

$localBackup = Get-TodayBackup -Root $BackupRoot
if ($localBackup) {
    Copy-ToDriveIfNeeded -LocalBackup $localBackup -DriveRoot $DriveBackupRoot
}

if (-not (Test-BackupIsComplete -LocalBackup $localBackup -DriveRoot $DriveBackupRoot)) {
    throw "La sauvegarde du jour n'est toujours pas complete. La prochaine verification horaire reessaiera."
}

Set-Content -Path $TodayOkMarker -Value "OK $($localBackup.FullName)" -Encoding UTF8
Write-Log "Sauvegarde du jour OK apres nouvelle tentative : $($localBackup.FullName)"
