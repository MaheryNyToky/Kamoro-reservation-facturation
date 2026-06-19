@echo off
setlocal

cd /d "%~dp0"

echo.
echo Lancement de Kamoro Reservation Facturation avec Docker...
echo Le premier lancement peut prendre longtemps car Docker construit l'application.
echo Ne fermez pas cette fenetre pendant le lancement.
echo.

echo Mise a jour du depot local...
git pull --ff-only origin main

if errorlevel 1 (
    echo.
    echo ERREUR : impossible de recuperer les dernieres modifications.
    echo Verifiez que le depot local est propre et que la connexion GitHub fonctionne.
    pause
    exit /b 1
)

echo.
echo Reconstruction et demarrage des services...
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
