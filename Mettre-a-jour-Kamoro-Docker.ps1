$ErrorActionPreference = "Stop"

$ProjectRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
$env:KAMORO_FORCE_UPDATE = "1"

try {
    & powershell.exe -NoProfile -ExecutionPolicy Bypass -File (Join-Path $ProjectRoot "Demarrer-Kamoro-Au-Demarrage.ps1")
    if ($LASTEXITCODE -ne 0) {
        exit $LASTEXITCODE
    }
} finally {
    Remove-Item Env:KAMORO_FORCE_UPDATE -ErrorAction SilentlyContinue
}
