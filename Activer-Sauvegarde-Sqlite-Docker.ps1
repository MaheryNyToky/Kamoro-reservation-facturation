param(
    [string]$BackupRoot,
    [string]$DriveBackupRoot,
    [string]$At = "09:00",
    [int]$RetentionDays = 60
)

$ErrorActionPreference = "Stop"

$ProjectRoot = if ($PSScriptRoot) { $PSScriptRoot } else { Split-Path -Parent $MyInvocation.MyCommand.Path }
$ScriptPath = Join-Path $ProjectRoot "Sauvegarder-Sqlite-Docker.ps1"
$VerifyScriptPath = Join-Path $ProjectRoot "Verifier-Sauvegarde-Sqlite-Docker.ps1"
$BackupTaskName = "Kamoro - Sauvegarde SQLite 09h"
$VerifyTaskName = "Kamoro - Verification Sauvegarde SQLite"
$LegacyTaskName = "Kamoro - Sauvegarde SQLite"

if (-not (Test-Path $ScriptPath)) {
    throw "Script de sauvegarde introuvable : $ScriptPath"
}

if (-not (Test-Path $VerifyScriptPath)) {
    throw "Script de verification introuvable : $VerifyScriptPath"
}

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

$arguments = @(
    "-NoProfile",
    "-ExecutionPolicy", "Bypass",
    "-File", "`"$ScriptPath`"",
    "-BackupRoot", "`"$BackupRoot`"",
    "-RetentionDays", "$RetentionDays"
)

if ($DriveBackupRoot) {
    $arguments += @("-DriveBackupRoot", "`"$DriveBackupRoot`"")
}

$verifyArguments = @(
    "-NoProfile",
    "-ExecutionPolicy", "Bypass",
    "-File", "`"$VerifyScriptPath`"",
    "-BackupRoot", "`"$BackupRoot`"",
    "-RetentionDays", "$RetentionDays"
)

if ($DriveBackupRoot) {
    $verifyArguments += @("-DriveBackupRoot", "`"$DriveBackupRoot`"")
}

$backupAction = New-ScheduledTaskAction -Execute "powershell.exe" -Argument ($arguments -join " ") -WorkingDirectory $ProjectRoot
$backupTrigger = New-ScheduledTaskTrigger -Daily -At $At
$verifyAction = New-ScheduledTaskAction -Execute "powershell.exe" -Argument ($verifyArguments -join " ") -WorkingDirectory $ProjectRoot
$verifyTriggers = for ($hour = 10; $hour -le 23; $hour++) {
    New-ScheduledTaskTrigger -Daily -At ("{0}:00" -f $hour)
}
$principal = New-ScheduledTaskPrincipal -UserId "$env:USERDOMAIN\$env:USERNAME" -LogonType Interactive -RunLevel Limited
$settings = New-ScheduledTaskSettingsSet -StartWhenAvailable -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Minutes 30)

foreach ($taskName in @($LegacyTaskName, $BackupTaskName, $VerifyTaskName)) {
    if (Get-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue) {
        Unregister-ScheduledTask -TaskName $taskName -Confirm:$false
    }
}

Register-ScheduledTask -TaskName $BackupTaskName -Action $backupAction -Trigger $backupTrigger -Principal $principal -Settings $settings -Force | Out-Null
Register-ScheduledTask -TaskName $VerifyTaskName -Action $verifyAction -Trigger $verifyTriggers -Principal $principal -Settings $settings -Force | Out-Null

Write-Host ""
Write-Host "[OK] Sauvegarde automatique activee."
Write-Host "Tache Windows sauvegarde : $BackupTaskName"
Write-Host "Tache Windows verification : $VerifyTaskName"
Write-Host "Sauvegarde : $At tous les jours"
Write-Host "Verification : 10:00 puis toutes les heures jusqu'a 23:00 seulement si la journee n'est pas encore OK"
Write-Host "Conservation : $RetentionDays jours"
Write-Host "Dossier local : $BackupRoot"
if ($DriveBackupRoot) {
    Write-Host "Dossier Google Drive/cloud : $DriveBackupRoot"
} else {
    Write-Host "Dossier Google Drive/cloud : non configure"
    Write-Host "Pour l'activer, relancez ce script avec -DriveBackupRoot ""C:\chemin\vers\Google Drive\Kamoro Backups""."
}
