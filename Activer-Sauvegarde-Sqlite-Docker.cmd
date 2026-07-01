@echo off
setlocal

cd /d "%~dp0"
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0Activer-Sauvegarde-Sqlite-Docker.ps1"

if errorlevel 1 (
    echo.
    echo ERREUR : l'activation de la sauvegarde automatique a echoue.
    pause
    exit /b 1
)

echo.
pause
