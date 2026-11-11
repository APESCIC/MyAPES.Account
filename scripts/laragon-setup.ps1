<#
.SYNOPSIS
    Sets up MyAPES Account as its own Laragon project. Safe to run more than once.

.DESCRIPTION
    Reads the expected versions from docs/laragon/profile/myapes-account.ini and the
    database, URL and Vite port from .env.example, checks Laragon's PHP, Composer, Node,
    npm and MySQL, creates .env when it is missing, installs dependencies, creates this
    project's database, runs migrations and optionally loads the demo data.

    It only touches this repository and the database named in .env (which must end in
    _local). It never edits Laragon's settings, other projects' folders, databases or vhosts.

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File .\scripts\laragon-setup.ps1
.EXAMPLE
    powershell -ExecutionPolicy Bypass -File .\scripts\laragon-setup.ps1 -Demo
#>
[CmdletBinding()]
param(
    [string]$LaragonRoot = "C:\laragon",
    [switch]$Demo,
    [switch]$NoDemo,
    [switch]$SkipBuild
)

$ErrorActionPreference = "Stop"

$RepoRoot = (Resolve-Path (Join-Path $PSScriptRoot "..")).Path
Set-Location $RepoRoot

$script:Warnings = @()

function Write-Step([string]$Message) {
    Write-Host ""
    Write-Host "==> $Message" -ForegroundColor Cyan
}

function Write-Ok([string]$Message) {
    Write-Host "    OK   $Message" -ForegroundColor Green
}

function Write-Warn([string]$Message) {
    $script:Warnings += $Message
    Write-Host "    WARN $Message" -ForegroundColor Yellow
}

