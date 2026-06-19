@echo off
setlocal

cd /d "%~dp0"

echo.
echo Arret de Kamoro Reservation Facturation...
docker compose down

echo.
echo Application arretee.
pause
