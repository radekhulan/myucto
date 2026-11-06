# First-time install for the Docker stack - smart: preferuje GHCR pull, build jen na vyzadani.
#
#   1. Generates .env with random DB password (if missing)
#   2. Generates cfg.docker.php from cfg.sample.php with Docker-friendly defaults (if missing)
#   3. Zvoli rezim a ziska image:
#        registry (default, je-li docker-compose.production.yml) -> docker compose pull z GHCR
#        source (-Build / MYINVOICE_INSTALL_MODE=source / chybi production.yml) -> local build
#        registry pull selze + je Dockerfile -> automaticky fallback na build
#   4. Brings the stack up
#   5. Waits for DB health and runs migrations (entrypoint je spusti sam)
#   6. Prints the URL where the setup wizard is available
#
# Prebiti: -Build  nebo  $env:MYINVOICE_INSTALL_MODE=registry|source.  Idempotent.
#
# Konfigurace (jen pri prvnim vygenerovani cfg.docker.php):
#   -AppUrl https://ucto.firma.cz   verejna adresa (app.url); jinak $env:MYINVOICE_APP_URL,
#                                   vychozi http://localhost:<APP_PORT>
#   -Dev                            ciste lokalni vyvojova instalace (app.env=development);
#                                   nikdy ji nevystavuj do site
# Vychozi je produkcni rezim (app.env=production). Secure cookie se ridi schematem
# app.url: https -> cookie_secure=true s prefixem __Host-, http -> false (jinak by se
# pres http://localhost neslo prihlasit).
[CmdletBinding()]
param([switch]$Build, [switch]$Dev, [string]$AppUrl = '')

# Skript vyzaduje PowerShell 7. Ve Windows PowerShellu 5.1 (povodni powershell.exe,
# porad vychozi na Win 11) se lisi zapis souboru bez BOM a chybi cast syntaxe, takze
# by tise vznikla rozbita konfigurace. Radeji skoncime hned a s jasnou hlaskou.
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
# Invoke-WebRequest / curl.exe na Windows zobrazuje progress bar, ktery v
# pollovacim loopu dramaticky zpomaluje kazde volani.
$ProgressPreference = 'SilentlyContinue'
$ProjectRoot = Resolve-Path (Join-Path $PSScriptRoot '..')
Set-Location $ProjectRoot

# Zadne tiche selhani (issue #14) - protejsek ERR trapu v docker-install.sh.
trap {
    Write-Host ""
    Write-Host "ERROR: docker-install.ps1 selhal na radku $($_.InvocationInfo.ScriptLineNumber): $($_.Exception.Message)" -ForegroundColor Red
    Write-Host "       INSTALACE NEBYLA DOKONCENA." -ForegroundColor Red
    exit 1
}

# Sdileny bezpecny parser `.env` (protejsek cmd/lib/env-load.sh).
$EnvLoader = Join-Path $PSScriptRoot 'lib\env-load.ps1'
if (-not (Test-Path -LiteralPath $EnvLoader)) {
    Write-Error "Chybi cmd\lib\env-load.ps1 - instalace je neuplna, dotahni repo (git pull)."
}
. $EnvLoader

if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
    Write-Error "docker not found in PATH"
}
& docker compose version > $null 2>&1
if ($LASTEXITCODE -ne 0) {
    Write-Error "'docker compose' (v2) plugin required - install Docker Desktop"
}

