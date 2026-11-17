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

    Idempotent: re-sourcing strips previous Herd prefixes from this session's
    PATH before prepending once. Blind prepends used to grow PATH past the
    Windows limit (~8191), which made `herd` / `php` vanish mid-session and
    broke local tooling even while nginx still served the site.

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

$prefix = [System.Collections.Generic.List[string]]::new()
$prefix.Add($phpDir)
$prefix.Add($herdBin)
$prefix.Add($nodeDir.FullName)
if ($mysqlDir) {
    $prefix.Add((Join-Path $mysqlDir.FullName 'bin'))
}

# Drop earlier Herd prefixes / duplicates so this script is safe to re-run.
$managed = {
    param([string]$Entry)
    if ([string]::IsNullOrWhiteSpace($Entry)) { return $true }
    $n = $Entry.TrimEnd('\')
    foreach ($p in $prefix) {
        if ($n.Equals($p.TrimEnd('\'), [StringComparison]::OrdinalIgnoreCase)) { return $true }
    }
    if ($n -match '(?i)[\\/]\.config[\\/]herd[\\/]bin$') { return $true }
    if ($n -match '(?i)[\\/]\.config[\\/]herd[\\/]bin[\\/]php\d+$') { return $true }
    if ($n -match '(?i)[\\/]\.config[\\/]herd[\\/]bin[\\/]nvm[\\/]') { return $true }
    if ($n -match '(?i)[\\/]\.config[\\/]herd[\\/]bin[\\/]services[\\/]mysql[\\/]') { return $true }
    return $false
}

$seen = [System.Collections.Generic.HashSet[string]]::new([StringComparer]::OrdinalIgnoreCase)
$rest = foreach ($entry in ($env:Path -split ';')) {
    if (& $managed $entry) { continue }
    if ($seen.Add($entry)) { $entry }
}

$env:Path = (@($prefix) + @($rest)) -join ';'
$env:XDEBUG_MODE = 'off'

if ($env:Path.Length -gt 6000) {
    Write-Warning "PATH is $($env:Path.Length) characters after herd-env. Windows can truncate near 8191 and drop System32/herd. Open a fresh shell if tools go missing."
}

Write-Host "Herd PHP $($herdProfile.php) ($phpDir), Node $($nodeDir.Name), Composer$(if ($mysqlDir) { " and MySQL $($mysqlDir.Name)" }) are first on PATH; XDEBUG_MODE=off."
