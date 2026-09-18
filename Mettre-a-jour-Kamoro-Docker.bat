@echo off
setlocal

cd /d "%~dp0"

echo.
echo Mise a jour securisee de Kamoro...
echo Une sauvegarde SQLite sera creee avant la reconstruction.
echo.

powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0Mettre-a-jour-Kamoro-Docker.ps1"

if errorlevel 1 (
    echo.
    echo ERREUR : la mise a jour a echoue. Consultez le dossier .startup-logs.
    pause
    exit /b 1
)

echo.
echo Mise a jour terminee.
exit /b 0
