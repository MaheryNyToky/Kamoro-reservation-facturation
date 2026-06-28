@echo off
setlocal

cd /d "%~dp0"

echo.
echo Lancement de Kamoro Reservation Facturation...
echo Le lanceur attend jusqu'a 15 secondes pour joindre le depot distant.
echo Si le depot n'est pas joignable, il continue avec la copie locale actuelle.
echo.

powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0Demarrer-Kamoro-Au-Demarrage.ps1"

if errorlevel 1 (
    echo.
    echo ERREUR : le lancement a echoue. Consultez le dossier .startup-logs pour plus de details.
    pause
    exit /b 1
)

echo.
echo Application lancee.
exit /b 0
