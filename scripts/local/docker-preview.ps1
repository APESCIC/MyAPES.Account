[CmdletBinding()]
param(
    [switch]$Build,
    [switch]$Migrate,
    [switch]$Fresh,
    [string]$HostName = "myaccount.myapes.test",
    [int]$Port = 8081,
    [int]$VitePort = 5175,
    [int]$HealthTimeoutSeconds = 240
)

$ErrorActionPreference = "Stop"

$RootDir = (Resolve-Path (Join-Path $PSScriptRoot "..\..")).Path
Set-Location $RootDir

$Services = @("app", "web", "node")

function Invoke-Compose {
    & docker compose @args
    if ($LASTEXITCODE -ne 0) {
        throw "docker compose $($args -join ' ') failed with exit code $LASTEXITCODE."
    }
}

function Get-ComposeServices {
    param([switch]$Running)

    $arguments = @("compose", "ps", "--services")
    if ($Running) {
        $arguments += @("--status", "running")
    } else {
        $arguments += "-a"
    }

    $output = & docker @arguments
    if ($LASTEXITCODE -ne 0) {
        throw "docker compose ps failed with exit code $LASTEXITCODE."
    }

    return @($output | ForEach-Object { "$_".Trim() } | Where-Object { $_ })
}

function Invoke-App {
    & docker compose exec -T app @args
    if ($LASTEXITCODE -ne 0) {
        throw "Command failed in the app container with exit code ${LASTEXITCODE}: $($args -join ' ')"
    }
}

& docker info --format "{{.ServerVersion}}" *> $null
if ($LASTEXITCODE -ne 0) {
    throw "Docker is not running. Start Docker Desktop, then run this script again."
}

$hostsPath = Join-Path $env:SystemRoot "System32\drivers\etc\hosts"
$hostsPattern = "^\s*127\.0\.0\.1\s+(.*\s)?$([regex]::Escape($HostName))(\s|$)"
if (-not (Select-String -LiteralPath $hostsPath -Pattern $hostsPattern -Quiet)) {
    Write-Warning "The hosts file has no entry for $HostName. In an elevated PowerShell, run:"
    Write-Warning "  Add-Content -Path `"$hostsPath`" -Value `"127.0.0.1`t$HostName`""
}

# Always check for existing containers before creating any.
$existing = Get-ComposeServices
$running = Get-ComposeServices -Running
$missing = @($Services | Where-Object { $existing -notcontains $_ })
$stopped = @($Services | Where-Object { ($existing -contains $_) -and ($running -notcontains $_) })

if ($Build) {
    Write-Host "Rebuilding images; docker compose keeps any container whose image and config are unchanged."
    Invoke-Compose up -d --build
    $containerState = "rebuilt"
} elseif ($missing.Count -eq 0 -and $stopped.Count -eq 0) {
    Write-Host "Reusing running containers: $($Services -join ', ')."
    $containerState = "reused"
} elseif ($missing.Count -eq 0) {
    Write-Host "Starting existing stopped containers: $($stopped -join ', ')."
    Invoke-Compose start
    $containerState = "started"
} elseif ($existing.Count -eq 0) {
    Write-Host "No MyAPES Account containers found. Creating them."
    Invoke-Compose up -d
    $containerState = "created"
} else {
    Write-Host "Creating missing containers ($($missing -join ', ')) and keeping the existing ones."
    Invoke-Compose up -d
    $containerState = "completed"
}

$envPath = Join-Path $RootDir ".env"
if (-not (Test-Path -LiteralPath $envPath)) {
    Copy-Item -LiteralPath (Join-Path $RootDir ".env.docker.example") -Destination $envPath
    Write-Host "Created .env from .env.docker.example."
}

$envContent = [System.IO.File]::ReadAllText($envPath)
$appEnvironmentMatch = [regex]::Match($envContent, '(?m)^APP_ENV=(?<value>.*)$')
$appEnvironment = if ($appEnvironmentMatch.Success) {
    $appEnvironmentMatch.Groups['value'].Value.Trim().Trim('"').Trim("'")
} else {
    ""
}
if ($appEnvironment -notin @("local", "testing")) {
    throw "Refusing to prepare the app because APP_ENV is '$appEnvironment'. The Docker preview only accepts local or testing."
}

$sqlitePath = Join-Path $RootDir "database\database.sqlite"
$databaseCreated = $false
if (-not (Test-Path -LiteralPath $sqlitePath)) {
    New-Item -ItemType File -Path $sqlitePath | Out-Null
    $databaseCreated = $true
    Write-Host "Created database\database.sqlite."
}

if (-not (Test-Path -LiteralPath (Join-Path $RootDir "vendor\autoload.php"))) {
    Invoke-App composer install --no-interaction --prefer-dist
}

if ($envContent -match '(?m)^APP_KEY=\s*$') {
    Invoke-App php artisan key:generate --force
}

if ($Fresh) {
    Write-Host "Running destructive local QA reset (migrate:fresh --seed)."
    Invoke-App php artisan migrate:fresh --seed --force
} elseif ($Migrate -or $databaseCreated) {
    Invoke-App php artisan migrate --force
} else {
    $pending = & docker compose exec -T app php artisan migrate:status --pending 2>$null
    if ($LASTEXITCODE -eq 0 -and ($pending -join "`n") -match 'Pending') {
        Write-Warning "There are pending migrations. Run this script again with -Migrate to apply them."
    }
}

$healthUrl = "http://127.0.0.1:$Port/healthz"
$deadline = (Get-Date).AddSeconds($HealthTimeoutSeconds)
$healthy = $false
while ((Get-Date) -lt $deadline) {
    try {
        $response = Invoke-WebRequest -Uri $healthUrl -UseBasicParsing -TimeoutSec 5
        if ($response.StatusCode -eq 200) {
            $healthy = $true
            break
        }
    } catch {
        Start-Sleep -Seconds 2
    }
}
if (-not $healthy) {
    throw "The preview did not answer $healthUrl within $HealthTimeoutSeconds seconds. Check 'docker compose logs app web'."
}

$hotPath = Join-Path $RootDir "public\hot"
$hotDeadline = (Get-Date).AddSeconds($HealthTimeoutSeconds)
while (-not (Test-Path -LiteralPath $hotPath) -and (Get-Date) -lt $hotDeadline) {
    Start-Sleep -Seconds 2
}
if (-not (Test-Path -LiteralPath $hotPath)) {
    Write-Warning "Vite has not started yet (public\hot is missing). Check 'docker compose logs node'."
}

Write-Host ""
Write-Host "Containers: $containerState"
Write-Host "Preview: http://${HostName}:$Port"
Write-Host "Vite HMR: http://${HostName}:$VitePort"
