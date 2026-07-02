param(
    [string]$BackupRoot,
    [string]$DriveBackupRoot,
    [int]$RetentionDays = 60,
    [switch]$SkipDockerConsistentBackup
)

$ErrorActionPreference = "Stop"

$ProjectRoot = if ($PSScriptRoot) { $PSScriptRoot } else { Split-Path -Parent $MyInvocation.MyCommand.Path }
$DatabasePath = Join-Path $ProjectRoot "hestiapredict/database/database.sqlite"
$TempRoot = Join-Path $ProjectRoot "hestiapredict/database/.backup-temp"
$LogRoot = Join-Path $ProjectRoot ".backup-logs"

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

    $line = "[{0}] {1}" -f (Get-Date -Format "yyyy-MM-dd HH:mm:ss"), $Message
    Write-Host $line
    Add-Content -Path (Join-Path $LogRoot "sqlite-backup.log") -Value $line -Encoding UTF8
}

function Remove-OldBackups {
    param([string]$Root)

    if (-not $Root -or -not (Test-Path $Root)) {
        return
    }

    $limit = (Get-Date).AddDays(-1 * $RetentionDays)
    Get-ChildItem -Path $Root -Filter "kamoro-sqlite-*.sqlite" -File -ErrorAction SilentlyContinue |
        Where-Object { $_.LastWriteTime -lt $limit } |
        Remove-Item -Force
}

New-Item -ItemType Directory -Force -Path $BackupRoot | Out-Null
New-Item -ItemType Directory -Force -Path $TempRoot | Out-Null
New-Item -ItemType Directory -Force -Path $LogRoot | Out-Null

if (-not (Test-Path $DatabasePath)) {
    throw "Base SQLite introuvable : $DatabasePath"
}

$timestamp = Get-Date -Format "yyyyMMdd-HHmmss"
$backupFileName = "kamoro-sqlite-$timestamp.sqlite"
$localBackupPath = Join-Path $BackupRoot $backupFileName
$tempBackupPath = Join-Path $TempRoot $backupFileName
$backupCreatedByDocker = $false

Write-Log "Debut sauvegarde SQLite."
Write-Log "Source : $DatabasePath"
Write-Log "Destination locale : $localBackupPath"

if (-not $SkipDockerConsistentBackup) {
    $docker = Get-Command docker -ErrorAction SilentlyContinue

    if ($docker) {
        try {
            $containerBackupPath = "/data/.backup-temp/$backupFileName"
            $tempPhpScriptPath = Join-Path $TempRoot "vacuum-$backupFileName.php"
            $phpScript = @"
<?php
`$db = new PDO('sqlite:/data/database.sqlite');
`$db->exec("VACUUM INTO '$containerBackupPath'");
"@

            Set-Content -Path $tempPhpScriptPath -Value $phpScript -Encoding UTF8

            Push-Location $ProjectRoot
            try {
                & docker compose exec -T laravel php "/data/.backup-temp/$(Split-Path -Leaf $tempPhpScriptPath)"
            } finally {
                Pop-Location
            }

            if (Test-Path $tempBackupPath) {
                Copy-Item -Path $tempBackupPath -Destination $localBackupPath -Force
                Remove-Item -Path $tempBackupPath -Force
                $backupCreatedByDocker = $true
                Write-Log "Sauvegarde coherente creee via Docker/VACUUM INTO."
            }
        } catch {
            try { Pop-Location } catch {}
            Write-Log "Sauvegarde Docker indisponible, copie fichier directe utilisee. Detail : $($_.Exception.Message)"
        } finally {
            if ($tempPhpScriptPath -and (Test-Path $tempPhpScriptPath)) {
                Remove-Item -Path $tempPhpScriptPath -Force
            }
        }
    } else {
        Write-Log "Docker introuvable dans le PATH, copie fichier directe utilisee."
    }
}

if (-not $backupCreatedByDocker) {
    Copy-Item -Path $DatabasePath -Destination $localBackupPath -Force
    Write-Log "Sauvegarde creee par copie directe du fichier SQLite."
}

if ($DriveBackupRoot) {
    try {
        New-Item -ItemType Directory -Force -Path $DriveBackupRoot | Out-Null
        $driveBackupPath = Join-Path $DriveBackupRoot $backupFileName
        Copy-Item -Path $localBackupPath -Destination $driveBackupPath -Force
        Write-Log "Copie Google Drive/local cloud : $driveBackupPath"
    } catch {
        Write-Log "Avertissement : copie Drive impossible vers '$DriveBackupRoot'. La sauvegarde locale est conservee. Detail : $($_.Exception.Message)"
    }
}

Remove-OldBackups -Root $BackupRoot
Remove-OldBackups -Root $DriveBackupRoot

Write-Log "Sauvegarde terminee."
