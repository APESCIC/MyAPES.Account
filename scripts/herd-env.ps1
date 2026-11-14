<#
.SYNOPSIS
    Put Herd's PHP, Composer, MySQL client and Node for MyAPES Account first on
    PATH for this PowerShell session.

.DESCRIPTION
    Reads docs/herd/profile.json and picks Herd's PHP build for the profile's
    version (8.4), Herd's MySQL client and the newest Node of the profile's major
    version (22) from Herd's nvm, so the command line uses the same PHP and
    php.ini as the site. Herd's global Node default may be another major
    version; this only changes the current session. Also sets XDEBUG_MODE=off.
    Changes nothing on disk.

    Dot-source it, so the settings stay in your terminal:

        . .\scripts\herd-env.ps1

.PARAMETER HerdRoot
    Herd's folder. Defaults to %USERPROFILE%\.config\herd.
#>
[CmdletBinding()]
param(
    [string]$HerdRoot = (Join-Path $env:USERPROFILE '.config\herd')
)

$herdProfile = Get-Content (Join-Path (Split-Path $PSScriptRoot -Parent) 'docs\herd\profile.json') -Raw | ConvertFrom-Json
$herdBin = Join-Path $HerdRoot 'bin'
$phpDir = Join-Path $herdBin "php$($herdProfile.php -replace '\.', '')"
$nodeDir = Get-ChildItem (Join-Path $herdBin 'nvm') -Directory -ErrorAction SilentlyContinue |
    Where-Object { $_.Name -match "^v$($herdProfile.node)\.\d+\.\d+$" } |
    Sort-Object { [version]($_.Name.TrimStart('v')) } -Descending |
    Select-Object -First 1
$mysqlDir = Get-ChildItem (Join-Path $herdBin 'services\mysql') -Directory -ErrorAction SilentlyContinue |
    Where-Object { $_.Name -like "$($herdProfile.database.version).*" } |
    Sort-Object { [version]$_.Name } -Descending |
    Select-Object -First 1

if (-not (Test-Path (Join-Path $phpDir 'php.exe'))) {
    Write-Warning "Herd has no PHP $($herdProfile.php) in $phpDir. Install it with: herd php:install $($herdProfile.php)"
    return
}
if (-not $nodeDir) {
    Write-Warning "Herd's nvm has no Node $($herdProfile.node). Turn on Node.js in Herd > Settings, then run: herd node:install $($herdProfile.node)"
    return
}

$prefix = @($phpDir, $herdBin, $nodeDir.FullName)
if ($mysqlDir) { $prefix += Join-Path $mysqlDir.FullName 'bin' }
$env:Path = ($prefix -join ';') + ';' + $env:Path
$env:XDEBUG_MODE = 'off'
Write-Host "Herd PHP $($herdProfile.php) ($phpDir), Node $($nodeDir.Name), Composer$(if ($mysqlDir) { " and MySQL $($mysqlDir.Name)" }) are first on PATH; XDEBUG_MODE=off."
