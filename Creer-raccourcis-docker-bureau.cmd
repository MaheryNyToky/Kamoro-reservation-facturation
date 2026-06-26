@echo off
setlocal

cd /d "%~dp0"

powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0Creer-raccourcis-docker-bureau.ps1"

if errorlevel 1 (
    echo.
    echo ERREUR : la creation des raccourcis a echoue.
    pause
    exit /b 1
)

pause
