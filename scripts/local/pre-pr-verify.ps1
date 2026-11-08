param(
    [switch]$Fresh,
    [switch]$Docker
)

$ErrorActionPreference = 'Stop'
Set-Location (Split-Path -Parent (Split-Path -Parent $PSScriptRoot))

Write-Host 'Running pre-merge contract gate...'
composer pre-merge
if ($LASTEXITCODE -ne 0) {
    exit $LASTEXITCODE
}

$version = (Get-Content VERSION -Raw).Trim()

if ($Docker) {
    $previewArgs = @()
    if ($Fresh) {
        $previewArgs += '-Fresh'
    } else {
        $previewArgs += '-Migrate'
    }

    Write-Host 'Starting or reusing the Docker preview...'
    & (Join-Path $PSScriptRoot 'docker-preview.ps1') @previewArgs

    $baseUrl = 'http://myaccount.myapes.test:8081'
    foreach ($path in @('/healthz', '/change-log')) {
        $response = Invoke-WebRequest -Uri "http://127.0.0.1:8081$path" -UseBasicParsing -TimeoutSec 15
        if ($response.StatusCode -ne 200) {
            throw "Smoke check failed: $path returned $($response.StatusCode)."
        }
    }
    if ((Invoke-WebRequest -Uri 'http://127.0.0.1:8081/healthz' -UseBasicParsing).Content -notmatch [regex]::Escape($version)) {
        throw "Smoke check failed: /healthz does not report version $version."
    }

    Write-Host ''
    Write-Host 'Pre-PR local verify checks passed.'
    Write-Host "VERSION: v$version"
    Write-Host "Smoke healthz: $baseUrl/healthz (ok)"
    Write-Host "Smoke change-log: $baseUrl/change-log (ok)"
    Write-Host ''
    Write-Host 'Spot-check changed routes in the browser before commit/PR.'
    exit 0
}

$bootstrapArgs = @()
if ($Fresh) {
    $bootstrapArgs += '-Fresh'
} else {
    $bootstrapArgs += '-Seed'
}

Write-Host 'Running local bootstrap...'
& (Join-Path $PSScriptRoot 'bootstrap.ps1') @bootstrapArgs
if ($LASTEXITCODE -ne 0) {
    exit $LASTEXITCODE
}

$baseUrl = 'http://127.0.0.1:8000'

Write-Host ''
Write-Host 'Pre-PR local verify checks passed.'
Write-Host "VERSION: v$version"
Write-Host "Smoke healthz: $baseUrl/healthz"
Write-Host "Smoke change-log: $baseUrl/change-log"
Write-Host ''
Write-Host 'Start the stack if needed: composer run dev'
Write-Host 'Then spot-check changed routes in the browser before commit/PR.'
