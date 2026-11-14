<#
.SYNOPSIS
    Sets up MyAPES Account as its own Laravel Herd site. Safe to run more than once.

.DESCRIPTION
    Reads docs/herd/profile.json, then:
      1. checks Herd is running, finds its PHP, Composer, MySQL client and Node
         that match the profile, and puts them first on PATH for this session;
      2. checks the PHP extensions, OPcache and npm's version;
      3. checks Herd's MySQL service runs the profile's version on its port;
      4. links the site (herd link), pins it to the profile's PHP version
         (herd isolate) and secures it for HTTPS (herd secure), unless that is
         already done;
      5. creates .env from .env.example when it is missing and compares it with
         the profile;
      6. installs dependencies and generates APP_KEY when it is blank;
      7. creates this project's database (its name must end in _local), runs the
         migrations and checks the selective media boundary;
      8. offers to load the fake demo data, builds the assets and checks /healthz.

    It only touches this project's site link, .env and database. It never
    changes Herd's settings, other sites or their databases.

.PARAMETER Demo
    Load the demo data without asking.

.PARAMETER NoDemo
    Skip the demo data without asking.

.PARAMETER SkipBuild
    Skip npm run build.

.PARAMETER HerdRoot
    Herd's folder. Defaults to %USERPROFILE%\.config\herd.

.EXAMPLE
    powershell -NoProfile -ExecutionPolicy Bypass -File scripts\herd-setup.ps1

.EXAMPLE
    powershell -NoProfile -ExecutionPolicy Bypass -File scripts\herd-setup.ps1 -Demo
#>
[CmdletBinding()]
param(
    [switch]$Demo,
    [switch]$NoDemo,
    [switch]$SkipBuild,
    [string]$HerdRoot = (Join-Path $env:USERPROFILE '.config\herd')
)

$ErrorActionPreference = 'Stop'
$script:Warnings = @()

function Write-Step([string]$Message) { Write-Host ''; Write-Host "==> $Message" -ForegroundColor Cyan }
function Write-Ok([string]$Message) { Write-Host "    OK   $Message" -ForegroundColor Green }
function Write-Warn([string]$Message) { $script:Warnings += $Message; Write-Host "    WARN $Message" -ForegroundColor Yellow }

# Windows PowerShell turns a native tool's stderr into errors, so run tools
# with 'Continue' and judge them by their exit code.
function Invoke-Checked([string]$What, [scriptblock]$Command) {
    $previous = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try { & $Command } finally { $ErrorActionPreference = $previous }
    if ($LASTEXITCODE -ne 0) { throw "$What failed (exit $LASTEXITCODE)." }
}

function Get-NativeOutput([scriptblock]$Command) {
    $previous = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try { $output = & $Command 2>&1 } finally { $ErrorActionPreference = $previous }
    return ($output | Out-String).Trim()
}

function Get-EnvValue([string]$Content, [string]$Key) {
    $match = [regex]::Match($Content, "(?m)^$([regex]::Escape($Key))=(?<value>.*)$")
    if (-not $match.Success) { return '' }
    return $match.Groups['value'].Value.Trim().Trim('"').Trim("'")
}

if ($Demo -and $NoDemo) { throw 'Use -Demo or -NoDemo, not both.' }

$repo = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
foreach ($required in @('artisan', 'composer.json', 'package.json', '.env.example', 'docs\herd\profile.json')) {
    if (-not (Test-Path -LiteralPath (Join-Path $repo $required))) {
        throw "Required file is missing: $required. Run this script from a MyAPES Account checkout."
    }
}
$p = Get-Content (Join-Path $repo 'docs\herd\profile.json') -Raw | ConvertFrom-Json

Write-Host "MyAPES Account Herd set-up ($($p.appUrl))"

# 1. Herd tools ----------------------------------------------------------------
Write-Step 'Checking Herd'
$herdBin = Join-Path $HerdRoot 'bin'
if (-not (Test-Path (Join-Path $herdBin 'herd.bat'))) {
    throw "Laravel Herd was not found at $HerdRoot. Install Herd for Windows, or pass -HerdRoot."
}
$notRunning = @('Herd', 'nginx' | Where-Object { -not (Get-Process $_ -ErrorAction SilentlyContinue) })
if ($notRunning.Count -gt 0) { throw "Not running: $($notRunning -join ', '). Open Herd, then run this again." }

