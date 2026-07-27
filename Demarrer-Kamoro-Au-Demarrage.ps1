$ErrorActionPreference = "Stop"

$ProjectRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
$LogDir = Join-Path $ProjectRoot ".startup-logs"
$StdOutLog = Join-Path $LogDir "auto-start.out.log"
$StdErrLog = Join-Path $LogDir "auto-start.err.log"
$AiStdOutLog = Join-Path $LogDir "ai.out.log"
$AiStdErrLog = Join-Path $LogDir "ai.err.log"
$LogFile = Join-Path $LogDir "auto-start.log"
$AppUrl = "http://127.0.0.1:8080/index.html"
$DockerConfigDir = Join-Path $env:TEMP "Kamoro-Docker-Config"
$LauncherMutex = $null
$OwnsMutex = $false

New-Item -ItemType Directory -Force -Path $LogDir | Out-Null
New-Item -ItemType Directory -Force -Path $DockerConfigDir | Out-Null
$env:DOCKER_CONFIG = $DockerConfigDir

function Write-Log {
    param([string]$Message)

    $Timestamp = Get-Date -Format "yyyy-MM-dd HH:mm:ss"
    Add-Content -Path $LogFile -Value "[$Timestamp] $Message"
}

function Get-DockerExecutable {
    $docker = Get-Command docker -ErrorAction SilentlyContinue
    if ($docker) {
        if ($docker.Path) {
            return $docker.Path
        }

        if ($docker.Source) {
            return $docker.Source
        }
    }

    $candidatePaths = @(
        (Join-Path $env:ProgramFiles "Docker\Docker\resources\bin\docker.exe"),
        (Join-Path ${env:ProgramFiles(x86)} "Docker\Docker\resources\bin\docker.exe")
    ) | Where-Object { $_ -and (Test-Path $_) }

    if ($candidatePaths.Count -gt 0) {
        return $candidatePaths[0]
    }

    throw "Docker CLI introuvable. Installez Docker Desktop ou ajoutez docker.exe au PATH."
}

function Get-DockerDesktopExecutable {
    $candidate = Join-Path $env:ProgramFiles "Docker\Docker\Docker Desktop.exe"
    if (Test-Path $candidate) {
        return $candidate
    }

    throw "Docker Desktop.exe est introuvable dans Program Files."
}

function Start-DockerDesktop {
    try {
        $service = Get-Service com.docker.service -ErrorAction SilentlyContinue
        if ($service -and $service.Status -ne "Running") {
            Write-Log "Tentative de demarrage du service Docker Desktop..."
            try {
                Start-Service com.docker.service -ErrorAction Stop
            } catch {
                Write-Log "Impossible de demarrer le service Docker Desktop depuis cette session: $($_.Exception.Message)"
            }
        }

        $DesktopExe = Get-DockerDesktopExecutable
        Write-Log "Lancement de Docker Desktop..."
        Start-Process -FilePath $DesktopExe -WorkingDirectory $ProjectRoot | Out-Null
    } catch {
        Write-Log "Impossible de lancer Docker Desktop automatiquement: $($_.Exception.Message)"
    }
}

function Test-DockerEngineReady {
    param(
        [string]$DockerExe,
        [ref]$Details
    )

    $Details.Value = ""

    try {
        $output = & $DockerExe info 2>&1
        $Details.Value = ($output | Out-String).Trim()
        return ($LASTEXITCODE -eq 0)
    } catch {
        $Details.Value = $_.Exception.Message
        return $false
    }
}

function Wait-ForDocker {
    param(
        [int]$Attempts = 40,
        [int]$StableSuccesses = 3,
        [int]$RetryDelaySeconds = 3,
        [int]$RestartAtAttempt = 8
    )

    $DockerExe = Get-DockerExecutable
    $DesktopStarted = $false
    $StableCount = 0

    for ($i = 1; $i -le $Attempts; $i++) {
        $Details = ""
        $IsReady = Test-DockerEngineReady -DockerExe $DockerExe -Details ([ref]$Details)
        if ($IsReady) {
            $StableCount++
            Write-Log "Docker repond correctement (tentative $i/$Attempts, validation $StableCount/$StableSuccesses)."
            if ($StableCount -ge $StableSuccesses) {
                return
            }
        } else {
            $StableCount = 0
            if ($Details) {
                Write-Log "Docker pas encore pret (tentative $i/$Attempts) : $Details"
            } else {
                Write-Log "Docker pas encore pret (tentative $i/$Attempts)."
            }
        }

        if (-not $DesktopStarted -or $i -eq $RestartAtAttempt) {
            Start-DockerDesktop
            $DesktopStarted = $true
        }

        Start-Sleep -Seconds $RetryDelaySeconds
    }

    throw "Docker n'est pas pret apres $Attempts tentatives."
}

