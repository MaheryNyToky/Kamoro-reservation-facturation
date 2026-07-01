$ErrorActionPreference = "Stop"

$TaskNames = @(
    "Kamoro - Sauvegarde SQLite",
    "Kamoro - Sauvegarde SQLite 09h",
    "Kamoro - Verification Sauvegarde SQLite"
)

$removed = 0
foreach ($taskName in $TaskNames) {
    $task = Get-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue
    if ($task) {
        Unregister-ScheduledTask -TaskName $taskName -Confirm:$false
        Write-Host "[OK] Tache desactivee : $taskName"
        $removed++
    }
}

if ($removed -eq 0) {
    Write-Host "Aucune tache de sauvegarde Kamoro trouvee."
}
