[CmdletBinding()]
param(
    [switch]$Seed,
    [switch]$Fresh,
    [switch]$Laragon,
    [string]$AppUrl = "http://myapes-account.test"
)

$ErrorActionPreference = "Stop"

$BootstrapRootDir = (Resolve-Path (Join-Path $PSScriptRoot "..\..")).Path
Set-Location $BootstrapRootDir

function Invoke-CheckedCommand {
    param(
        [Parameter(Mandatory = $true)]
        [string]$Command,
        [Parameter(ValueFromRemainingArguments = $true)]
        [string[]]$Arguments
    )

    & $Command @Arguments
    if ($LASTEXITCODE -ne 0) {
        throw "Command failed with exit code ${LASTEXITCODE}: $Command $($Arguments -join ' ')"
    }
}

foreach ($tool in @("php", "composer", "npm")) {
    if (-not (Get-Command $tool -ErrorAction SilentlyContinue)) {
        throw "Missing required tool: $tool"
    }
}

$envExampleName = if ($Laragon) { ".env.laragon.example" } else { ".env.local.example" }

foreach ($requiredFile in @(".\artisan", ".\composer.json", ".\$envExampleName")) {
    if (-not (Test-Path -LiteralPath $requiredFile)) {
        throw "Required local bootstrap file is missing: $requiredFile"
    }
}

$envPath = Join-Path $BootstrapRootDir ".env"
if (-not (Test-Path -LiteralPath $envPath)) {
    Copy-Item -LiteralPath (Join-Path $BootstrapRootDir $envExampleName) -Destination $envPath
    Write-Host "Created .env from $envExampleName"
}

$envContent = [System.IO.File]::ReadAllText($envPath)
$appEnvironmentMatch = [regex]::Match($envContent, '(?m)^APP_ENV=(?<value>.*)$')
$appEnvironment = if ($appEnvironmentMatch.Success) {
    $appEnvironmentMatch.Groups['value'].Value.Trim().Trim('"').Trim("'")
} else {
    ""
}

if ($appEnvironment -notin @("local", "testing")) {
    throw "Refusing to rewrite .env because APP_ENV is '$appEnvironment'. Local bootstrap only accepts local or testing environments."
}

function Set-LocalEnvValue {
    param(
        [Parameter(Mandatory = $true)]
        [string]$Key,
        [Parameter(Mandatory = $true)]
        [string]$Value
    )

    $pattern = "(?m)^$([regex]::Escape($Key))=.*$"
    $replacement = "${Key}=${Value}"

    if ([regex]::IsMatch($script:envContent, $pattern)) {
        $script:envContent = [regex]::Replace($script:envContent, $pattern, $replacement, 1)
    } else {
        $script:envContent = $script:envContent.TrimEnd("`r", "`n") + "`r`n${replacement}`r`n"
    }
}

$sqlitePath = Join-Path $BootstrapRootDir "database\database.sqlite"
if (-not (Test-Path -LiteralPath $sqlitePath)) {
    New-Item -ItemType File -Path $sqlitePath | Out-Null
}

$sqliteEnvPath = $sqlitePath.Replace("\", "/")
$appUrl = if ($Laragon) { $AppUrl.TrimEnd("/") } else { "http://127.0.0.1:8000" }
Set-LocalEnvValue -Key "APP_ENV" -Value "local"
Set-LocalEnvValue -Key "APP_DEBUG" -Value "true"
Set-LocalEnvValue -Key "APP_URL" -Value $appUrl
if ($Laragon) {
    Set-LocalEnvValue -Key "OIDC_REDIRECT_URI" -Value "$appUrl/staff/auth/callback"
    Set-LocalEnvValue -Key "VITE_DEV_SERVER_URL" -Value "http://127.0.0.1:5173"
}
Set-LocalEnvValue -Key "DB_CONNECTION" -Value "sqlite"
Set-LocalEnvValue -Key "DB_DATABASE" -Value $sqliteEnvPath
Set-LocalEnvValue -Key "CACHE_STORE" -Value "file"
Set-LocalEnvValue -Key "SESSION_DRIVER" -Value "file"
Set-LocalEnvValue -Key "SESSION_SECURE_COOKIE" -Value "false"
Set-LocalEnvValue -Key "QUEUE_CONNECTION" -Value "sync"
Set-LocalEnvValue -Key "MAIL_MAILER" -Value "log"

[System.IO.File]::WriteAllText(
    $envPath,
    $envContent,
    [System.Text.UTF8Encoding]::new($false)
)

Invoke-CheckedCommand composer install --no-interaction --prefer-dist
Invoke-CheckedCommand npm install --no-audit --no-fund

$refreshedEnv = [System.IO.File]::ReadAllText($envPath)
if ($refreshedEnv -match '(?m)^APP_KEY=\s*$') {
    Invoke-CheckedCommand php artisan key:generate --force
}

if ($Fresh) {
    Write-Host "Running destructive local QA reset (migrate:fresh --seed)."
    Invoke-CheckedCommand php artisan migrate:fresh --seed --force
} elseif ($Seed) {
    Invoke-CheckedCommand php artisan migrate --force
    Invoke-CheckedCommand php artisan db:seed --force
} else {
    Invoke-CheckedCommand php artisan migrate --force
}

# Dot-sourcing selective-media-boundary.ps1 declares a script-level $RootDir param that
# would overwrite a caller variable of the same name; keep the bootstrap path separate.
. (Join-Path $PSScriptRoot "selective-media-boundary.ps1")
Assert-SelectiveMediaBoundary -RootDir $BootstrapRootDir -CreateAvatarLink
Invoke-CheckedCommand npm run build

if ($Laragon) {
    Write-Host "Local Laragon bootstrap complete. Start Laragon Apache, then run 'composer run dev:laragon'."
} else {
    Write-Host "Local bootstrap complete. Run 'composer run dev' to start MyAPES Account."
}