function New-RandomToken([int]$Bytes = 24) {
    $buf = New-Object byte[] $Bytes
    [System.Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($buf)
    return ([Convert]::ToBase64String($buf) -replace '[+/=]', '').Substring(0, [math]::Min($Bytes + 4, 32))
}

function Get-ComposeProjectName([string]$root) {
    if ($env:COMPOSE_PROJECT_NAME) { return $env:COMPOSE_PROJECT_NAME }
    return ((Split-Path -Leaf $root).ToLower() -replace '[^a-z0-9_-]', '')
}

# Zapise soubor jako UTF-8 BEZ BOM. `Set-Content -Encoding UTF8` pise BOM ve
# Windows PowerShellu 5.1; BOM pred '<?php' v cfg.docker.php se vypise na vystup
# jeste pred hlavickami a znemozni odeslani session cookie (= prihlaseni projde,
# ale aplikace pak uzivatele posila zpatky na /login).
function Write-Utf8NoBom([string]$Path, [string]$Content) {
    $full = if ([System.IO.Path]::IsPathRooted($Path)) { $Path } else { Join-Path (Get-Location).Path $Path }
    [System.IO.File]::WriteAllText($full, $Content, (New-Object System.Text.UTF8Encoding $false))
}

# Vrati $true, kdyz hostovy port $Port drzi CIZI (ne-myucto) kontejner nebo proces.
# Vlastni myucto kontejner na tomtez portu je OK (up/force-recreate ho prevezme).
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
    # Zadny docker kontejner nevlastni port -> zkus hostovy listener (proces mimo Docker).
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

# Posledni N radku logu app sluzby (pro diagnostiku sitove chyby ve fazi cekani).
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
# Vlastni zapis dela Set-DotEnvValue z lib/env-load.ps1 - UTF-8 bez BOM, LF konce
# radku, hodnota se v pripade potreby uvozuje.
function Set-EnvValue([string]$Key, [string]$Value) {
    Set-DotEnvValue -Path (Join-Path (Get-Location).Path '.env') -Key $Key -Value $Value
    $script:envVars[$Key] = $Value
}

function Get-AppLogTail([string[]]$ComposeArgs = @(), [int]$Lines = 40) {
    return (& docker compose @ComposeArgs logs --no-color --tail $Lines app 2>&1 | Out-String)
}

# Rozpozna TRVALOU sitovou chybu 'app bezi, ale nema compose sit' (DNS 'db' nejde)
# - odlisi ji od docasneho 'DB jeste nabiha' (to by byl Connection refused).
function Test-AppNetworkBroken([string]$Logs) {
    return [bool]($Logs -match 'getaddrinfo (for )?db failed|getaddrinfo failed|php_network_getaddresses|Temporary failure in name resolution|Name or service not known')
}

# Kryptograficky nahodny 32B klic v base64 (pepper, sifrovaci klic, klic
# mzdovych otisku). Pseudonahodny generator PowerShellu na to nestaci.
function New-Base64Key([int]$Bytes = 32) {
    $buf = New-Object byte[] $Bytes
    [System.Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($buf)
    return [Convert]::ToBase64String($buf)
}

# --- 1. .env --------------------------------------------------------------
if (-not (Test-Path .env)) {
    Write-Host "==> Generating .env with random DB passwords..."
    $rootPass = New-RandomToken 24
    $userPass = New-RandomToken 24
    $envContent = @"
# MyUcto.cz - Docker compose env (gitignored)
APP_PORT=8080
DB_PORT=3307
DB_NAME=myucto
DB_USER=myucto
DB_ROOT_PASSWORD=$rootPass
DB_PASSWORD=$userPass
"@
    Write-Utf8NoBom '.env' $envContent
    Write-Host "    .env written (passwords randomised)"
} else {
    Write-Host "==> .env already exists (skipping)"
}

# `.env` se PARSUJE sdilenym loaderem (issue #14) - stary inline regex neumel
# sundat uvozovky, neznal klice s cislici a inline komentar bral jako hodnotu.
# POSIX protejsek: cmd/lib/env-load.sh (drz obe varianty v synchronizaci).
$envVars = Read-DotEnvFile -Path '.env'

# --- 2. cfg.docker.php ----------------------------------------------------
# Separate from cfg.php so the same checkout can run both native dev (`php -S`)
# and the Docker stack without one clobbering the other. compose mounts this
# file as /var/www/html/cfg.php inside the container.
if (-not (Test-Path cfg.docker.php)) {
    Write-Host "==> Generating cfg.docker.php from cfg.sample.php with Docker defaults..."
    $pepper = New-Base64Key
    $encKey = New-Base64Key
    # Nova instalace = nova data, takze samostatny klic mzdovych otisku je bezpecny.
    # (Na existujici instalaci se nesmi menit, viz cfg.sample.php.)
    $payrollHashKey = New-Base64Key

    $appUrl = $AppUrl
    if (-not $appUrl) { $appUrl = $env:MYINVOICE_APP_URL }
    if (-not $appUrl) { $appUrl = "http://localhost:$($envVars.APP_PORT)" }
    $appUrl = $appUrl.TrimEnd('/')
    if ($appUrl -notmatch '^https?://[A-Za-z0-9.:\[\]-]+$') {
        Write-Error "-AppUrl musi byt jen origin ve tvaru http(s)://host[:port], dostal jsem '$appUrl'."
    }
    $appIsHttp = $appUrl -match '^http://'
    $appIsLocal = $appUrl -match '^https?://(localhost|127\.[0-9.]+|\[::1\])(:[0-9]+)?$'
    $appEnv = 'production'
    if ($Dev) { $appEnv = 'development' }

    $cfg = Get-Content cfg.sample.php -Raw
    # cfg.sample.php has TWO `'host' => '127.0.0.1',` lines (db then redis).
    # Replace first occurrence -> db, then first remaining occurrence -> redis.
    $cfg = [regex]::Replace($cfg, "'host'    => '127\.0\.0\.1',", "'host'    => 'db',",    1)
    $cfg = [regex]::Replace($cfg, "'host'    => '127\.0\.0\.1',", "'host'    => 'redis',", 1)
    $cfg = $cfg -replace "'name'    => 'myucto',", "'name'    => '$($envVars.DB_NAME)',"
    $cfg = $cfg -replace "'user'    => 'root',",      "'user'    => '$($envVars.DB_USER)',"
    $cfg = $cfg -replace "'pass'    => 'CHANGE-ME',", "'pass'    => '$($envVars.DB_PASSWORD)',"
    $cfg = $cfg -replace "'pepper' => 'CHANGE-ME',",  "'pepper' => '$pepper',"
    $cfg = $cfg -replace "'secret_encryption_key' => '',", "'secret_encryption_key' => '$encKey',"
    $cfg = $cfg -replace "'payroll_hash_key' => '',", "'payroll_hash_key' => '$payrollHashKey',"
    $cfg = $cfg -replace "'env'    => 'production',",      "'env'    => '$appEnv',"
    $cfg = $cfg -replace "'url'    => 'https://dev\.example\.com',", "'url'    => '$appUrl',"
    # Secure cookie podle schematu app.url: pres plain HTTP by prohlizec Secure
    # cookie (i prefix __Host-) zahodil a prihlaseni by nefungovalo. U https
    # zustavaji produkcni hodnoty z cfg.sample.php.
    if ($appIsHttp) {
        $cfg = $cfg -replace "'cookie_name'   => '__Host-myinvoice_session',", "'cookie_name'   => 'myinvoice_session',"
        $cfg = $cfg -replace "'cookie_secure' => true,", "'cookie_secure' => false,"
        # Cookie duveryhodneho zarizeni ma stejny __Host- prefix, ktery prohlizec pres
        # plain HTTP zahodi. Bez tehle nahrady prestane fungovat MFA "zapamatovat si".
        $cfg = $cfg -replace "'trusted_cookie_name'     => '__Host-myinvoice_td',", "'trusted_cookie_name'     => 'myinvoice_td',"
    }
    Write-Utf8NoBom 'cfg.docker.php' $cfg
    Write-Host "    cfg.docker.php written (app.env=$appEnv, app.url=$appUrl)"
    if ($appIsHttp -and -not $appIsLocal) {
        Write-Host "    !!  app.url je HTTP mimo localhost: prihlasovaci cookie pujde po siti nesifrovane.  !!" -ForegroundColor Yellow
        Write-Host "    !!  Pro provoz dej pred aplikaci HTTPS proxy a nastav https adresu + cookie_secure. !!" -ForegroundColor Yellow
    }
    if ($appEnv -eq 'development') {
        Write-Host "    !!  Vyvojovy rezim (-Dev): instalaci nevystavuj do site.  !!" -ForegroundColor Yellow
    }
    Write-Host ""
    Write-Host "    !!  Edit cfg.docker.php to fill in SMTP, Cloudflare Turnstile, IP allowlist  !!" -ForegroundColor Yellow
    Write-Host ""
} else {
    Write-Host "==> cfg.docker.php already exists (skipping)"
}

# --- 3. zvolit rezim + ziskat image ---------------------------------------
# Default = registry (GHCR pull) - rychlejsi a setri RAM/disk/CPU build. Lokalni build
# jen na vyzadani (-Build / MYINVOICE_INSTALL_MODE=source) nebo kdyz neni production.yml.
$runningImage = (& docker ps --filter 'label=com.docker.compose.service=app' --format '{{.Image}}' 2>$null |
    Where-Object { $_ -match 'myucto' } | Select-Object -First 1)
if ($runningImage) {
    Write-Host "==> Pozn.: app uz bezi (image '$runningImage'). Pro aktualizaci pouzij cmd\docker-update.ps1."
}

$mode = if ($Build) { 'source' }
        elseif ($env:MYINVOICE_INSTALL_MODE) { $env:MYINVOICE_INSTALL_MODE }
        elseif (Test-Path docker-compose.production.yml) { 'registry' }
        else { 'source' }

$composeArgs = @()
if ($mode -eq 'registry' -and (Test-Path docker-compose.production.yml)) {
    $composeArgs = @('-f', 'docker-compose.production.yml')
}
$composeHint = if ($composeArgs.Count -gt 0) { " (compose: $($composeArgs[1]))" } else { '' }
Write-Host "==> Rezim instalace: $mode$composeHint"
Write-Host "    (registry = GHCR pull; prebij pres -Build nebo MYINVOICE_INSTALL_MODE=registry|source)"

if ($mode -eq 'registry') {
    Write-Host "==> Pulling image from GHCR..."
    & docker compose @composeArgs pull app
    if ($LASTEXITCODE -ne 0) {
        if (Test-Path Dockerfile) {
            Write-Warning "GHCR pull selhal -> fallback na lokalni build."
            $mode = 'source'; $composeArgs = @()
        } else {
            Write-Error "GHCR pull selhal a neni Dockerfile pro build."
        }
    }
}

if ($mode -eq 'source') {
    & docker image inspect myucto:latest 2>$null | Out-Null
    if ($LASTEXITCODE -ne 0) {
        Write-Host "==> Building image..."
        & docker compose @composeArgs build app
        if ($LASTEXITCODE -ne 0) { Write-Error "docker compose build failed" }
    }
}

# --- 3b. pre-flight: hostovy port aplikace --------------------------------
# Kolize portu (napr. stary/half-created app kontejner drzici 8080) zpusobi, ze
# se novy app kontejner nepripoji k compose siti ('port is already allocated')
# a pak nepreklada hostname 'db' -> migrace v cyklu padaji. Odhal to predem.
$ourProject = Get-ComposeProjectName $ProjectRoot
$appPort = 0; [void][int]::TryParse(("" + $envVars.APP_PORT), [ref]$appPort)
if ($appPort -le 0) { $appPort = 8080 }
Write-Host "==> Pre-flight: kontrola hostoveho portu $appPort..."
if (Test-ForeignPortHolder $appPort $ourProject 'APP_PORT') {
    Write-Error "Host port $appPort je obsazeny cizim procesem/kontejnerem (viz vyse) - uvolni ho nebo zmen APP_PORT v .env a spust znovu."
}

# Port databaze: mapuje se na loopback jen kvuli klientovi zvenci. Souzeni
# MyInvoice a MyUcta na jednom stroji je bezny scenar (prevod dat), takze kolize
# je ocekavatelna a nema kvuli ni instalace padat - port posuneme a zapiseme do
# .env. Aplikace na nem nezavisi, uvnitr site saha na 'db:3306'.
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
    $dbPort = $free
}

# Fallback (GHCR pull selhal) i cisty build meni definici sluzby app oproti tomu,
# co pripadne bezi -> vynut cistou rekreaci app, at nezustane pul-vytvoreny
# kontejner drzici port bez site. DB data jsou ve volume, neztrati se.
$forceRecreate = ($mode -eq 'source')

# --- 4. up databaze --------------------------------------------------------
# --remove-orphans: uklidi kontejnery z jineho compose souboru (napr. po
# fallbacku registry->source) - jinak zbyle stale kontejnery drzi porty/site.
Write-Host "==> Starting database..."
& docker compose @composeArgs up -d --remove-orphans db
if ($LASTEXITCODE -ne 0) { Write-Error "docker compose up (db) failed" }

# --- 5. wait for DB health -------------------------------------------------
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

# --- 4b. up app ------------------------------------------------------------
# App az po zdrave DB (depends_on to sice hlida, ale takhle mame jasne stage
# hlaseni). force-recreate na source/fallback ceste - viz vyse.
$appUp = @('up', '-d', '--remove-orphans')
if ($forceRecreate) { $appUp += '--force-recreate'; Write-Host "==> Starting app (source/fallback rezim -> --force-recreate)..." }
else                { Write-Host "==> Starting app..." }
& docker compose @composeArgs @appUp app
if ($LASTEXITCODE -ne 0) { Write-Error "docker compose up (app) failed" }

# --- 6. wait for app (entrypoint runs migrations) --------------------------
# Migrace se spousti automaticky z entrypointu pred web serverem. Misto druheho
# explicitniho migrate (= race condition s entrypointem, napr. 0015 FK rename)
# jen cekame, az app odpovi na /api/health (v ALLOWED_PATHS pro FirstRunLock ->
# 200 i ve fresh-install stavu). Migraci muze byt hodne -> stedry timeout.
# Zamerne bez null-conditional operatoru (`?.`) - ten je az v PS7 a ve Windows
# PowerShellu 5.1 je to chyba parseru celeho souboru, tzn. skript by se nespustil
# vubec a kontrola verze vyse by se nikdy nevypsala.
$curlCmd = Get-Command curl.exe -ErrorAction SilentlyContinue
$curl = if ($curlCmd) { $curlCmd.Source } else { $null }
if (-not $curl) { $curl = 'C:\Windows\System32\curl.exe' }
if (-not (Test-Path $curl)) {
    Write-Error "curl.exe nenalezen (potreba na Win 10/11+). Updatuj OS nebo doinstaluj curl."
}

Write-Host "==> Waiting for app to become available (entrypoint runs migrations)..."
$appReady = $false
$lastErr = ''
$recovered = $false
for ($i = 1; $i -le 90; $i++) {
    $out = & $curl -fsS -m 3 -o NUL "http://localhost:$appPort/api/health" 2>&1
    if ($LASTEXITCODE -eq 0) { $appReady = $true; Write-Host "    App ready."; break }
    $lastErr = ($out | Out-String).Trim()

    # Kazdy 5. neuspesny pokus zdiagnostikuj z logu app: rozlis TRVALOU sitovou
    # chybu (app bezi, ale nema compose sit) od docasneho 'DB/migrace jeste bezi'.
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

# --- 6. report -------------------------------------------------------------
$port = $envVars.APP_PORT
if (-not $port) { $port = '8080' }
Write-Host ""
Write-Host "============================================================"
Write-Host " MyUcto.cz is up at:  http://localhost:$port"
Write-Host ""
Write-Host " The browser will land on the setup wizard:"
Write-Host "   1. Admin user (name, email, password >= 12 chars)"
Write-Host "   2. Supplier (IC -> Nacist z ARES -> bank account)"
Write-Host "   3. Optional sample data"
Write-Host ""
$cf = if ($composeArgs.Count -gt 0) { " $($composeArgs -join ' ')" } else { '' }
Write-Host " Useful:"
Write-Host "   docker compose$cf logs -f app    # tail app logs"
Write-Host "   docker compose$cf down           # stop stack (data persists)"
Write-Host "   docker compose$cf down -v        # stop + WIPE volumes (destroys DB)"
Write-Host "============================================================"
