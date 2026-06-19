$ErrorActionPreference = "Stop"

$ProjectRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
$DesktopPath = [Environment]::GetFolderPath("Desktop")
$Shell = New-Object -ComObject WScript.Shell

function New-KamoroShortcut {
    param(
        [string]$Name,
        [string]$Target,
        [string]$Description
    )

    if (-not (Test-Path $Target)) {
        throw "Fichier introuvable : $Target"
    }

    $ShortcutPath = Join-Path $DesktopPath "$Name.lnk"
    $Shortcut = $Shell.CreateShortcut($ShortcutPath)
    $Shortcut.TargetPath = $Target
    $Shortcut.WorkingDirectory = $ProjectRoot
    $Shortcut.Description = $Description
    $Shortcut.Save()
}

New-KamoroShortcut `
    -Name "Kamoro - Lancer" `
    -Target (Join-Path $ProjectRoot "Lancer-Kamoro-Docker.bat") `
    -Description "Lancer Kamoro Reservation Facturation avec Docker"

New-KamoroShortcut `
    -Name "Kamoro - Arreter" `
    -Target (Join-Path $ProjectRoot "Arreter-Kamoro-Docker.bat") `
    -Description "Arreter Kamoro Reservation Facturation"

Write-Host ""
Write-Host "[OK] Deux raccourcis ont ete crees sur le Bureau :"
Write-Host " - Kamoro - Lancer"
Write-Host " - Kamoro - Arreter"