function Invoke-Checked {
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

function Read-IniSections([string]$Path) {
    $sections = @{}
    $current = $null
    foreach ($line in [System.IO.File]::ReadAllLines($Path)) {
        $trimmed = $line.Trim()
        if ($trimmed -match '^\[(?<name>[^\]]+)\]$') {
            $current = $Matches['name']
            $sections[$current] = @{}
        } elseif ($current -and $trimmed -match '^(?<key>[^=;#]+)=(?<value>.*)$') {
            $sections[$current][$Matches['key'].Trim()] = $Matches['value'].Trim()
        }
    }
    return $sections
}

function Read-EnvValue([string]$Content, [string]$Key) {
    $match = [regex]::Match($Content, "(?m)^$([regex]::Escape($Key))=(?<value>.*)$")
    if (-not $match.Success) {
        return ""
    }
    return $match.Groups['value'].Value.Trim().Trim('"').Trim("'")
}

function Get-MajorMinor([string]$Text) {
    $match = [regex]::Match($Text, '(?<major>\d+)\.(?<minor>\d+)')
    if (-not $match.Success) {
        return $null
    }
    return "$($match.Groups['major'].Value).$($match.Groups['minor'].Value)"
}

# 1. Paths -------------------------------------------------------------------
Write-Step "Checking paths"

foreach ($required in @("artisan", "composer.json", "package.json", ".env.example", "docs\laragon\profile\myapes-account.ini")) {
    if (-not (Test-Path -LiteralPath (Join-Path $RepoRoot $required))) {
        throw "Required file is missing: $required. Run this script from a MyAPES Account checkout."
    }
}

if (-not (Test-Path -LiteralPath (Join-Path $LaragonRoot "laragon.exe"))) {
    throw "Laragon was not found at $LaragonRoot. Install Laragon 8 (Full) or pass -LaragonRoot."
}
Write-Ok "Laragon found at $LaragonRoot"

$projectFolder = Join-Path $LaragonRoot "www\myapes-account"
if (Test-Path -LiteralPath $projectFolder) {
    $projectItem = Get-Item -LiteralPath $projectFolder -Force
    $projectTarget = if ($projectItem.LinkType -eq "Junction" -or $projectItem.LinkType -eq "SymbolicLink") {
        [string]($projectItem.Target | Select-Object -First 1)
    } else {
        $projectItem.FullName
    }
    if ((Resolve-Path -LiteralPath $projectTarget).Path.TrimEnd('\') -ieq $RepoRoot.TrimEnd('\')) {
        Write-Ok "Laragon project folder $projectFolder points at this checkout"
    } else {
        Write-Warn "$projectFolder exists but is a different folder ($projectTarget). Laragon will serve that copy, not this one."
    }
} else {
    Write-Warn "$projectFolder does not exist. Clone the repository there (see docs/laragon.md) so Laragon serves it at http://myapes-account.test."
}

# 2. Profile and versions ----------------------------------------------------
Write-Step "Checking Laragon versions against docs/laragon/profile.md"

$profileIni = Read-IniSections (Join-Path $RepoRoot "docs\laragon\profile\myapes-account.ini")
$expectedPhpFolder = $profileIni['php']['Version']
$expectedMysqlFolder = $profileIni['mysql']['Version']
$expectedNodeFolder = $profileIni['nodejs']['Version']
$expectedPhp = Get-MajorMinor $expectedPhpFolder
$expectedMysql = Get-MajorMinor $expectedMysqlFolder
$expectedNodeMajor = ([regex]::Match($expectedNodeFolder, '\d+')).Value
$minimumNpm = [version]"11.10.0"

$phpDir = Join-Path $LaragonRoot "bin\php\$expectedPhpFolder"
if (-not (Test-Path -LiteralPath (Join-Path $phpDir "php.exe"))) {
    $phpDir = Get-ChildItem -LiteralPath (Join-Path $LaragonRoot "bin\php") -Directory -ErrorAction SilentlyContinue |
        Where-Object { $_.Name -like "php-$expectedPhp.*" -and $_.Name -like "*nts*" } |
        Sort-Object Name -Descending |
        Select-Object -First 1 -ExpandProperty FullName
    if (-not $phpDir) {
        throw "Laragon has no NTS PHP $expectedPhp build. Add one with Menu > PHP > Version > Get more (the NTS x64 build), then run this script again."
    }
    Write-Warn "Profile PHP folder $expectedPhpFolder is not installed; using $(Split-Path $phpDir -Leaf) instead."
}

$mysqlBin = Join-Path $LaragonRoot "bin\mysql\$expectedMysqlFolder\bin"
if (-not (Test-Path -LiteralPath (Join-Path $mysqlBin "mysql.exe"))) {
    $mysqlBin = Get-ChildItem -LiteralPath (Join-Path $LaragonRoot "bin\mysql") -Directory -ErrorAction SilentlyContinue |
        Where-Object { $_.Name -like "mysql-$expectedMysql.*" } |
        Sort-Object Name -Descending |
        Select-Object -First 1 |
        ForEach-Object { Join-Path $_.FullName "bin" }
    if (-not $mysqlBin) {
        throw "Laragon has no MySQL $expectedMysql. Add it with Menu > Tools > Quick add, then run this script again."
    }
}

$nodeDir = Join-Path $LaragonRoot "bin\nodejs\$expectedNodeFolder"
$composerDir = Join-Path $LaragonRoot "bin\composer"

$pathPrefix = @($phpDir, $mysqlBin, $nodeDir, $composerDir) | Where-Object { $_ -and (Test-Path -LiteralPath $_) }
$env:PATH = (($pathPrefix + @($env:PATH)) -join ";")

$phpVersion = (& php -r "echo PHP_VERSION;").Trim()
$phpThreadSafe = (& php -r "echo PHP_ZTS ? 'yes' : 'no';").Trim()
if ((Get-MajorMinor $phpVersion) -ne $expectedPhp) {
    throw "PHP on the command line is $phpVersion; this project needs PHP $expectedPhp."
}
Write-Ok "PHP $phpVersion (thread-safe: $phpThreadSafe) from $phpDir"

# Laragon runs an NTS PHP through FastCGI (mod_fcgid, etc\apache2\fcgid.conf), with separate
# PHP processes per site, so another project's .env cannot leak into this one.
$httpdConf = Get-ChildItem -LiteralPath (Join-Path $LaragonRoot "bin\apache") -Directory -ErrorAction SilentlyContinue |
    Sort-Object Name -Descending | Select-Object -First 1 | ForEach-Object { Join-Path $_.FullName "conf\httpd.conf" }
$handler = if ($httpdConf -and (Test-Path -LiteralPath $httpdConf)) {
    [regex]::Match([System.IO.File]::ReadAllText($httpdConf), '(?m)^\s*Include\s+"?[^"\r\n]*[\\/]etc[\\/]apache2[\\/](?<handler>mod_php|fcgid)\.conf').Groups['handler'].Value
}
$apachePhp = ''
if ($handler -eq 'fcgid') {
    $fcgidConf = Join-Path $LaragonRoot "etc\apache2\fcgid.conf"
    $apachePhp = [regex]::Match([System.IO.File]::ReadAllText($fcgidConf), 'FcgidWrapper\s+"[^"]*bin/php/(?<folder>[^/"]+)/php-cgi\.exe"').Groups['folder'].Value
}
if ($handler -ne 'fcgid') {
    Write-Host ""
    Write-Host "    ********************************************************************" -ForegroundColor Red
    Write-Host "    Laragon's Apache runs PHP through $(if ($handler) { $handler } else { 'an unknown handler' }), not FastCGI." -ForegroundColor Red
    Write-Host "    Choose the NTS build with Menu > PHP > Version > $(Split-Path $phpDir -Leaf), then Reload Apache." -ForegroundColor Red
    Write-Host "    ********************************************************************" -ForegroundColor Red
    $script:Warnings += "Laragon's Apache does not run PHP through FastCGI."
} elseif ($apachePhp -ne (Split-Path $phpDir -Leaf)) {
    Write-Warn "Laragon's Apache runs $apachePhp through FastCGI, but the command line uses $(Split-Path $phpDir -Leaf). Choose Menu > PHP > Version > $(Split-Path $phpDir -Leaf)."
    $script:Warnings += "Laragon's Apache uses $apachePhp instead of $(Split-Path $phpDir -Leaf)."
} else {
    Write-Ok "Laragon's Apache runs $apachePhp through FastCGI, the same build as the command line"
}

$opcache = (& php -r "echo extension_loaded('Zend OPcache') ? ini_get('opcache.enable').'/'.ini_get('opcache.validate_timestamps') : 'off';").Trim()
if ($opcache -ne '1/1') {
    Write-Warn "OPcache is not on with validate_timestamps=1 in $(Split-Path $phpDir -Leaf)\php.ini. For faster pages, turn it on in Menu > PHP > php.ini."
}

$missingExtensions = @()
$loadedExtensions = (& php -m) | ForEach-Object { $_.Trim().ToLowerInvariant() }
foreach ($extension in @("bcmath", "curl", "fileinfo", "gd", "iconv", "intl", "mbstring", "openssl", "pdo_mysql", "pdo_sqlite", "sodium", "zip")) {
    if ($loadedExtensions -notcontains $extension) {
        $missingExtensions += $extension
    }
}
if ($missingExtensions.Count -gt 0) {
    throw "PHP is missing these extensions: $($missingExtensions -join ', '). Turn them on with Menu > PHP > Extensions."
}
Write-Ok "PHP extensions present"

if (-not (Get-Command composer -ErrorAction SilentlyContinue)) {
    throw "Composer was not found. Laragon Full includes it at $composerDir."
}
$ErrorActionPreference = "Continue"
$composerVersion = ((& composer --version 2>$null) | Select-Object -First 1)
$ErrorActionPreference = "Stop"
Write-Ok "$composerVersion"

if (-not (Get-Command node -ErrorAction SilentlyContinue)) {
    throw "Node was not found. Pick Node $expectedNodeMajor in Menu > Node.js > Version."
}
$nodeVersion = (& node -v).Trim()
if (([regex]::Match($nodeVersion, '\d+')).Value -ne $expectedNodeMajor) {
    Write-Warn "Node is $nodeVersion; this project expects Node $expectedNodeMajor (Laragon $expectedNodeFolder)."
} else {
    Write-Ok "Node $nodeVersion"
}

$npmVersion = [version]((& npm -v).Trim())
if ($npmVersion -lt $minimumNpm) {
    throw "npm is $npmVersion; this project needs npm $minimumNpm or newer (the repo's .npmrc uses min-release-age). Run 'npm install -g npm@11' in a Laragon terminal, then run this script again."
}
Write-Ok "npm $npmVersion"

# 3. Environment -------------------------------------------------------------
Write-Step "Checking .env"

$envPath = Join-Path $RepoRoot ".env"
if (-not (Test-Path -LiteralPath $envPath)) {
    Copy-Item -LiteralPath (Join-Path $RepoRoot ".env.example") -Destination $envPath
    Write-Ok "Created .env from .env.example"
} else {
    Write-Ok ".env already exists; leaving its values as they are"
}

$exampleContent = [System.IO.File]::ReadAllText((Join-Path $RepoRoot ".env.example"))
$envContent = [System.IO.File]::ReadAllText($envPath)

$appEnvironment = Read-EnvValue $envContent "APP_ENV"
if ($appEnvironment -ne "local") {
    throw "Refusing to continue: APP_ENV in .env is '$appEnvironment'. Laragon setup only runs with APP_ENV=local."
}

foreach ($key in @("APP_URL", "DB_CONNECTION", "DB_HOST", "DB_PORT", "DB_DATABASE", "DB_USERNAME", "VITE_PORT")) {
    $expected = Read-EnvValue $exampleContent $key
    $actual = Read-EnvValue $envContent $key
    if ($actual -ne $expected) {
        Write-Warn ".env has $key=$actual; the Laragon profile expects $key=$expected."
    }
}

$dbConnection = Read-EnvValue $envContent "DB_CONNECTION"
$dbHost = Read-EnvValue $envContent "DB_HOST"
$dbPort = Read-EnvValue $envContent "DB_PORT"
$dbName = Read-EnvValue $envContent "DB_DATABASE"
$dbUser = Read-EnvValue $envContent "DB_USERNAME"
$dbPassword = Read-EnvValue $envContent "DB_PASSWORD"
$appUrl = (Read-EnvValue $envContent "APP_URL").TrimEnd("/")

if ($dbConnection -ne "mysql") {
    throw "DB_CONNECTION in .env is '$dbConnection'. The Laragon setup uses Laragon's MySQL (DB_CONNECTION=mysql)."
}
if ($dbName -notmatch '^[A-Za-z0-9_]+_local$') {
    throw "DB_DATABASE in .env is '$dbName'. It must be this project's own database ending in _local (expected $(Read-EnvValue $exampleContent 'DB_DATABASE'))."
}

# 4. Dependencies ------------------------------------------------------------
Write-Step "Installing dependencies"

Invoke-Checked composer install --no-interaction --prefer-dist
# npm ci never rewrites package-lock.json; skip it while node_modules is newer than the lock file.
$installedLock = Join-Path $RepoRoot "node_modules\.package-lock.json"
$lockFile = Join-Path $RepoRoot "package-lock.json"
if ((Test-Path -LiteralPath $installedLock) -and
    ((Get-Item -LiteralPath $installedLock).LastWriteTimeUtc -ge (Get-Item -LiteralPath $lockFile).LastWriteTimeUtc)) {
    Write-Ok "node_modules matches package-lock.json"
} else {
    Invoke-Checked npm ci --no-audit --no-fund
}

if ([string]::IsNullOrWhiteSpace((Read-EnvValue ([System.IO.File]::ReadAllText($envPath)) "APP_KEY"))) {
    Invoke-Checked php artisan key:generate --force
} else {
    Write-Ok "APP_KEY already set"
}

# 5. Database ----------------------------------------------------------------
Write-Step "Creating database $dbName (if missing)"

$mysqlArgs = @("--host=$dbHost", "--port=$dbPort", "--user=$dbUser", "--protocol=TCP")
$previousMysqlPassword = $env:MYSQL_PWD
if ($dbPassword) {
    $env:MYSQL_PWD = $dbPassword
} else {
    Remove-Item Env:\MYSQL_PWD -ErrorAction SilentlyContinue
}
try {
    $serverVersion = (& mysql @mysqlArgs --batch --skip-column-names -e "SELECT VERSION();")
    if ($LASTEXITCODE -ne 0) {
        throw "Could not connect to MySQL at ${dbHost}:${dbPort} as $dbUser. Start Laragon (Start All) and check that port 3306 is free."
    }
    $serverVersion = ([string]($serverVersion | Select-Object -First 1)).Trim()
    if ((Get-MajorMinor $serverVersion) -ne $expectedMysql) {
        Write-Warn "MySQL server is $serverVersion; this project expects MySQL $expectedMysql. Switch with Menu > MySQL > Version."
    } else {
        Write-Ok "MySQL $serverVersion"
    }

    & mysql @mysqlArgs -e "CREATE DATABASE IF NOT EXISTS ``$dbName`` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    if ($LASTEXITCODE -ne 0) {
        throw "Could not create database $dbName."
    }
    Write-Ok "Database $dbName is ready"
} finally {
    if ($null -ne $previousMysqlPassword) {
        $env:MYSQL_PWD = $previousMysqlPassword
    } else {
        Remove-Item Env:\MYSQL_PWD -ErrorAction SilentlyContinue
    }
}

Write-Step "Running migrations"
Invoke-Checked php artisan migrate --force --no-interaction

# Dot-sourcing selective-media-boundary.ps1 declares a script-level $RootDir param that
# would overwrite a caller variable of the same name; keep the repo path separate.
. (Join-Path $RepoRoot "scripts\local\selective-media-boundary.ps1")
Assert-SelectiveMediaBoundary -RootDir $RepoRoot -CreateAvatarLink

# 6. Demo data ---------------------------------------------------------------
$loadDemo = $false
if ($Demo) {
    $loadDemo = $true
} elseif (-not $NoDemo -and [Environment]::UserInteractive -and -not [Console]::IsInputRedirected) {
    $answer = Read-Host "Load the demo data now? This adds fake records to $dbName [y/N]"
    $loadDemo = $answer -match '^(y|yes)$'
}

if ($loadDemo) {
    Write-Step "Loading demo data"
    Invoke-Checked php artisan myapes:demo-data
} else {
    Write-Host ""
    Write-Host "    Skipped demo data. Load it any time with: composer demo:seed"
}

# 7. Build and check ---------------------------------------------------------
if (-not $SkipBuild) {
    Write-Step "Building frontend assets"
    Invoke-Checked npm run build
}

Write-Step "Checking $appUrl"
try {
    $response = Invoke-WebRequest -Uri "$appUrl/healthz" -UseBasicParsing -TimeoutSec 15
    Write-Ok "$appUrl/healthz returned HTTP $($response.StatusCode)"
} catch {
    Write-Warn "Could not load $appUrl/healthz ($($_.Exception.Message)). Make sure Laragon is started and Auto Virtual Hosts has created myapes-account.test (Menu > Apache > Reload)."
}

Write-Host ""
if ($script:Warnings.Count -gt 0) {
    Write-Host "Setup finished with $($script:Warnings.Count) warning(s):" -ForegroundColor Yellow
    foreach ($warning in $script:Warnings) {
        Write-Host "  - $warning" -ForegroundColor Yellow
    }
} else {
    Write-Host "Setup finished with no warnings." -ForegroundColor Green
}
Write-Host "Run scripts\preview.ps1 to start hot reload with no window, then open $appUrl."