function Open-AppUrl {
    param([string]$Url)

    Start-Sleep -Seconds 2
    Start-Process $Url | Out-Null
}

function Stop-ExistingComposeStack {
    param([string]$DockerExe)

    Write-Log "Arret de l'ancienne pile Docker..."
    $StopProcess = Start-Process -FilePath $DockerExe -ArgumentList @(
        "compose",
        "down",
        "--remove-orphans"
    ) -WorkingDirectory $ProjectRoot -PassThru -WindowStyle Hidden -RedirectStandardOutput $StdOutLog -RedirectStandardError $StdErrLog

    if (-not $StopProcess.WaitForExit(5 * 60 * 1000)) {
        try {
            $StopProcess.Kill()
        } catch {
            # Ignore les erreurs de terminaison forcee.
        }

        throw "docker compose down n'a pas termine dans le delai imparti."
    }

    if ($StopProcess.ExitCode -ne 0) {
        Write-Log "docker compose down a retourne le code $($StopProcess.ExitCode). On poursuit quand meme."
    }
}

function Stop-PortListeners {
    $Ports = @(8000, 8001, 8080)
    $ProcessIds = @()
    $NetstatOutput = @(netstat -ano -p tcp 2>$null)

    foreach ($Line in $NetstatOutput) {
        if ($Line -match '^\s*TCP\s+\S+:(8000|8001|8080)\s+\S+\s+LISTENING\s+(\d+)\s*$') {
            $ProcessIds += [int] $Matches[2]
        }
    }

    $ProcessIds = $ProcessIds |
        Sort-Object -Unique |
        Where-Object { $_ -and $_ -ne $PID -and $_ -ne 0 }

    foreach ($ProcessId in $ProcessIds) {
        try {
            $Process = Get-Process -Id $ProcessId -ErrorAction Stop
            Write-Log "Liberation du port utilise par $($Process.ProcessName) (PID $ProcessId)."
            Stop-Process -Id $ProcessId -Force -ErrorAction Stop
        } catch {
            Write-Log "Impossible de terminer le processus PID $ProcessId : $($_.Exception.Message)"
        }
    }
}

function Wait-ForAppPort {
    param(
        [int]$TimeoutSeconds = 120,
        [int]$PollSeconds = 2
    )

    $deadline = (Get-Date).AddSeconds($TimeoutSeconds)
    while ((Get-Date) -lt $deadline) {
        if (Test-AppPortOpen) {
            return $true
        }

        Start-Sleep -Seconds $PollSeconds
    }

    return $false
}

function Test-AppPortOpen {
    param(
        [string]$Address = "127.0.0.1",
        [int]$Port = 8080,
        [int]$TimeoutMilliseconds = 1000
    )

    $client = [System.Net.Sockets.TcpClient]::new()
    try {
        $async = $client.BeginConnect($Address, $Port, $null, $null)
        if (-not $async.AsyncWaitHandle.WaitOne($TimeoutMilliseconds, $false)) {
            return $false
        }

        $client.EndConnect($async)
        return $client.Connected
    } catch {
        return $false
    } finally {
        $client.Close()
    }
}

function Start-OptionalAiEngine {
    param([string]$DockerExe)

    Write-Log "Demarrage optionnel du moteur IA en arriere-plan..."
    try {
        $AiProcess = Start-Process -FilePath $DockerExe -ArgumentList @(
            "compose",
            "--progress",
            "plain",
            "--profile",
            "ai",
            "up",
            "-d",
            "--build",
            "ai-engine"
        ) -WorkingDirectory $ProjectRoot -PassThru -WindowStyle Hidden -RedirectStandardOutput $AiStdOutLog -RedirectStandardError $AiStdErrLog

        Write-Log "Moteur IA lance en arriere-plan (PID $($AiProcess.Id)). Les prix statiques restent disponibles s'il echoue."
    } catch {
        Write-Log "Impossible de lancer le moteur IA optionnel : $($_.Exception.Message). Laravel continuera avec les prix statiques."
    }
}

