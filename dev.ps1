$ErrorActionPreference = "Stop"

$ProjectRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
$LogDir = Join-Path $ProjectRoot ".dev-logs"

New-Item -ItemType Directory -Force -Path $LogDir | Out-Null
New-Item -ItemType Directory -Force -Path (Join-Path $LogDir "matplotlib") | Out-Null
New-Item -ItemType Directory -Force -Path (Join-Path $LogDir "cache") | Out-Null
New-Item -ItemType File -Force -Path (Join-Path $ProjectRoot "database.sqlite") | Out-Null

function Stop-ProcessOnPort {
    param([int]$Port)

    $connections = Get-NetTCPConnection -LocalPort $Port -ErrorAction SilentlyContinue
    if (-not $connections) {
        return
    }

    $connections |
        Select-Object -ExpandProperty OwningProcess -Unique |
        ForEach-Object {
            try {
                Stop-Process -Id $_ -Force -ErrorAction SilentlyContinue
            } catch {
            }
        }
}

function Wait-ForUrl {
    param(
        [string]$Name,
        [string]$Url,
        [int]$Attempts = 30
    )

    for ($i = 1; $i -le $Attempts; $i++) {
        try {
            Invoke-WebRequest -Uri $Url -UseBasicParsing -TimeoutSec 2 | Out-Null
            Write-Host "[OK] [$Name] Service pret."
            return
        } catch {
            Start-Sleep -Seconds 1
        }
    }

    throw "[ERREUR] [$Name] Service indisponible apres ${Attempts}s."
}

function Get-PythonCommand {
    $python = Get-Command python -ErrorAction SilentlyContinue
    if ($python) {
        return [pscustomobject]@{ FilePath = $python.Source; Arguments = @() }
    }

    $py = Get-Command py -ErrorAction SilentlyContinue
    if ($py) {
        return [pscustomobject]@{ FilePath = $py.Source; Arguments = @("-3") }
    }

    throw "Python introuvable. Installez Python 3.11+ et assurez-vous qu'il est disponible dans le PATH."
}

Write-Host "Lancement de l'environnement de developpement..."

Stop-ProcessOnPort -Port 8001
Stop-ProcessOnPort -Port 8000
Stop-ProcessOnPort -Port 8080

$AiDir = Join-Path $ProjectRoot "hestia-ai"
$LaravelDir = Join-Path $ProjectRoot "hestiapredict"
$FlutterDir = Join-Path $ProjectRoot "hestia_app"

$AiLog = Join-Path $LogDir "ai.log"
$LaravelLog = Join-Path $LogDir "laravel.log"
$FlutterLog = Join-Path $LogDir "flutter.log"
$MigrateLog = Join-Path $LogDir "migrate.log"

$PythonCommand = Get-PythonCommand

Push-Location $AiDir
$env:MPLCONFIGDIR = Join-Path $LogDir "matplotlib"
$env:XDG_CACHE_HOME = Join-Path $LogDir "cache"
$env:WATCHFILES_FORCE_POLLING = "true"
$AiArgs = @()
$AiArgs += $PythonCommand.Arguments
$AiArgs += @("-m", "uvicorn", "main:app", "--host", "127.0.0.1", "--port", "8001")
$AiProcess = Start-Process -FilePath $PythonCommand.FilePath -ArgumentList $AiArgs -PassThru -WindowStyle Hidden -RedirectStandardOutput $AiLog -RedirectStandardError $AiLog
Pop-Location
Wait-ForUrl -Name "AI" -Url "http://127.0.0.1:8001/health" -Attempts 30

Push-Location $LaravelDir
$env:AI_ENGINE_URL = "http://127.0.0.1:8001"
php artisan migrate --force | Out-File -FilePath $MigrateLog -Encoding utf8
$LaravelProcess = Start-Process -FilePath "php" -ArgumentList @("artisan", "serve", "--host=127.0.0.1", "--port=8000") -PassThru -WindowStyle Hidden -RedirectStandardOutput $LaravelLog -RedirectStandardError $LaravelLog
Pop-Location
Wait-ForUrl -Name "Laravel" -Url "http://127.0.0.1:8000/api/live-availability" -Attempts 30

Push-Location $FlutterDir
flutter build web --pwa-strategy=none --dart-define=API_BASE_URL=http://127.0.0.1:8000 | Out-File -FilePath $FlutterLog -Encoding utf8
Pop-Location

$FlutterWebDir = Join-Path $FlutterDir "build/web"
$FlutterProcess = Start-Process -FilePath "php" -ArgumentList @("-S", "127.0.0.1:8080") -WorkingDirectory $FlutterWebDir -PassThru -WindowStyle Hidden -RedirectStandardOutput $FlutterLog -RedirectStandardError $FlutterLog
Wait-ForUrl -Name "Flutter" -Url "http://127.0.0.1:8080/index.html" -Attempts 90

Write-Host ""
Write-Host "[OK] Environnement pret."
Write-Host "Liens a ouvrir dans le navigateur :"
Write-Host "   - App Flutter : http://127.0.0.1:8080/index.html"
Write-Host "   - Dashboard Laravel : http://127.0.0.1:8000/dashboard"
Write-Host "   - API IA : http://127.0.0.1:8001/docs"
Write-Host ""
Write-Host "Logs :"
Write-Host "   - $AiLog"
Write-Host "   - $MigrateLog"
Write-Host "   - $LaravelLog"
Write-Host "   - $FlutterLog"
Write-Host ""
Write-Host "PIDs :"
Write-Host "   - AI : $($AiProcess.Id)"
Write-Host "   - Laravel : $($LaravelProcess.Id)"
Write-Host "   - Flutter : $($FlutterProcess.Id)"
