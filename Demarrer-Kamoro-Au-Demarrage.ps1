$ErrorActionPreference = "Stop"

$ProjectRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
$LocalWebRoot = Join-Path $ProjectRoot "hestia_app/build/web"
$LocalWebServerScript = Join-Path $ProjectRoot "Start-Kamoro-LocalWebServer.ps1"
$LogDir = Join-Path $ProjectRoot ".startup-logs"
$StdOutLog = Join-Path $LogDir "auto-start.out.log"
$StdErrLog = Join-Path $LogDir "auto-start.err.log"
$LogFile = Join-Path $LogDir "auto-start.log"

New-Item -ItemType Directory -Force -Path $LogDir | Out-Null

function Write-Log {
    param([string]$Message)

    $Timestamp = Get-Date -Format "yyyy-MM-dd HH:mm:ss"
    Add-Content -Path $LogFile -Value "[$Timestamp] $Message"
}

function Test-InternetAccess {
    $client = $null
    try {
        $client = [System.Net.Http.HttpClient]::new()
        $client.Timeout = [TimeSpan]::FromSeconds(3)
        $response = $client.GetAsync("https://github.com/").GetAwaiter().GetResult()
        return $response.IsSuccessStatusCode
    } catch {
        return $false
    } finally {
        if ($client) {
            $client.Dispose()
        }
    }
}

function Start-LocalFallback {
    if (-not (Test-Path (Join-Path $LocalWebRoot "index.html"))) {
        throw "Le build Flutter local est introuvable dans '$LocalWebRoot'."
    }

    if (-not (Test-Path $LocalWebServerScript)) {
        throw "Le script de secours local est introuvable."
    }

    Write-Log "Lancement du fallback local."
    Start-Process -FilePath (Join-Path $PSHOME "powershell.exe") `
        -ArgumentList @(
            "-NoProfile",
            "-ExecutionPolicy",
            "Bypass",
            "-WindowStyle",
            "Hidden",
            "-File",
            $LocalWebServerScript,
            "-Root",
            $LocalWebRoot,
            "-Port",
            "8080"
        ) `
        -WorkingDirectory $ProjectRoot | Out-Null

    Start-Sleep -Seconds 2
    Start-Process "http://127.0.0.1:8080/index.html" | Out-Null
    Write-Log "Fallback local lance."
}

function Wait-ForDocker {
    param([int]$Attempts = 10)

    for ($i = 1; $i -le $Attempts; $i++) {
        try {
            docker info | Out-Null
            return
        } catch {
            Start-Sleep -Seconds 3
        }
    }

    throw "Docker n'est pas pret apres $Attempts tentatives."
}

Set-Location $ProjectRoot

try {
    if (-not (Test-InternetAccess)) {
        throw "Aucune connexion internet detectee."
    }

    Write-Log "Mise a jour du depot local..."
    git -C $ProjectRoot pull --ff-only origin main | Out-Null
    if ($LASTEXITCODE -ne 0) {
        Write-Log "Echec du git pull."
        throw "Impossible de recuperer les dernieres modifications."
    }

    Write-Log "Attente de Docker Desktop..."
    Wait-ForDocker

    Write-Log "Lancement de docker compose..."
    $Process = Start-Process -FilePath "docker" -ArgumentList @("compose", "up", "-d", "--build") -WorkingDirectory $ProjectRoot -WindowStyle Hidden -PassThru -RedirectStandardOutput $StdOutLog -RedirectStandardError $StdErrLog
    $Process.WaitForExit()

    if ($Process.ExitCode -ne 0) {
        Write-Log "Echec de docker compose avec le code $($Process.ExitCode)."
        throw "Lancement automatique Kamoro echoue."
    }

    Write-Log "Kamoro est lance via Docker."
} catch {
    Write-Log "Fallback local active: $($_.Exception.Message)"
    Start-LocalFallback
}
