$ErrorActionPreference = "Stop"

$ProjectRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
$LogDir = Join-Path $ProjectRoot ".startup-logs"
$StdOutLog = Join-Path $LogDir "auto-start.out.log"
$StdErrLog = Join-Path $LogDir "auto-start.err.log"
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

function Invoke-GitPullWithTimeout {
    param(
        [int]$TimeoutSeconds = 3
    )

    $git = Get-Command git -ErrorAction SilentlyContinue
    if (-not $git) {
        throw "Git n'est pas disponible dans le PATH."
    }

    $gitProcess = Start-Process -FilePath $git.Path -ArgumentList @(
        "-C",
        $ProjectRoot,
        "pull",
        "--ff-only",
        "origin",
        "main"
    ) -PassThru -WindowStyle Hidden -RedirectStandardOutput $StdOutLog -RedirectStandardError $StdErrLog

    if (-not $gitProcess.WaitForExit($TimeoutSeconds * 1000)) {
        try {
            $gitProcess.Kill()
        } catch {
            # Ignore les erreurs de terminaison forcée.
        }

        return @{
            TimedOut = $true
            ExitCode = $null
        }
    }

    return @{
        TimedOut = $false
        ExitCode = $gitProcess.ExitCode
    }
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

Set-Location $ProjectRoot

try {
    $CreatedNew = $false
    $LauncherMutex = New-Object System.Threading.Mutex($false, "Global\KamoroReservationFacturationLauncher", [ref]$CreatedNew)
    if (-not $CreatedNew) {
        Write-Log "Une autre instance du lanceur est deja en cours. Sortie sans relancer Docker."
        return
    }

    $OwnsMutex = $true

    if (Test-AppPortOpen) {
        Write-Log "Une instance repond deja sur 127.0.0.1:8080. Le lanceur va la remplacer par la version locale courante."
    }

    Write-Log "Utilisation du depot local courant. Aucune mise a jour Git automatique n'est effectuee par le lanceur."

    Write-Log "Attente de Docker Desktop..."
    $DockerExe = Get-DockerExecutable
    Wait-ForDocker

    Stop-ExistingComposeStack -DockerExe $DockerExe

    Write-Log "Lancement de docker compose..."
    $ComposeProcess = Start-Process -FilePath $DockerExe -ArgumentList @(
        "compose",
        "--progress",
        "plain",
        "up",
        "-d",
        "--build",
        "--force-recreate",
        "--remove-orphans"
    ) -WorkingDirectory $ProjectRoot -PassThru -WindowStyle Hidden -RedirectStandardOutput $StdOutLog -RedirectStandardError $StdErrLog

    if (-not $ComposeProcess.WaitForExit(20 * 60 * 1000)) {
        try {
            $ComposeProcess.Kill()
        } catch {
            # Ignore les erreurs de terminaison forcée.
        }

        throw "docker compose n'a pas termine dans le delai imparti."
    }

    $ComposeExitCode = $ComposeProcess.ExitCode

    if ($ComposeExitCode -ne $null -and $ComposeExitCode -ne 0) {
        Write-Log "docker compose a rendu un code $ComposeExitCode. Verification de l'application avant echec."
    } elseif ($ComposeExitCode -eq $null) {
        Write-Log "docker compose a termine sans code de sortie exploitable. Verification de l'application."
    }

    Write-Log "Verification finale de la stabilite Docker apres le lancement..."
    Wait-ForDocker -Attempts 10 -StableSuccesses 3 -RetryDelaySeconds 3 -RestartAtAttempt 4

    if (-not (Wait-ForAppPort -TimeoutSeconds 120 -PollSeconds 2)) {
        Write-Log "L'application ne repond pas sur 127.0.0.1:8080 apres le lancement Docker."
        throw "Lancement automatique Kamoro echoue."
    }

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
