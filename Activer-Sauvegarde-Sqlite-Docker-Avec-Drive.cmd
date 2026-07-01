@echo off
setlocal

cd /d "%~dp0"

echo.
echo Collez le chemin du dossier Google Drive ou Drive synchronise.
echo Exemple : C:\Users\VotreNom\Google Drive\Kamoro Backups
echo Exemple : G:\My Drive\Kamoro Backups
echo.
set /p DRIVE_PATH=Chemin du dossier Drive : 

if "%DRIVE_PATH%"=="" (
    echo.
    echo ERREUR : aucun chemin Drive indique.
    pause
    exit /b 1
)

powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0Activer-Sauvegarde-Sqlite-Docker.ps1" -DriveBackupRoot "%DRIVE_PATH%"

if errorlevel 1 (
    echo.
    echo ERREUR : l'activation de la sauvegarde automatique avec Drive a echoue.
    pause
    exit /b 1
)

echo.
pause
