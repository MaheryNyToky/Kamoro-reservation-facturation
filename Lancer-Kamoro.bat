@echo off
setlocal

cd /d "%~dp0"

echo.
echo Demarrage de Kamoro Reservation Facturation...
echo Cette fenetre peut rester ouverte pendant le lancement.
echo.

powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0dev.ps1"

echo.
echo Ouverture de l'application dans le navigateur...
start "" "http://127.0.0.1:8080/index.html"

echo.
echo Si l'application est ouverte, vous pouvez reduire cette fenetre.
pause
