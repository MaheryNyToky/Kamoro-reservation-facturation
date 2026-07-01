@echo off
setlocal

cd /d "%~dp0"
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0Desactiver-Sauvegarde-Sqlite-Docker.ps1"

if errorlevel 1 (
    echo.
    echo ERREUR : la desactivation de la sauvegarde automatique a echoue.
    pause
    exit /b 1
)

echo.
pause
