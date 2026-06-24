param(
    [Parameter(Mandatory = $true)]
    [string]$Root,

    [int]$Port = 8080
)

$ErrorActionPreference = "Stop"

function Get-ContentType {
    param([string]$Path)

    switch ([System.IO.Path]::GetExtension($Path).ToLowerInvariant()) {
        ".html" { "text/html; charset=utf-8" }
        ".js" { "application/javascript; charset=utf-8" }
        ".css" { "text/css; charset=utf-8" }
        ".json" { "application/json; charset=utf-8" }
        ".svg" { "image/svg+xml" }
        ".png" { "image/png" }
        ".jpg" { "image/jpeg" }
        ".jpeg" { "image/jpeg" }
        ".gif" { "image/gif" }
        ".ico" { "image/x-icon" }
        ".wasm" { "application/wasm" }
        ".woff" { "font/woff" }
        ".woff2" { "font/woff2" }
        ".map" { "application/json; charset=utf-8" }
        ".txt" { "text/plain; charset=utf-8" }
        default { "application/octet-stream" }
    }
}

function Resolve-AssetPath {
    param(
        [string]$RequestPath,
        [string]$RootPath
    )

    $relativePath = [Uri]::UnescapeDataString($RequestPath.TrimStart("/"))
    if ([string]::IsNullOrWhiteSpace($relativePath)) {
        return (Join-Path $RootPath "index.html")
    }

    $relativePath = $relativePath -replace "/", [System.IO.Path]::DirectorySeparatorChar
    $candidate = Join-Path $RootPath $relativePath

    if (Test-Path $candidate -PathType Container) {
        $candidate = Join-Path $candidate "index.html"
    }

    if (Test-Path $candidate -PathType Leaf) {
        return $candidate
    }

    if (-not ([System.IO.Path]::GetExtension($candidate))) {
        $indexFallback = Join-Path $RootPath "index.html"
        if (Test-Path $indexFallback -PathType Leaf) {
            return $indexFallback
        }
    }

    return $candidate
}

function Start-WithPython {
    param(
        [string]$Interpreter,
        [string]$RootPath,
        [int]$ServerPort
    )

    $arguments = @(
        "-m", "http.server", "$ServerPort",
        "--bind", "127.0.0.1",
        "--directory", $RootPath
    )

    Start-Process -FilePath $Interpreter -ArgumentList $arguments -WindowStyle Hidden -WorkingDirectory $RootPath | Out-Null
}

if (-not (Test-Path (Join-Path $Root "index.html"))) {
    throw "Le build Flutter local est introuvable dans '$Root'."
}

$python = Get-Command python -ErrorAction SilentlyContinue
if (-not $python) {
    $python = Get-Command python3 -ErrorAction SilentlyContinue
}

if ($python) {
    try {
        $pythonPath = $python.Path
        if (-not $pythonPath) {
            $pythonPath = $python.Source
        }
        Start-WithPython -Interpreter $pythonPath -RootPath $Root -ServerPort $Port
        exit 0
    } catch {
        # Fallback vers un serveur PowerShell si Python ne peut pas demarrer.
    }
}

$php = Get-Command php -ErrorAction SilentlyContinue
if ($php) {
    try {
        $phpPath = $php.Path
        if (-not $phpPath) {
            $phpPath = $php.Source
        }
        if (-not $phpPath) {
            $phpPath = "php"
        }
        Start-Process -FilePath $phpPath -ArgumentList @("-S", "127.0.0.1:$Port", "-t", $Root) -WindowStyle Hidden -WorkingDirectory $Root | Out-Null
        exit 0
    } catch {
        # Fallback vers HttpListener ci-dessous.
    }
}

$listener = [System.Net.HttpListener]::new()
$listener.Prefixes.Add("http://127.0.0.1:$Port/")

try {
    $listener.Start()
    while ($listener.IsListening) {
        $context = $listener.GetContext()
        $response = $context.Response

        try {
            $assetPath = Resolve-AssetPath -RequestPath $context.Request.Url.AbsolutePath -RootPath $Root
            if (-not (Test-Path $assetPath -PathType Leaf)) {
                $response.StatusCode = 404
                $bytes = [System.Text.Encoding]::UTF8.GetBytes("404 Not Found")
                $response.ContentType = "text/plain; charset=utf-8"
                $response.ContentLength64 = $bytes.Length
                $response.OutputStream.Write($bytes, 0, $bytes.Length)
                continue
            }

            $bytes = [System.IO.File]::ReadAllBytes($assetPath)
            $response.ContentType = Get-ContentType -Path $assetPath
            $response.ContentLength64 = $bytes.Length
            $response.OutputStream.Write($bytes, 0, $bytes.Length)
        } finally {
            $response.OutputStream.Close()
        }
    }
} finally {
    if ($listener.IsListening) {
        $listener.Stop()
    }
    $listener.Close()
}
