# One-click install z pre-built image na GHCR (zadny local build).
#
#   1. Vygeneruje .env s random DB hesly (pokud chybi)
#   2. Vygeneruje cfg.docker.php z cfg.sample.php s random secrets (pokud chybi)
#   3. docker compose pull (image z ghcr.io/radekhulan/myucto:latest)
#   4. docker compose up -d (entrypoint sam spusti migrace pred apache2-foreground)
#   5. Pocka az app odpovi na HTTP (= migrace dobehly, apache bezi)
#   6. Vypise URL k setup wizardu
#
# Pouziva docker-compose.production.yml (image pull, zadny build).
# Idempotentni - bezpecne spoustet opakovane.
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
param([switch]$Dev, [string]$AppUrl = '')

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
# pollovacim loopu dramaticky zpomaluje kazde volani (i nekolik sekund navic).
$ProgressPreference = 'SilentlyContinue'

# Detekce PROJECT_ROOT - skript se pouszti dvema zpusoby (stejne jako .sh):
#   a) standalone install (curl 3 souboru do jedne slozky): script vedle compose file
#   b) z klonu repa: script v `cmd/`, compose file o uroven vys
if (Test-Path (Join-Path $PSScriptRoot 'docker-compose.production.yml')) {
    $ProjectRoot = $PSScriptRoot
} else {
    $ProjectRoot = Resolve-Path (Join-Path $PSScriptRoot '..')
}
Set-Location $ProjectRoot

$ComposeFile = 'docker-compose.production.yml'

# Zadne tiche selhani (issue #14) - protejsek ERR trapu v docker-ghcr.sh.
trap {
    Write-Host ""
    Write-Host "ERROR: docker-ghcr.ps1 selhal na radku $($_.InvocationInfo.ScriptLineNumber): $($_.Exception.Message)" -ForegroundColor Red
    Write-Host "       INSTALACE NEBYLA DOKONCENA." -ForegroundColor Red
    exit 1
}

# Sdileny bezpecny parser `.env` (protejsek cmd/lib/env-load.sh, issue #14).
# Skript se pousti i standalone (curl vedle compose file), takze helper hledame
# na vsech mistech, kde muze byt, a kdyz chybi, dotahneme ho z repa. Kdyz ani to
# nejde, koncime HLASITE - nikdy nepokracujeme s poloprazdnou konfiguraci.
$EnvLoader = @(
    (Join-Path $PSScriptRoot 'lib\env-load.ps1'),
    (Join-Path $ProjectRoot  'cmd\lib\env-load.ps1'),
    (Join-Path $PSScriptRoot 'env-load.ps1')
) | Where-Object { Test-Path -LiteralPath $_ } | Select-Object -First 1
if (-not $EnvLoader) {
    Write-Host "==> Stahuji helper env-load.ps1 (parser .env)..."
    $EnvLoader = Join-Path $PSScriptRoot 'env-load.ps1'
    try {
        Invoke-WebRequest -UseBasicParsing `
            -Uri 'https://raw.githubusercontent.com/radekhulan/myucto/master/cmd/lib/env-load.ps1' `
            -OutFile $EnvLoader
    } catch {
        Remove-Item -LiteralPath $EnvLoader -ErrorAction SilentlyContinue
        Write-Error ("Nepodarilo se ziskat cmd\lib\env-load.ps1 ($($_.Exception.Message)). " +
                     "Stahni jej rucne vedle tohoto skriptu z " +
                     "https://raw.githubusercontent.com/radekhulan/myucto/master/cmd/lib/env-load.ps1")
    }
}
. $EnvLoader

if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
    Write-Error "docker not found in PATH"
}
& docker compose version > $null 2>&1
if ($LASTEXITCODE -ne 0) {
    Write-Error "'docker compose' (v2) plugin required - install Docker Desktop"
}
if (-not (Test-Path $ComposeFile)) {
    Write-Error "$ComposeFile not found in $ProjectRoot"
}

function Invoke-Compose {
    & docker compose -f $ComposeFile @args
}

# Zapise soubor jako UTF-8 BEZ BOM. `Set-Content -Encoding UTF8` pise BOM ve
# Windows PowerShellu 5.1; BOM pred '<?php' v cfg.docker.php se vypise na vystup
# jeste pred hlavickami a znemozni odeslani session cookie (= prihlaseni projde,
# ale aplikace pak uzivatele posila zpatky na /login).
function Write-Utf8NoBom([string]$Path, [string]$Content) {
    $full = if ([System.IO.Path]::IsPathRooted($Path)) { $Path } else { Join-Path (Get-Location).Path $Path }
    [System.IO.File]::WriteAllText($full, $Content, (New-Object System.Text.UTF8Encoding $false))
}

