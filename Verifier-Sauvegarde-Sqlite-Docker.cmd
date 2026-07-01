@echo off
setlocal

cd /d "%~dp0"
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0Verifier-Sauvegarde-Sqlite-Docker.ps1"

if errorlevel 1 (
    echo.
    echo ERREUR : la verification de sauvegarde a echoue.
    pause
    exit /b 1
)

echo.
echo Verification de sauvegarde terminee.
pause
