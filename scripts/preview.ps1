<#
.SYNOPSIS
    Live preview gate, steps 1 and 2: load the latest code, then check the
    Herd preview serves it. Run before any development work.

.DESCRIPTION
    1. Stops if tracked files have uncommitted changes. Fetches origin, fast-
       forwards the branch, and merges origin/main into it (a merge commit, never
       a rebase; a conflict is aborted and reported). Nothing is pushed.
    2. Runs composer install or npm ci when a lock file is newer than the
       installed dependencies, and reports migrations that have not run (it
       never runs them).
    3. Checks Herd and its MySQL service are running, and that /healthz shows
       the checked-out revision on the profile's PHP version through FastCGI.
    4. Starts Vite in the background with no window, its output in
       storage\logs\vite-dev.log, unless this repository's Vite already listens
       on its port. It never starts a second copy.

    Opening the page in Cursor's browser is the agent's step 3.

    -StopDevServer stops this repository's Vite (and removes public\hot) and
    does nothing else.

    Exit codes: 0 ready, 1 blocked (the message says why), 3 needs a decision.

.EXAMPLE
    powershell -NoProfile -ExecutionPolicy Bypass -File scripts\preview.ps1

.EXAMPLE
    powershell -NoProfile -ExecutionPolicy Bypass -File scripts\preview.ps1 -StopDevServer
#>
[CmdletBinding()]
param(
    [switch]$NoSync,
    [switch]$NoMergeMain,
    [switch]$NoDevServer,
    [switch]$StopDevServer,
    [string]$HerdRoot = (Join-Path $env:USERPROFILE '.config\herd')
)

$ErrorActionPreference = 'Stop'

function Write-Step([string]$Message) { Write-Host "==> $Message" -ForegroundColor Cyan }
function Write-Ok([string]$Message) { Write-Host "    $Message" -ForegroundColor Green }
function Write-Warn([string]$Message) { Write-Host "    WARNING: $Message" -ForegroundColor Yellow }
function Stop-Preview([int]$Code, [string]$Message) { Write-Host "    STOP: $Message" -ForegroundColor Red; exit $Code }

function Invoke-Native([scriptblock]$Command) {
    $previous = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try { $output = & $Command 2>&1 } finally { $ErrorActionPreference = $previous }
    return [pscustomobject]@{ Code = $LASTEXITCODE; Output = ($output | Out-String).Trim() }
}

function Get-Page([string]$Url) {
    try {
        return (Invoke-WebRequest $Url -UseBasicParsing -TimeoutSec 60).Content
    } catch {
        # A timeout or refused connection has no response to read.
        $response = $_.Exception.Response
        if ($null -eq $response) { return $null }
        return (New-Object IO.StreamReader $response.GetResponseStream()).ReadToEnd()
    }
}

$repo = Split-Path $PSScriptRoot -Parent
# This repository's Herd values (docs/herd/profile.md).
$p = Get-Content (Join-Path $repo 'docs\herd\profile.json') -Raw | ConvertFrom-Json
$devScript = 'node_modules\vite\bin\vite.js'
$devLog = 'storage\logs\vite-dev.log'

# This repository's Vite processes, started by this script or npm.
function Get-DevServer {
    $pattern = "(?i)$([regex]::Escape($repo.TrimEnd('\')))\\node_modules\\(\.bin\\+\.\.\\+)?vite\\bin\\vite\.js"
    return @(Get-CimInstance Win32_Process -Filter "Name='node.exe'" | Where-Object { $_.CommandLine -match $pattern })
}

function Get-DevListener {
    return Get-NetTCPConnection -State Listen -LocalPort $p.viteDevServerPort -ErrorAction SilentlyContinue | Select-Object -First 1
}

function Get-HotUrl {
    $hot = Join-Path $repo 'public\hot'
    if (Test-Path $hot) { return "$(Get-Content $hot -Raw)".Trim() }
    return $null
}

function Stop-DevServer {
    $all = @(Get-CimInstance Win32_Process)
    $ids = @()
    foreach ($vite in Get-DevServer) {
        $ids += $vite.ProcessId
        # Walk up through the cmd and npm launchers, stopping at the shell that started them.
        $parentId = $vite.ParentProcessId
        while ($parent = $all | Where-Object { $_.ProcessId -eq $parentId -and $_.Name -in 'cmd.exe', 'node.exe' -and $_.CommandLine -match '(?i)vite|npm(-cli\.js"?)? run dev' }) {
            $ids += $parent.ProcessId
            $parentId = $parent.ParentProcessId
        }
    }
    foreach ($id in $ids | Select-Object -Unique) { Invoke-Native { taskkill /PID $id /T /F } | Out-Null }
    return @($ids | Select-Object -Unique).Count
}

