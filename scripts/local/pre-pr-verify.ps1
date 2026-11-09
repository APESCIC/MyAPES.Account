param(
    [switch]$Fresh,
    [string]$AppUrl = "http://myapes-account.test"
)

$ErrorActionPreference = 'Stop'
Set-Location (Split-Path -Parent (Split-Path -Parent $PSScriptRoot))

Write-Host 'Running pre-merge contract gate...'
composer pre-merge
if ($LASTEXITCODE -ne 0) {
    exit $LASTEXITCODE
}

Write-Host 'Running Laragon setup...'
& (Join-Path $PSScriptRoot '..\laragon-setup.ps1') -NoDemo
if ($LASTEXITCODE -ne 0) {
    exit $LASTEXITCODE
}

if ($Fresh) {
    Write-Host 'Resetting the local database with demo data (composer demo:reset)...'
    composer demo:reset
    if ($LASTEXITCODE -ne 0) {
        exit $LASTEXITCODE
    }
}

$version = (Get-Content VERSION -Raw).Trim()
$baseUrl = $AppUrl.TrimEnd('/')

Write-Host ''
Write-Host 'Pre-PR local verify checks passed.'
Write-Host "VERSION: v$version"
Write-Host "Smoke healthz: $baseUrl/healthz"
Write-Host "Smoke change-log: $baseUrl/change-log"
Write-Host ''
Write-Host 'Start hot reload if needed: composer run dev'
Write-Host 'Then spot-check changed routes in the browser before commit/PR.'