$phpDir = Join-Path $herdBin "php$($p.php -replace '\.', '')"
if (-not (Test-Path (Join-Path $phpDir 'php.exe'))) { throw "Herd has no PHP $($p.php). Install it with: herd php:install $($p.php)" }

$composerPhar = Join-Path $herdBin 'composer.phar'
if (-not (Test-Path $composerPhar)) { throw "Composer was not found at $composerPhar." }

$nodeDir = Get-ChildItem (Join-Path $herdBin 'nvm') -Directory -ErrorAction SilentlyContinue |
    Where-Object { $_.Name -match "^v$($p.node)\.\d+\.\d+$" } |
    Sort-Object { [version]($_.Name.TrimStart('v')) } -Descending |
    Select-Object -First 1
if (-not $nodeDir) { throw "Herd's nvm has no Node $($p.node). Turn on Node.js in Herd > Settings, then run: herd node:install $($p.node)" }

$env:Path = "$phpDir;$herdBin;$($nodeDir.FullName);" + $env:Path
if (-not $env:XDEBUG_MODE) { $env:XDEBUG_MODE = 'off' }

$phpVersion = "$(php -r 'echo PHP_VERSION;')".Trim()
$nodeVersion = "$(node -v)".Trim()
if ($phpVersion -notlike "$($p.php).*") { throw "PHP on PATH is $phpVersion, not $($p.php)." }
if ($nodeVersion -notlike "v$($p.node).*") { throw "Node on PATH is $nodeVersion, not $($p.node)." }
Write-Ok "PHP $phpVersion ($phpDir)"
Write-Ok "Node $nodeVersion ($($nodeDir.FullName))"

# 2. PHP and npm ---------------------------------------------------------------
$loaded = "$(php -r "echo implode(',', array_map('strtolower', get_loaded_extensions()));")".Split(',')
$missing = @($p.phpExtensions | Where-Object { $loaded -notcontains $_ })
if ($missing.Count -gt 0) {
    throw "Herd's PHP $phpVersion is missing these extensions: $($missing -join ', '). Open its php.ini with 'herd ini $($p.php)', remove the ; before extension=<name>, run 'herd restart php', then run this again."
}
Write-Ok "PHP extensions present"

$opcache = "$(php -r "echo extension_loaded('Zend OPcache') ? ini_get('opcache.enable').'/'.ini_get('opcache.validate_timestamps') : 'off';")".Trim()
if ($opcache -ne '1/1') {
    Write-Warn "OPcache is not on with validate_timestamps=1. For faster pages, set opcache.enable=1 and opcache.validate_timestamps=1 with 'herd ini $($p.php)'."
}

$npmVersion = [version]"$(npm -v)".Trim()
if ($npmVersion -lt [version]$p.npmMinimum) {
    throw "npm is $npmVersion; this project needs npm $($p.npmMinimum) or newer (the repo's .npmrc uses min-release-age). With Herd's Node $($p.node) first on PATH (. .\scripts\herd-env.ps1), run 'npm install -g npm@11', then run this again."
}
Write-Ok "npm $npmVersion"

# 3. MySQL service -------------------------------------------------------------
Write-Step "Herd MySQL $($p.database.version) on port $($p.database.port)"
$services = Get-NativeOutput { herd services:list }
$mysqlRow = $services -split "`r?`n" | Where-Object { $_ -match '\|\s*mysql\s*\|' -and $_ -match "\|\s*$($p.database.port)\s*\|" } | Select-Object -First 1
if (-not $mysqlRow) {
    throw "Herd has no MySQL service on port $($p.database.port). Create one in Herd > Services (MySQL $($p.database.version), port $($p.database.port))."
}
if ($mysqlRow -notmatch "\|\s*$([regex]::Escape($p.database.version))\.\d+\s*\|") { Write-Warn "The MySQL service on port $($p.database.port) is not version $($p.database.version): $($mysqlRow.Trim())" }
if (-not (Get-NetTCPConnection -State Listen -LocalPort $p.database.port -ErrorAction SilentlyContinue)) {
    throw "Herd's MySQL service on port $($p.database.port) is not running. Start it in Herd > Services, then run this again."
}
$mysqlDir = Get-ChildItem (Join-Path $herdBin 'services\mysql') -Directory -ErrorAction SilentlyContinue |
    Where-Object { $_.Name -like "$($p.database.version).*" } |
    Sort-Object { [version]$_.Name } -Descending |
    Select-Object -First 1
