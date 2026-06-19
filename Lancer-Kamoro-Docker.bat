@echo off
setlocal

cd /d "%~dp0"

echo.
echo Lancement de Kamoro Reservation Facturation avec Docker...
echo Le premier lancement peut prendre longtemps car Docker construit l'application.
echo Ne fermez pas cette fenetre pendant le lancement.
echo.

docker compose up -d --build

if errorlevel 1 (
    echo.
    echo ERREUR : Docker n'a pas reussi a lancer l'application.
    echo Verifiez que Docker Desktop est installe et ouvert.
    pause
    exit /b 1
)

echo.
echo Attente du demarrage des services...
timeout /t 8 /nobreak >nul

echo.
echo Ouverture de l'application dans le navigateur...
start "" "http://127.0.0.1:8080/index.html"

echo.
echo Application lancee.
echo Pour arreter l'application plus tard, double-cliquez sur Arreter-Kamoro-Docker.bat.
pause
