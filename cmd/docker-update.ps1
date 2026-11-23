# Update a running MyUcto.cz Docker stack to the latest code.
#
#   1. Pulls (registry mode) or rebuilds (source mode) the app image
#   2. Restarts the stack
#   3. Waits for DB health and runs pending migrations
#
# Detects mode automatically - preferuje aktualne RUNNING stack:
#   1. Pokud bezi stack z `docker-compose.production.yml` -> registry mode
#      (GHCR pull, dale pouziva `-f docker-compose.production.yml`).
#   2. Pokud bezi stack z `docker-compose.yml` a je `.git/` + `build:` blok
#      -> source mode (git pull + local build).
#   3. Fallback bez beziciho stacku - podle existujicich souboru.
#
# Idempotent - safe to re-run. Volumes (DB data) persist; backup is your responsibility.
[CmdletBinding()]
param()

# Skript vyzaduje PowerShell 7 - stejne jako docker-install.ps1 / docker-ghcr.ps1.
if ($PSVersionTable.PSVersion.Major -lt 7) {
    Write-Host ""
    Write-Host "  Tento skript vyzaduje PowerShell 7 nebo novejsi." -ForegroundColor Red
    Write-Host "  Bezi ve Windows PowerShellu $($PSVersionTable.PSVersion) (powershell.exe)." -ForegroundColor Red
    Write-Host ""
    Write-Host "  Instalace:  winget install --id Microsoft.PowerShell -e"
    Write-Host "  Pak spust znovu pres 'pwsh' (ne 'powershell'):"
    Write-Host "      pwsh -File `"$PSCommandPath`"" -ForegroundColor Yellow
    Write-Host ""
    exit 1
}

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
$ProjectRoot = Resolve-Path (Join-Path $PSScriptRoot '..')
Set-Location $ProjectRoot

# Zadne tiche selhani (issue #14) - protejsek ERR trapu v docker-update.sh.
# Kazdy neosetreny pad vypise, KDE spadl, a skript konci nenulovym kodem, aby
# ho watcher i UI reportovaly jako "selhalo", ne jako nedokoncenou aktualizaci.
trap {
    Write-Host ""
    Write-Host "ERROR: docker-update.ps1 selhal na radku $($_.InvocationInfo.ScriptLineNumber): $($_.Exception.Message)" -ForegroundColor Red
    Write-Host "       AKTUALIZACE NEBYLA DOKONCENA. Oprav pricinu vyse a spust skript znovu." -ForegroundColor Red
    exit 1
}

if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
    Write-Error "docker not found in PATH"
}
& docker compose version > $null 2>&1
if ($LASTEXITCODE -ne 0) { Write-Error "'docker compose' (v2) plugin required" }
if (-not (Test-Path .env)) { Write-Error ".env not found - run docker-install.ps1 first" }

# `.env` se PARSUJE sdilenym loaderem (issue #14) - stary inline regex neumel
# sundat uvozovky, neznal klice s cislici a inline komentar bral jako hodnotu.
# POSIX protejsek: cmd/lib/env-load.sh (drz obe varianty v synchronizaci).
$EnvLoader = Join-Path $PSScriptRoot 'lib\env-load.ps1'
if (-not (Test-Path -LiteralPath $EnvLoader)) {
    Write-Error "Chybi cmd\lib\env-load.ps1 - instalace je neuplna, dotahni repo (git pull)."
}
. $EnvLoader
$envVars = Read-DotEnvFile -Path '.env'

function Get-ComposeProjectName([string]$root) {
    if ($env:COMPOSE_PROJECT_NAME) { return $env:COMPOSE_PROJECT_NAME }
    return ((Split-Path -Leaf $root).ToLower() -replace '[^a-z0-9_-]', '')
}

# Najde prvni volny hostovy port od $Start vys (max 40 pokusu).
function Find-FreePort([int]$Start) {
    for ($p = $Start; $p -lt ($Start + 40); $p++) {
        $busy = $false
        foreach ($ln in (& docker ps --format '{{.Ports}}' 2>$null)) { if ($ln -match ":$p->") { $busy = $true; break } }
        if (-not $busy) { try { if (Get-NetTCPConnection -LocalPort $p -State Listen -ErrorAction Stop) { $busy = $true } } catch {} }
        if (-not $busy) { return $p }
    }
    return 0
}

# Prepise jeden klic v .env (a v $envVars), aby zmena prezila dalsi spusteni.
# Vlastni zapis dela Set-DotEnvValue z lib/env-load.ps1 - zapisuje UTF-8 bez BOM,
# LF konce radku a hodnotu v pripade potreby uvozuje.
function Set-EnvValue([string]$Key, [string]$Value) {
    Set-DotEnvValue -Path (Join-Path (Get-Location).Path '.env') -Key $Key -Value $Value
    $script:envVars[$Key] = $Value
}
# Vrati $true, kdyz hostovy port $Port drzi CIZI (ne-myucto) kontejner nebo proces.
# Vlastni myucto kontejner na tomtez portu je OK (up ho prevezme).
function Test-ForeignPortHolder([int]$Port, [string]$OurProject, [string]$EnvVar = 'APP_PORT') {
    $lines = & docker ps --format '{{.Names}}|{{.Image}}|{{.Label "com.docker.compose.project"}}|{{.Ports}}' 2>$null
    foreach ($ln in $lines) {
        $parts = $ln -split '\|', 4
        if ($parts.Count -lt 4) { continue }
        if ($parts[3] -notmatch ":$Port->") { continue }
        $name = $parts[0]; $image = $parts[1]; $proj = $parts[2]
        if (($proj -eq $OurProject) -or ($name -match 'myucto') -or ($image -match 'myucto')) {
            Write-Host "    Port $Port drzi vlastni myucto kontejner '$name' (image $image) - OK."
            return $false
        }
        Write-Warning "Host port $Port uz drzi CIZI Docker kontejner '$name' (image $image, projekt '$proj')."
        Write-Host    "    Reseni: 'docker stop $name' nebo zmen $EnvVar v .env a spust znovu." -ForegroundColor Yellow
        return $true
    }
    $conn = $null
    try { $conn = Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction Stop | Select-Object -First 1 } catch {}
    if ($conn) {
        $proc  = Get-Process -Id $conn.OwningProcess -ErrorAction SilentlyContinue
        $pname = if ($proc) { $proc.ProcessName } else { "PID $($conn.OwningProcess)" }
        if ($pname -match 'docker|vpnkit|wslrelay|com\.docker') {
            Write-Host "    Port $Port drzi Docker proxy ($pname), ale zadny bezici kontejner ho nevlastni - stale endpoint. 'up --force-recreate app' ho uvolni."
            return $false
        }
        Write-Warning "Host port $Port uz posloucha proces '$pname' (PID $($conn.OwningProcess)) mimo Docker."
        Write-Host    "    Reseni: ukonci proces nebo zmen $EnvVar v .env a spust znovu." -ForegroundColor Yellow
        return $true
    }
    return $false
}

function Get-AppLogTail([string[]]$ComposeArgs = @(), [int]$Lines = 40) {
    return (& docker compose @ComposeArgs logs --no-color --tail $Lines app 2>&1 | Out-String)
}

function Test-AppNetworkBroken([string]$Logs) {
    return [bool]($Logs -match 'getaddrinfo (for )?db failed|getaddrinfo failed|php_network_getaddresses|Temporary failure in name resolution|Name or service not known')
}

# Detect mode z IMAGE bezicho app kontejneru (autoritativni - nezavisi na tom, ktery
# compose file je po ruce; stara detekce podle compose souboru byla krehka, protoze
# git klon ma oba soubory + kolidujici nazev projektu). Prebiti: MYINVOICE_UPDATE_MODE.
#   - bezici registry image (ghcr.io/...) -> registry mode -> docker compose pull
#   - bezici lokalni build (myucto:latest)  -> source mode -> git pull + build
#   - nic nebezi + lokalne GHCR image -> registry (byls GHCR deploy, jen zhasnuty)
#   - nic nebezi + .git + build: -> source, jinak registry
$mode = $env:MYINVOICE_UPDATE_MODE

$runningImage = (& docker ps --filter 'label=com.docker.compose.service=app' --format '{{.Image}}' 2>$null |
    Where-Object { $_ -match 'myucto' } | Select-Object -First 1)

if (-not $mode) {
    if ($runningImage) {
        $mode = if ($runningImage -match '/') { 'registry' } else { 'source' }
    } elseif (& docker images --format '{{.Repository}}' 2>$null | Select-String -Quiet -Pattern 'ghcr\.io/.*myucto') {
        # Stack nebezi, ALE lokalne je stazeny GHCR image -> drive se pullovalo = registry deploy.
        $mode = 'registry'
    } elseif ((Test-Path .git) -and (Select-String -Path docker-compose.yml -Pattern '^\s*build:' -Quiet)) {
        $mode = 'source'
    } else {
        $mode = 'registry'
    }
}

$composeArgs = @()
if ($mode -eq 'registry' -and (Test-Path docker-compose.production.yml)) {
    $composeArgs = @('-f', 'docker-compose.production.yml')
}

if ($runningImage) {
    Write-Host "==> Detekovano: bezici image '$runningImage' -> rezim '$mode'"
} else {
    Write-Host "==> Zadny bezici app kontejner -> rezim '$mode' (dle pritomnych souboru)"
}
Write-Host "    (prebit lze pres MYINVOICE_UPDATE_MODE=registry|source)"
if ($composeArgs.Count -gt 0) { Write-Host "    compose: $($composeArgs[1])" }

# --- 1. fetch new code/image ---------------------------------------------
$tagBump = $null
$pinCompose = $null
if ($mode -eq 'source') {
    $dirty = & git status --porcelain
    if ($dirty) {
        Write-Warning "Working tree is dirty - local changes won't be pulled."
        Write-Warning "Consider 'git stash' or commit first. Continuing in 5s..."
        Start-Sleep -Seconds 5
    }
    Write-Host "==> git pull"
    & git pull --ff-only
    if ($LASTEXITCODE -ne 0) { Write-Error "git pull failed" }
    Write-Host "==> Rebuilding app image..."
    & docker compose @composeArgs build --pull app
    if ($LASTEXITCODE -ne 0) { Write-Error "docker compose build failed" }
} else {
    $requestedVersion = $env:MYINVOICE_TARGET_VERSION
    if ($requestedVersion -cmatch '\Av?[0-9]{1,6}\.[0-9]{1,6}\.[0-9]{1,6}\z') {
        $imageTagLib = Join-Path $PSScriptRoot 'lib\image-tag.ps1'
        if (-not (Test-Path -LiteralPath $imageTagLib)) {
            Write-Error "Chybi cmd\lib\image-tag.ps1, dotahni repo."
        }
        . $imageTagLib
        $resolvedConfig = & docker compose @composeArgs config
        if ($LASTEXITCODE -ne 0) { Write-Error "docker compose config failed" }
        $tagBump = Get-ImageTagBump -Lines $resolvedConfig -Target $requestedVersion
        if ($tagBump) {
            if ($composeArgs.Count -gt 0) {
                $pinCompose = $composeArgs[1]
            } elseif (-not ($env:COMPOSE_FILE -or $envVars.COMPOSE_FILE)) {
                $composeFiles = @(('compose.yaml', 'compose.yml', 'docker-compose.yaml', 'docker-compose.yml',
                    'compose.override.yaml', 'compose.override.yml', 'docker-compose.override.yaml', 'docker-compose.override.yml') |
                    Where-Object { Test-Path -LiteralPath $_ -PathType Leaf })
                if ($composeFiles.Count -eq 1 -and $composeFiles[0] -notlike '*.override.*') {
                    $pinCompose = $composeFiles[0]
                }
            }
            if (-not $pinCompose -or (Get-Item -LiteralPath $pinCompose).LinkType) {
                Write-Error "Pripnuty image sluzby app nelze jednoznacne zmenit v souboru Compose. Nastav cilovou verzi rucne."
            }
            $literalBump = Get-ImageTagBump -Compose $pinCompose -Target $requestedVersion
            if ($literalBump.Old -cne $tagBump.Old -or $literalBump.New -cne $tagBump.New) {
                Write-Error "Pripnuty image sluzby app nelze jednoznacne zmenit v souboru Compose. Nastav cilovou verzi rucne."
            }
            Write-Host "==> Pripnuta verze $($tagBump.Old.Split(':')[-1]) -> cilova $($tagBump.New.Split(':')[-1])"
            & docker pull $tagBump.New
            if ($LASTEXITCODE -ne 0) { Write-Error "docker pull $($tagBump.New) failed" }
        }
    }
    if (-not $tagBump) {
        Write-Host "==> Pulling latest image from registry..."
        & docker compose @composeArgs pull app
        if ($LASTEXITCODE -ne 0) { Write-Error "docker compose pull failed" }
    }
}

# --- 1b. detect legacy 3-volume layout and auto-migrate (3.5.x -> 3.6.0) --
# Od 3.6.0 je default Compose layout single-volume (`app-data:/data`). Pokud
# existuji stare 3-volume volumes (`app-log`, `app-storage`, `app-private`)
# a novy `app-data` ne, je to uvodni migrace - probehne automaticky.
$project = $env:COMPOSE_PROJECT_NAME
if (-not $project) {
    $project = (Split-Path -Leaf $ProjectRoot).ToLower() -replace '[^a-z0-9_-]', ''
}
$oldVolumes = @("${project}_app-log", "${project}_app-storage", "${project}_app-private")
$newData = "${project}_app-data"
$hasOld = $false
foreach ($v in $oldVolumes) {
    & docker volume inspect $v *>$null
    if ($LASTEXITCODE -eq 0) { $hasOld = $true; break }
}
& docker volume inspect $newData *>$null
$hasNew = ($LASTEXITCODE -eq 0)

if ($hasOld -and (-not $hasNew)) {
    Write-Host ""
    Write-Host "############################################################" -ForegroundColor Yellow
    Write-Host "#  MIGRACE VOLUMES (3.5.x -> 3.6.0)"                          -ForegroundColor Yellow
    Write-Host "#"                                                            -ForegroundColor Yellow
    Write-Host "#  Detekovan stary 3-volume Docker layout. 3.6.0 prechazi na" -ForegroundColor Yellow
    Write-Host "#  single-volume (/data), ktery drzi i cfg.local.php - tim se"  -ForegroundColor Yellow
    Write-Host "#  per-instance konfigurace (app.url, auth.require_totp) chova" -ForegroundColor Yellow
    Write-Host "#  korektne i po image updatu."                                -ForegroundColor Yellow
    Write-Host "#"                                                            -ForegroundColor Yellow
    Write-Host "#  Skript ted automaticky:"                                    -ForegroundColor Yellow
    Write-Host "#    1. Snapshotne cfg.local.php z bezicho kontejneru"        -ForegroundColor Yellow
    Write-Host "#    2. Zastavi stack (DB volume zustava)"                     -ForegroundColor Yellow
    Write-Host "#    3. Zkopiruje data ze starych volumes do noveho app-data" -ForegroundColor Yellow
    Write-Host "#    4. Obnovi cfg.local.php v novem volumu"                   -ForegroundColor Yellow
    Write-Host "#    5. Spusti stack na novem layoutu"                         -ForegroundColor Yellow
    Write-Host "#"                                                            -ForegroundColor Yellow
    Write-Host "#  Stare volumes NEMAZU - po overeni je smaz rucne."           -ForegroundColor Yellow
    Write-Host "############################################################" -ForegroundColor Yellow
    Write-Host ""
    # Volame migrate.ps1 v AKTUALNIM PS hostu (& path). Sub-`powershell -File` by
    # spustil PS 5.1, ktery nezna $PSNativeCommandUseErrorActionPreference a
    # `docker compose down` (progress do stderr) by trigoval NativeCommandError.
    & (Join-Path $ProjectRoot 'cmd\docker-migrate-volumes.ps1')
    if ($LASTEXITCODE -ne 0) {
        Write-Host "ERROR: Migrace volumes selhala (rc=$LASTEXITCODE) - check log above" -ForegroundColor Red
        exit 1
    }
    Write-Host ""
}

# --- pre-flight: hostovy port aplikace -----------------------------------
$ourProject = Get-ComposeProjectName $ProjectRoot
$appPort = 0; [void][int]::TryParse(("" + $envVars.APP_PORT), [ref]$appPort)
if ($appPort -le 0) { $appPort = 8080 }
Write-Host "==> Pre-flight: kontrola hostoveho portu $appPort..."
if (Test-ForeignPortHolder $appPort $ourProject 'APP_PORT') {
    Write-Error "Host port $appPort je obsazeny cizim procesem/kontejnerem (viz vyse) - uvolni ho nebo zmen APP_PORT v .env a spust znovu."
}

# Port databaze: kdyz ho behem odstavky sebral cizi kontejner, `up` by spadl na
# 'port already allocated'. Mapovani je jen loopback konvence pro DB klienta,
# aplikace uvnitr site saha na 'db:3306' - port proto radeji posuneme, nez aby
# update selhal. Zmena jde do .env, takze prezije dalsi spusteni.
$dbPort = 0; [void][int]::TryParse(("" + $envVars.DB_PORT), [ref]$dbPort)
if ($dbPort -le 0) { $dbPort = 3307 }
Write-Host "==> Pre-flight: kontrola hostoveho portu databaze $dbPort..."
if (Test-ForeignPortHolder $dbPort $ourProject 'DB_PORT') {
    $free = Find-FreePort ($dbPort + 1)
    if ($free -le 0) {
        Write-Error "Host port $dbPort je obsazeny a v rozsahu $($dbPort+1)..$($dbPort+40) nenasel volny - uvolni port nebo zmen DB_PORT v .env rucne."
    }
    Write-Host "    Prepinam DB_PORT $dbPort -> $free a zapisuji do .env." -ForegroundColor Yellow
    Set-EnvValue 'DB_PORT' "$free"
}

# --- 2. restart ----------------------------------------------------------
# --remove-orphans: uklidi stale kontejnery z jineho compose souboru; jinak
# zbyly app kontejner drzi port a novy se nepripoji k siti ('port already
# allocated' -> app nepreklada 'db' -> migrace v cyklu padaji).
Write-Host "==> Restarting database..."
& docker compose @composeArgs up -d --remove-orphans db
if ($LASTEXITCODE -ne 0) { Write-Error "docker compose up (db) failed" }

# --- 3. wait for DB health ----------------------------------------------
Write-Host "==> Waiting for database to become healthy..."
$ready = $false
for ($i = 1; $i -le 45; $i++) {
    $json = & docker compose @composeArgs ps --format json db 2>$null
    if ($json -match '"Health":"healthy"') { $ready = $true; Write-Host "    DB ready."; break }
    if ($json -match '"Health":"unhealthy"') { Write-Warning "DB hlasi 'unhealthy' - cekam dal (attempt $i/45)..." }
    Start-Sleep -Seconds 2
}
if (-not $ready) {
    Write-Error "DB failed to become healthy in ~90s. Check 'docker compose logs db'."
}

# App az po zdrave DB. Novy image -> compose app tak jako tak rekreuje; s
# --remove-orphans + auto-recovery nize je restart odolny proti kolizi portu/site.
if ($tagBump) {
    $pinPath = (Resolve-Path -LiteralPath $pinCompose).Path
    $composeBefore = (Get-FileHash -LiteralPath $pinPath).Hash
    $composeStage = "$pinPath.$([guid]::NewGuid().ToString('N')).tmp"
    try {
        Copy-Item -LiteralPath $pinPath -Destination $composeStage
        Set-ImageTag -Compose $composeStage -Old $tagBump.Old -New $tagBump.New
        $resolvedConfig = & docker compose -f $composeStage config
        if ($LASTEXITCODE -ne 0) { Write-Error "docker compose config failed" }
        if ((Find-AppImageLine -Lines $resolvedConfig).Image -cne $tagBump.New -or
            (Get-FileHash -LiteralPath $pinPath).Hash -cne $composeBefore) {
            Write-Error "Zmenu pripnute verze sluzby app v $pinCompose se nepodarilo overit."
        }
        [System.IO.File]::Move($composeStage, $pinPath, $true)
        Write-Host "    ${pinCompose}: $($tagBump.New)"
    } finally {
        Remove-Item -LiteralPath $composeStage -Force -ErrorAction SilentlyContinue
    }
}
Write-Host "==> Restarting app..."
& docker compose @composeArgs up -d --remove-orphans app
if ($LASTEXITCODE -ne 0) { Write-Error "docker compose up (app) failed" }

# --- 3b. wait for app (+ auto-recovery pri chybejici siti) ---------------
# Zamerne bez null-conditional operatoru (`?.`) - ten je az v PS7 a ve Windows
# PowerShellu 5.1 je to chyba parseru celeho souboru, tzn. skript by se nespustil
# vubec a kontrola verze vyse by se nikdy nevypsala.
$curlCmd = Get-Command curl.exe -ErrorAction SilentlyContinue
$curl = if ($curlCmd) { $curlCmd.Source } else { $null }
if (-not $curl) { $curl = 'C:\Windows\System32\curl.exe' }
if (-not (Test-Path $curl)) {
    Write-Error "curl.exe nenalezen (potreba na Win 10/11+). Updatuj OS nebo doinstaluj curl."
}

$port = $appPort
Write-Host "==> Waiting for app to become available (entrypoint runs migrations)..."
$appReady = $false
$lastErr = ''
$recovered = $false
for ($i = 1; $i -le 90; $i++) {
    $out = & $curl -fsS -m 3 -o NUL "http://localhost:$port/api/health" 2>&1
    if ($LASTEXITCODE -eq 0) { $appReady = $true; Write-Host "    App ready."; break }
    $lastErr = ($out | Out-String).Trim()
    if (($i % 5) -eq 0) {
        $logs = Get-AppLogTail -ComposeArgs $composeArgs -Lines 40
        if (-not $recovered -and (Test-AppNetworkBroken $logs)) {
            Write-Warning "App bezi, ale nema compose sit (DNS 'db' selhava) -> auto-recovery: force-recreate app."
            & docker compose @composeArgs up -d --remove-orphans --force-recreate app 2>&1 | Out-Null
            $recovered = $true
            Start-Sleep -Seconds 3
            continue
        }
        elseif ($logs -match 'Migration attempt') { Write-Host "    ...migrace bezi (attempt $i/90)" }
    }
    Start-Sleep -Seconds 2
}
if (-not $appReady) {
    Write-Host "    Last curl error: $lastErr" -ForegroundColor Yellow
    Write-Host "    --- posledni radky 'docker compose logs app' ---" -ForegroundColor Yellow
    Write-Host (Get-AppLogTail -ComposeArgs $composeArgs -Lines 25)
    Write-Error "App failed to respond in time. Check 'docker compose logs app'."
}

if ($tagBump) {
    $expectedVersion = $tagBump.New.Split(':')[-1]
    $actualVersion = & docker compose @composeArgs exec -T app cat /var/www/html/VERSION
    if ($LASTEXITCODE -ne 0 -or $actualVersion -cne $expectedVersion) {
        Write-Error "Sluzba app hlasi verzi '$actualVersion', ocekavana je '$expectedVersion'."
    }
}

# --- 3c. uklid osirelych vrstev po updatu (bezpecne - jen dangling) -------
Write-Host "==> Uklid dangling image vrstev..."
& docker image prune -f *> $null
Write-Host "    (stare tagovane verze uklidis pres cmd\docker-prune-images.ps1)"

# --- 4. report -----------------------------------------------------------
Write-Host ""
Write-Host "============================================================"
Write-Host " Update complete. App: http://localhost:$port"
Write-Host ""
Write-Host " Tail logs:        docker compose logs -f app"
Write-Host " Restart only:     docker compose restart app"
Write-Host "============================================================"