# Herd's PHP for the profile's version and Node of the profile's major version; Herd's global
# Node default may be another major version.
$herdBin = Join-Path $HerdRoot 'bin'
$phpDir = Join-Path $herdBin "php$($p.php -replace '\.', '')"
if (-not (Test-Path (Join-Path $phpDir 'php.exe'))) { Stop-Preview 1 "Herd has no PHP $($p.php) in $phpDir. Run scripts\herd-setup.ps1." }
$composerPhar = Join-Path $herdBin 'composer.phar'
$nodeDir = Get-ChildItem (Join-Path $herdBin 'nvm') -Directory -ErrorAction SilentlyContinue |
    Where-Object { $_.Name -match "^v$($p.node)\.\d+\.\d+$" } |
    Sort-Object { [version]($_.Name.TrimStart('v')) } -Descending |
    Select-Object -First 1
if (-not $nodeDir) { Stop-Preview 1 "Herd's nvm has no Node $($p.node). Turn on Node.js in Herd > Settings, then run: herd node:install $($p.node)" }
$env:Path = "$phpDir;$herdBin;$($nodeDir.FullName);" + $env:Path
if (-not $env:XDEBUG_MODE) { $env:XDEBUG_MODE = 'off' }

Push-Location $repo
try {
    if ($StopDevServer) {
        Write-Step "Stop the dev server on port $($p.viteDevServerPort)"
        $stopped = Stop-DevServer
        Start-Sleep -Milliseconds 500
        if (Get-DevListener) { Write-Warn "Port $($p.viteDevServerPort) is still in use by another program." }
        elseif (Test-Path 'public\hot') { Remove-Item 'public\hot' -Force; Write-Ok 'Removed public\hot, so the pages use the built assets' }
        Write-Ok $(if ($stopped -gt 0) { "Stopped $stopped processes" } else { 'It was not running' })
        exit 0
    }

    # 1. Latest code ------------------------------------------------------------
    Write-Step 'Latest code'
    $before = (git rev-parse HEAD).Trim()
    $branch = (git branch --show-current).Trim()
    if ($NoSync) {
        Write-Warn 'Skipped the sync (-NoSync)'
    } else {
        $dirty = @(git status --porcelain --untracked-files=no)
        if ($dirty.Count -gt 0) { Stop-Preview 3 "$($dirty.Count) tracked files have uncommitted changes. Commit them or decide what to do before syncing." }
        $fetch = Invoke-Native { git fetch origin --prune --tags }
        if ($fetch.Code -ne 0) { Stop-Preview 1 "git fetch failed: $($fetch.Output)" }
        $upstream = Invoke-Native { git rev-parse --abbrev-ref '@{u}' }
        if ($upstream.Code -eq 0) {
            $ff = Invoke-Native { git merge --ff-only '@{u}' }
            if ($ff.Code -ne 0) { Stop-Preview 3 "$branch and $($upstream.Output) have both moved on. Merge them (no rebase), then run this again." }
        }
        if ($branch -ne 'main' -and -not $NoMergeMain) {
            $behind = [int](git rev-list --count 'HEAD..origin/main').Trim()
            if ($behind -gt 0) {
                $merge = Invoke-Native { git merge --no-edit origin/main }
                if ($merge.Code -ne 0) {
                    Invoke-Native { git merge --abort } | Out-Null
                    Stop-Preview 3 "Merging origin/main into $branch conflicts. The merge was aborted; resolve it before starting work."
                }
                Write-Ok "Merged $behind commits from origin/main into $branch (not pushed)"
            }
        }
    }
    $head = (git rev-parse HEAD).Trim()
    $short = $head.Substring(0, 12)
    Write-Ok "$branch at $short"

    # 2. Dependencies and migrations --------------------------------------------
    Write-Step 'Dependencies'
    $installed = Join-Path $repo 'vendor\composer\installed.json'
    if (-not (Test-Path $installed) -or (Get-Item 'composer.lock').LastWriteTime -gt (Get-Item $installed).LastWriteTime) {
        $run = Invoke-Native { php $composerPhar install --no-interaction --no-progress }
        if ($run.Code -ne 0) { Stop-Preview 1 "composer install failed: $($run.Output)" }
        # Composer leaves installed.json alone when nothing changed; mark it current so the next run skips the install.
        (Get-Item $installed).LastWriteTime = Get-Date
        Write-Ok 'Ran composer install'
    } else { Write-Ok 'Composer dependencies are current' }
    $npmMarker = Join-Path $repo 'node_modules\.package-lock.json'
    if (-not (Test-Path $npmMarker) -or (Get-Item 'package-lock.json').LastWriteTime -gt (Get-Item $npmMarker).LastWriteTime) {
        $run = Invoke-Native { npm ci --no-audit --no-fund }
        if ($run.Code -ne 0) { Stop-Preview 1 "npm ci failed: $($run.Output)" }
        Write-Ok 'Ran npm ci (restart the dev server if it was running)'
    } else { Write-Ok 'npm dependencies are current' }
    $pending = Invoke-Native { php artisan migrate:status --pending --no-ansi }
    if ($pending.Code -eq 0 -and $pending.Output -match '\bPending\b' -and $pending.Output -notmatch 'No pending migrations') {
        Write-Warn 'There are migrations that have not run. Ask before running php artisan migrate.'
    } else { Write-Ok 'No pending migrations' }

    # 3. Herd and the preview ----------------------------------------------------
    Write-Step "Herd preview $($p.appUrl)"
    $missing = @('Herd', 'nginx' | Where-Object { -not (Get-Process $_ -ErrorAction SilentlyContinue) })
    if ($missing.Count -gt 0) { Stop-Preview 1 "Not running: $($missing -join ', '). Open Herd." }
    if (-not (Get-NetTCPConnection -State Listen -LocalPort $p.database.port -ErrorAction SilentlyContinue)) { Stop-Preview 1 "Nothing listens on MySQL's port $($p.database.port). Start Herd's MySQL service (Herd > Services)." }
    $health = Get-Page "$($p.appUrl)/healthz" | ConvertFrom-Json
    if ($null -eq $health -or $null -eq $health.local) { Stop-Preview 1 "$($p.appUrl)/healthz did not answer with the local block. Is APP_ENV=local in .env, and does 'herd links' list $($p.herdSite)?" }
    if ($health.local.git_revision -ne $short) {
        Invoke-Native { php artisan optimize:clear } | Out-Null
        $health = Get-Page "$($p.appUrl)/healthz" | ConvertFrom-Json
    }
    if ($health.local.git_revision -ne $short) { Stop-Preview 1 "The preview serves $($health.local.git_revision), not $short. Check 'herd links' shows $($p.herdSite) pointing at this clone." }
    if ($health.local.php_sapi -ne 'cgi-fcgi') { Stop-Preview 1 "The preview runs PHP as $($health.local.php_sapi), not FastCGI (cgi-fcgi). Run scripts\herd-setup.ps1." }
    if ($health.local.php -notlike "$($p.php).*") { Stop-Preview 1 "The preview runs PHP $($health.local.php), not $($p.php). Pin the site with: herd isolate $($p.php) --site=$($p.herdSite)" }
    Write-Ok "Serves $short on PHP $($health.local.php) through FastCGI"
    if ($health.status -ne 'ok') { Write-Warn "/healthz reports $($health.status). The preview still works; start the Herd service it needs if the task needs it." }

    # 4. Dev server ------------------------------------------------------------
    Write-Step "Dev server on port $($p.viteDevServerPort)"
    $listener = Get-DevListener
    if ($listener) {
        if (Get-DevServer | Where-Object { $_.ProcessId -eq $listener.OwningProcess }) {
            Write-Ok "Already running at $(Get-HotUrl)"
        } else {
            Write-Warn "Port $($p.viteDevServerPort) is used by $((Get-Process -Id $listener.OwningProcess).ProcessName), not this repository's Vite. Stop it, or set a free VITE_PORT in .env."
        }
    } elseif ($NoDevServer) {
        Write-Warn 'Not running (-NoDevServer). Run this script without -NoDevServer to start it.'
    } else {
        $node = Join-Path $nodeDir.FullName 'node.exe'
        $log = Join-Path $repo $devLog
        New-Item -ItemType Directory -Force (Split-Path $log) | Out-Null
        if (Test-Path 'public\hot') { Remove-Item 'public\hot' -Force }
        # A hidden cmd only redirects Vite's output to the log; nothing opens on the taskbar.
        $command = "`"`"$node`" `"$(Join-Path $repo $devScript)`" > `"$log`" 2>&1`""
        Start-Process -FilePath $env:ComSpec -ArgumentList "/d /s /c $command" -WorkingDirectory $repo -WindowStyle Hidden
        $deadline = (Get-Date).AddSeconds(30)
        while (-not ((Get-DevListener) -and (Get-HotUrl)) -and (Get-Date) -lt $deadline) { Start-Sleep -Milliseconds 500 }
        if (-not (Get-DevListener)) {
            $tail = (Get-Content $log -Tail 10 -ErrorAction SilentlyContinue) -join "`n"
            Stop-Preview 1 "Vite did not start on port $($p.viteDevServerPort). The end of ${devLog}:`n$tail"
        }
        Write-Ok "Started Vite at $(Get-HotUrl) on Node $($nodeDir.Name) with no window (log $devLog)"
    }
    $hotUrl = Get-HotUrl
    $expected = "$($p.appUrl):$($p.viteDevServerPort)"
    if ($hotUrl -and $hotUrl -ne $expected) { Write-Warn "public\hot points at $hotUrl, not $expected. Check APP_URL and VITE_PORT in .env." }

    Write-Step 'Ready'
    Write-Ok "Open $($p.appUrl) in Cursor's browser (live reload from $expected)."
    Write-Ok 'Stop Vite with scripts\preview.ps1 -StopDevServer.'
    if ($before -ne $head) { Write-Ok "Code moved from $($before.Substring(0, 12)) to $short during the sync." }
} finally {
    Pop-Location
}
