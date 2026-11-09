param(
    [switch]$Fresh,
    [switch]$Wamp,
    [string]$AppUrl = "http://myapes-account.test"
)

$ErrorActionPreference = 'Stop'
Set-Location (Split-Path -Parent (Split-Path -Parent $PSScriptRoot))

Write-Host 'Running pre-merge contract gate...'
composer pre-merge
if ($LASTEXITCODE -ne 0) {
    exit $LASTEXITCODE
}

$bootstrapArgs = @()
if ($Fresh) {
    $bootstrapArgs += '-Fresh'
} else {
    $bootstrapArgs += '-Seed'
}

if ($Wamp) {
    $bootstrapArgs += '-Wamp'
    $bootstrapArgs += '-AppUrl'
    $bootstrapArgs += $AppUrl.TrimEnd('/')
}

Write-Host 'Running local bootstrap...'
& (Join-Path $PSScriptRoot 'bootstrap.ps1') @bootstrapArgs
if ($LASTEXITCODE -ne 0) {
    exit $LASTEXITCODE
}

$version = (Get-Content VERSION -Raw).Trim()
$baseUrl = if ($Wamp) { $AppUrl.TrimEnd('/') } else { 'http://127.0.0.1:8000' }
$devCommand = if ($Wamp) { 'dev:wamp' } else { 'dev' }

Write-Host ''
Write-Host 'Pre-PR local verify checks passed.'
Write-Host "VERSION: v$version"
Write-Host "Smoke healthz: $baseUrl/healthz"
Write-Host "Smoke change-log: $baseUrl/change-log"
Write-Host ''
Write-Host "Start the stack if needed: composer run $devCommand"
Write-Host 'Then spot-check changed routes in the browser before commit/PR.'