function Get-ComposeProjectName([string]$root) {
    if ($env:COMPOSE_PROJECT_NAME) { return $env:COMPOSE_PROJECT_NAME }
    return ((Split-Path -Leaf $root).ToLower() -replace '[^a-z0-9_-]', '')
}

# Vrati $true, kdyz hostovy port $Port drzi CIZI (ne-myucto) kontejner/proces.
# $EnvVar jen upresni hlasku, aby radila spravnou promennou v .env.
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

# Najde prvni volny hostovy port od $Start vys (max 40 pokusu).
function Find-FreePort([int]$Start, [string]$OurProject, [string]$EnvVar) {
    for ($p = $Start; $p -lt ($Start + 40); $p++) {
        $busy = $false
        $lines = & docker ps --format '{{.Ports}}' 2>$null
        foreach ($ln in $lines) { if ($ln -match ":$p->") { $busy = $true; break } }
        if (-not $busy) {
            try { if (Get-NetTCPConnection -LocalPort $p -State Listen -ErrorAction Stop) { $busy = $true } } catch {}
        }
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

function Get-AppLogTail([int]$Lines = 40) {
    return (Invoke-Compose logs --no-color --tail $Lines app 2>&1 | Out-String)
}

function Test-AppNetworkBroken([string]$Logs) {
    return [bool]($Logs -match 'getaddrinfo (for )?db failed|getaddrinfo failed|php_network_getaddresses|Temporary failure in name resolution|Name or service not known')
}

# Smart: pokud uz app bezi, tohle je spis update nez cersta instalace.
$runningImage = (& docker ps --filter 'label=com.docker.compose.service=app' --format '{{.Image}}' 2>$null |
    Where-Object { $_ -match 'myucto' } | Select-Object -First 1)
if ($runningImage) {
    Write-Host "==> Pozn.: app uz bezi (image '$runningImage'). Pro pouhou aktualizaci pouzij cmd\docker-update.ps1."
    Write-Host "    (tenhle skript je idempotentni - klidne pokracuj, jen prepulluje a nahodi znovu)"
}

function New-RandomToken([int]$Bytes = 24) {
    $buf = New-Object byte[] $Bytes
    [System.Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($buf)
    return ([Convert]::ToBase64String($buf) -replace '[+/=]', '').Substring(0, [math]::Min($Bytes + 4, 32))
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
$envVars = Read-DotEnvFile -Path '.env'

# --- 2. cfg.docker.php ----------------------------------------------------
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

# --- 3. pull image from GHCR ---------------------------------------------
Write-Host "==> Pulling image from GHCR..."
Invoke-Compose pull app
if ($LASTEXITCODE -ne 0) { Write-Error "docker compose pull failed" }

# --- 3b. pre-flight: hostovy port aplikace -------------------------------
# Kolize portu (stary/half-created app kontejner) -> novy app se nepripoji k
# siti ('port already allocated') -> nepreklada 'db' -> migrace v cyklu padaji.
$ourProject = Get-ComposeProjectName $ProjectRoot
$appPort = 0; [void][int]::TryParse(("" + $envVars.APP_PORT), [ref]$appPort)
if ($appPort -le 0) { $appPort = 8080 }
Write-Host "==> Pre-flight: kontrola hostoveho portu $appPort..."
if (Test-ForeignPortHolder $appPort $ourProject 'APP_PORT') {
    Write-Error "Host port $appPort je obsazeny cizim procesem/kontejnerem (viz vyse) - uvolni ho nebo zmen APP_PORT v .env a spust znovu."
}

# --- 3c. pre-flight: hostovy port databaze -------------------------------
# Db se mapuje na loopback jen kvuli pripojeni klientem zvenci. Souzeni MyInvoice
# a MyUcta na jednom stroji je bezny scenar (prevod dat), takze kolize na 3307 je
# ocekavatelna a nema kvuli ni instalace padat: port jen posuneme a zapiseme do
# .env, aby volba prezila dalsi spusteni. Aplikace na nem nezavisi - uvnitr site
# si saha na 'db:3306'.
$dbPort = 0; [void][int]::TryParse(("" + $envVars.DB_PORT), [ref]$dbPort)
if ($dbPort -le 0) { $dbPort = 3307 }
Write-Host "==> Pre-flight: kontrola hostoveho portu databaze $dbPort..."
if (Test-ForeignPortHolder $dbPort $ourProject 'DB_PORT') {
    $free = Find-FreePort ($dbPort + 1) $ourProject 'DB_PORT'
    if ($free -le 0) {
        Write-Error "Host port $dbPort je obsazeny a v rozsahu $($dbPort+1)..$($dbPort+40) nenasel volny - uvolni port nebo zmen DB_PORT v .env rucne."
    }
    Write-Host "    Prepinam DB_PORT $dbPort -> $free a zapisuji do .env." -ForegroundColor Yellow
    Set-EnvValue 'DB_PORT' "$free"
    $dbPort = $free
}

# --- 4. up databaze ------------------------------------------------------
# --remove-orphans: uklidi stale kontejnery z jineho compose souboru.
Write-Host "==> Starting database..."
Invoke-Compose up -d --remove-orphans db
if ($LASTEXITCODE -ne 0) { Write-Error "docker compose up (db) failed" }

# --- 5. wait for DB health -----------------------------------------------
Write-Host "==> Waiting for database to become healthy..."
$ready = $false
for ($i = 1; $i -le 45; $i++) {
    $json = Invoke-Compose ps --format json db 2>$null
    if ($json -match '"Health":"healthy"') { $ready = $true; Write-Host "    DB ready."; break }
    if ($json -match '"Health":"unhealthy"') { Write-Warning "DB hlasi 'unhealthy' - cekam dal (attempt $i/45)..." }
    Start-Sleep -Seconds 2
}
if (-not $ready) {
    Write-Error "DB failed to become healthy in ~90s. Check 'docker compose -f $ComposeFile logs db'."
}

# --- 4b. up app ----------------------------------------------------------
Write-Host "==> Starting app..."
Invoke-Compose up -d --remove-orphans app
if ($LASTEXITCODE -ne 0) { Write-Error "docker compose up (app) failed" }

# Migrace se spousti automaticky z docker-entrypoint.sh pred apache2-foreground.
# Misto druheho explicitniho migrate (= race condition s entrypointem, viz issue
# s duplicate PK v `migrations` tabulce) jen cekame, az app odpovi na HTTP.
# Pouzivame /api/health - je v ALLOWED_PATHS pro FirstRunLockMiddleware, takze
# vraci 200 i ve fresh-install state (kdy /api/version dostane 423 Locked).
# Pouzivame curl.exe (shipped s Windows 10/11 v C:\Windows\System32\curl.exe),
# protoze Invoke-WebRequest se na Windows v polling loopu chova nepredvidatelne
# (pomale, error handling jine nez curl, navic catch{} skryval diagnostiku).
# Zarovnano s docker-ghcr.sh - stejna sematika `curl -fsS` (200 = ok, jinak fail).
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
$ready = $false
$lastErr = ''
$recovered = $false
for ($i = 1; $i -le 90; $i++) {
    $out = & $curl -fsS -m 3 -o NUL "http://localhost:$appPort/api/health" 2>&1
    if ($LASTEXITCODE -eq 0) { $ready = $true; Write-Host "    App ready."; break }
    $lastErr = ($out | Out-String).Trim()
    if (($i % 5) -eq 0) {
        $logs = Get-AppLogTail 40
        if (-not $recovered -and (Test-AppNetworkBroken $logs)) {
            Write-Warning "App bezi, ale nema compose sit (DNS 'db' selhava) -> auto-recovery: force-recreate app."
            Invoke-Compose up -d --remove-orphans --force-recreate app 2>&1 | Out-Null
            $recovered = $true
            Start-Sleep -Seconds 3
            continue
        }
        elseif ($logs -match 'Migration attempt') { Write-Host "    ...migrace bezi (attempt $i/90)" }
    }
    Start-Sleep -Seconds 2
}
if (-not $ready) {
    Write-Host "    Last curl error: $lastErr" -ForegroundColor Yellow
    Write-Host "    --- posledni radky logu app ---" -ForegroundColor Yellow
    Write-Host (Get-AppLogTail 25)
    Write-Error "App failed to respond in time. Check 'docker compose -f $ComposeFile logs app'."
}

# --- 6. report -----------------------------------------------------------
$port = $envVars.APP_PORT
if (-not $port) { $port = '8080' }
Write-Host ""
Write-Host "============================================================"
Write-Host " MyUcto.cz is up at:  http://localhost:$port"
Write-Host " Image:                  ghcr.io/radekhulan/myucto:latest"
Write-Host ""
Write-Host " The browser will land on the setup wizard:"
Write-Host "   1. Admin user (name, email, password >= 12 chars)"
Write-Host "   2. Supplier (IC -> Nacist z ARES -> bank account)"
Write-Host "   3. Optional sample data"
Write-Host ""
Write-Host " Useful (-f $ComposeFile flag is needed for all compose calls):"
Write-Host "   docker compose -f $ComposeFile logs -f app"
Write-Host "   docker compose -f $ComposeFile pull; docker compose -f $ComposeFile up -d   # update"
Write-Host "   docker compose -f $ComposeFile down           # stop (data persists)"
Write-Host "   docker compose -f $ComposeFile down -v        # stop + WIPE volumes"
Write-Host "============================================================"
