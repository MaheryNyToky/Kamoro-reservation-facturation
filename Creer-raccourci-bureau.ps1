$ErrorActionPreference = "Stop"

$ProjectRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
$LauncherPath = Join-Path $ProjectRoot "Lancer-Kamoro.bat"

if (-not (Test-Path $LauncherPath)) {
    throw "Le fichier Lancer-Kamoro.bat est introuvable dans le dossier du projet."
}

$DesktopPath = [Environment]::GetFolderPath("Desktop")
$ShortcutPath = Join-Path $DesktopPath "Kamoro Reservation Facturation.lnk"

$Shell = New-Object -ComObject WScript.Shell
$Shortcut = $Shell.CreateShortcut($ShortcutPath)
$Shortcut.TargetPath = $LauncherPath
$Shortcut.WorkingDirectory = $ProjectRoot
$Shortcut.Description = "Lancer Kamoro Reservation Facturation en local"
$Shortcut.Save()

Write-Host ""
Write-Host "[OK] Raccourci cree sur le Bureau : Kamoro Reservation Facturation"
Write-Host "Double-cliquez dessus pour lancer l'application."