Set-Location $ProjectRoot

try {
    $CreatedNew = $false
    $LauncherMutex = New-Object System.Threading.Mutex($false, "Global\KamoroReservationFacturationLauncher", [ref]$CreatedNew)
    if (-not $CreatedNew) {
        Write-Log "Une autre instance du lanceur est deja en cours. Sortie sans relancer Docker."
        return
    }

    $OwnsMutex = $true

    Write-Log "Lancement a partir de la copie locale courante."
    if (Test-AppPortOpen) {
        Write-Log "Une instance repond deja sur 127.0.0.1:8080. Le lanceur va la remplacer."
    }

    Write-Log "Attente de Docker Desktop..."
    $DockerExe = Get-DockerExecutable
    Wait-ForDocker

    Stop-ExistingComposeStack -DockerExe $DockerExe
    Stop-PortListeners

    try {
        $SourceRevision = (& git rev-parse --short HEAD 2>$null | Select-Object -First 1).Trim()
    } catch {
        $SourceRevision = ""
    }

    if (-not $SourceRevision) {
        $SourceRevision = (Get-Date).ToString("yyyyMMddHHmmss")
    }

    $env:KAMORO_SOURCE_REV = $SourceRevision
    Write-Log "Revision source utilisee pour le build Docker: $SourceRevision"

    Write-Log "Lancement de docker compose..."
    $ComposeProcess = Start-Process -FilePath $DockerExe -ArgumentList @(
        "compose",
        "--progress",
        "plain",
        "up",
        "-d",
        "--build",
        "--force-recreate",
        "--remove-orphans",
        "laravel",
        "frontend"
    ) -WorkingDirectory $ProjectRoot -PassThru -WindowStyle Hidden -RedirectStandardOutput $StdOutLog -RedirectStandardError $StdErrLog

    if (-not $ComposeProcess.WaitForExit(45 * 60 * 1000)) {
        try {
            $ComposeProcess.Kill()
        } catch {
            # Ignore les erreurs de terminaison forcée.
        }

        throw "docker compose n'a pas termine dans le delai imparti de 45 minutes. Consultez $StdErrLog."
    }

    $ComposeExitCode = $ComposeProcess.ExitCode

    if ($ComposeExitCode -ne $null -and $ComposeExitCode -ne 0) {
        Write-Log "docker compose a rendu un code $ComposeExitCode. Arret du lancement."
        $ComposeError = Get-Content -Path $StdErrLog -Tail 80 -ErrorAction SilentlyContinue | Out-String
        if ($ComposeError.Trim()) {
            Write-Log "Dernieres erreurs docker compose :`n$($ComposeError.Trim())"
        }
        throw "docker compose a echoue avec le code $ComposeExitCode. Consultez $StdErrLog."
    } elseif ($ComposeExitCode -eq $null) {
        Write-Log "docker compose a termine sans code de sortie exploitable. Arret du lancement."
        throw "docker compose n'a pas fourni de code de sortie. Consultez $StdErrLog."
    }

    Write-Log "Verification finale de la stabilite Docker apres le lancement..."
    Wait-ForDocker -Attempts 10 -StableSuccesses 3 -RetryDelaySeconds 3 -RestartAtAttempt 4

    if (-not (Wait-ForAppPort -TimeoutSeconds 120 -PollSeconds 2)) {
        Write-Log "L'application ne repond pas sur 127.0.0.1:8080 apres le lancement Docker."
        throw "Lancement automatique Kamoro echoue."
    }

    Start-OptionalAiEngine -DockerExe $DockerExe
    Write-Log "Kamoro est lance via Docker."
    Open-AppUrl -Url $AppUrl
} catch {
    Write-Log "Echec du lancement Docker: $($_.Exception.Message)"
    throw
} finally {
    if ($OwnsMutex -and $LauncherMutex) {
        try {
            $null = $LauncherMutex.ReleaseMutex()
        } catch {
            # Le mutex peut deja avoir ete libere si le script a quitte tres tot.
        }

        $LauncherMutex.Dispose()
    }
}
