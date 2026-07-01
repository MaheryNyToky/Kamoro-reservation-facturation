@echo off
setlocal

cd /d "%~dp0"
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0Sauvegarder-Sqlite-Docker.ps1"

if errorlevel 1 (
    echo.
    echo ERREUR : la sauvegarde SQLite a echoue.
    pause
    exit /b 1
)

echo.
echo Sauvegarde SQLite terminee.
pause