if (-not $mysqlDir) { throw "Herd's MySQL $($p.database.version) tools were not found in $herdBin\services\mysql." }
$env:Path = "$($mysqlDir.FullName)\bin;" + $env:Path
Write-Ok "MySQL $($mysqlDir.Name) answers on port $($p.database.port)"

Push-Location $repo
try {
    # 4. Herd site ---------------------------------------------------------------
    Write-Step "Herd site $($p.hostname)"
    $siteLink = Join-Path $HerdRoot "config\valet\Sites\$($p.herdSite)"
    if (Test-Path $siteLink) {
        $target = "$((Get-Item $siteLink -Force).Target)".TrimEnd('\')
        if ($target -and $target -ine $repo.TrimEnd('\')) {
            throw "The Herd site $($p.herdSite) already points at $target, not this clone ($repo). Run 'herd unlink $($p.herdSite)' there first, then run this again."
        }
        Write-Ok 'Already linked to this clone'
    } else {
        Invoke-Checked 'herd link' { herd link $p.herdSite }
        Write-Ok "Linked $($p.hostname) to $repo"
    }
    if ($p.phpIsolated) {
        $sitePhp = Get-NativeOutput { herd which-php $p.herdSite }
        if ($sitePhp -match "php$($p.php -replace '\.', '')[\\/]") {
            Write-Ok "Pinned to PHP $($p.php)"
        } else {
            Invoke-Checked 'herd isolate' { herd isolate $p.php --site=$($p.herdSite) }
            Write-Ok "Pinned $($p.hostname) to PHP $($p.php)"
        }
    }
    if ($p.appUrl -like 'https://*') {
        if (Test-Path (Join-Path $HerdRoot "config\valet\Certificates\$($p.hostname).crt")) {
            Write-Ok "Served over HTTPS with Herd's certificate"
        } else {
            Invoke-Checked 'herd secure' { herd secure $p.herdSite }
            Write-Ok "Secured $($p.hostname) with Herd's certificate (HTTPS)"
        }
    }

    # 5. .env --------------------------------------------------------------------
    Write-Step 'Checking .env'
    $envPath = Join-Path $repo '.env'
    if (Test-Path -LiteralPath $envPath) {
        Write-Ok '.env already exists; leaving its values as they are'
    } else {
        Copy-Item -LiteralPath (Join-Path $repo '.env.example') -Destination $envPath
        Write-Ok 'Created .env from .env.example'
    }
    $envContent = [System.IO.File]::ReadAllText($envPath)

    $appEnvironment = Get-EnvValue $envContent 'APP_ENV'
    if ($appEnvironment -ne 'local') {
        throw "Refusing to continue: APP_ENV in .env is '$appEnvironment'. Herd setup only runs with APP_ENV=local."
    }
    $expected = [ordered]@{
        APP_URL       = $p.appUrl
        DB_CONNECTION = $p.database.engine
        DB_HOST       = $p.database.host
        DB_PORT       = "$($p.database.port)"
        DB_DATABASE   = $p.database.name
        DB_USERNAME   = $p.database.username
        MAIL_HOST     = $p.mail.host
        MAIL_PORT     = "$($p.mail.port)"
        VITE_PORT     = "$($p.viteDevServerPort)"
    }
    foreach ($key in $expected.Keys) {
        $actual = Get-EnvValue $envContent $key
        if ($actual -ne $expected[$key]) { Write-Warn ".env has $key=$actual; the Herd profile expects $key=$($expected[$key])." }
    }

    $dbName = Get-EnvValue $envContent 'DB_DATABASE'
    $dbHost = Get-EnvValue $envContent 'DB_HOST'
    $dbPort = Get-EnvValue $envContent 'DB_PORT'
    $dbUser = Get-EnvValue $envContent 'DB_USERNAME'
    if ((Get-EnvValue $envContent 'DB_CONNECTION') -ne 'mysql') {
        throw "DB_CONNECTION in .env is '$(Get-EnvValue $envContent 'DB_CONNECTION')'. The Herd setup uses Herd's MySQL (DB_CONNECTION=mysql)."
    }
    if ($dbName -notmatch '^[A-Za-z0-9_]+_local$') {
        throw "DB_DATABASE in .env is '$dbName'. It must be this project's own database ending in _local (expected $($p.database.name))."
    }
    if (Get-EnvValue $envContent 'DB_PASSWORD') {
        throw "DB_PASSWORD is set in .env. Herd's MySQL uses root with no password; the script only creates databases that way."
    }

    # 6. Dependencies ------------------------------------------------------------
    Write-Step 'Installing dependencies'
    Invoke-Checked 'composer install' { php $composerPhar install --no-interaction --prefer-dist --no-progress }
    # npm ci never rewrites package-lock.json; skip it while node_modules is newer than the lock file.
    $installedLock = Join-Path $repo 'node_modules\.package-lock.json'
    $lockFile = Join-Path $repo 'package-lock.json'
    if ((Test-Path -LiteralPath $installedLock) -and
        ((Get-Item -LiteralPath $installedLock).LastWriteTimeUtc -ge (Get-Item -LiteralPath $lockFile).LastWriteTimeUtc)) {
        Write-Ok 'node_modules matches package-lock.json'
    } else {
        Invoke-Checked 'npm ci' { npm ci --no-audit --no-fund }
    }

    if (-not (Get-EnvValue ([System.IO.File]::ReadAllText($envPath)) 'APP_KEY')) {
        Invoke-Checked 'key:generate' { php artisan key:generate --force --no-interaction }
    } else {
        Write-Ok 'APP_KEY already set'
    }

    # 7. Database ----------------------------------------------------------------
    Write-Step "Creating database $dbName (if missing)"
    $mysqlArgs = @("--host=$dbHost", "--port=$dbPort", "--user=$dbUser", '--protocol=TCP')
    $serverVersion = Get-NativeOutput { mysql @mysqlArgs --batch --skip-column-names -e 'SELECT VERSION();' }
    if ($LASTEXITCODE -ne 0) { throw "Could not connect to MySQL at ${dbHost}:${dbPort} as $dbUser. Start Herd's MySQL service and check that port $dbPort is free." }
    Write-Ok "MySQL server $serverVersion"
    Invoke-Checked 'CREATE DATABASE' { mysql @mysqlArgs -e "CREATE DATABASE IF NOT EXISTS ``$dbName`` CHARACTER SET $($p.database.charset) COLLATE $($p.database.collation);" }
    Write-Ok "Database $dbName is ready"

    Write-Step 'Running migrations'
    Invoke-Checked 'migrate' { php artisan migrate --force --no-interaction }

    # Dot-sourcing selective-media-boundary.ps1 declares a script-level $RootDir param that
    # would overwrite a caller variable of the same name; keep the repo path separate.
    . (Join-Path $repo 'scripts\local\selective-media-boundary.ps1')
    Assert-SelectiveMediaBoundary -RootDir $repo -CreateAvatarLink

    # 8. Demo data, build and check ------------------------------------------------
    $loadDemo = $Demo.IsPresent
    if (-not $Demo -and -not $NoDemo -and [Environment]::UserInteractive -and -not [Console]::IsInputRedirected) {
        $answer = Read-Host "Load the demo data now? This adds fake records to $dbName [y/N]"
        $loadDemo = $answer -match '^(y|yes)$'
    }
    if ($loadDemo) {
        Write-Step 'Loading demo data'
        Invoke-Checked 'myapes:demo-data' { php artisan myapes:demo-data }
    } else {
        Write-Host ''
        Write-Host '    Skipped demo data. Load it any time with: composer demo:seed'
    }

    if (-not $SkipBuild) {
        Write-Step 'Building frontend assets'
        Invoke-Checked 'npm run build' { npm run build }
    }

    Write-Step "Checking $($p.appUrl)"
    try {
        $response = Invoke-WebRequest -Uri "$($p.appUrl)/healthz" -UseBasicParsing -TimeoutSec 15
        Write-Ok "$($p.appUrl)/healthz returned HTTP $($response.StatusCode)"
    } catch {
        Write-Warn "Could not load $($p.appUrl)/healthz ($($_.Exception.Message)). Check Herd is running and 'herd links' lists $($p.herdSite)."
    }
} finally {
    Pop-Location
}

Write-Host ''
if ($script:Warnings.Count -gt 0) {
    Write-Host "Setup finished with $($script:Warnings.Count) warning(s):" -ForegroundColor Yellow
    foreach ($warning in $script:Warnings) { Write-Host "  - $warning" -ForegroundColor Yellow }
} else {
    Write-Host 'Setup finished with no warnings.' -ForegroundColor Green
}
Write-Host "Run scripts\preview.ps1 to start hot reload with no window, then open $($p.appUrl)."
