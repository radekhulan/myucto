# H-19 — akceptační test hardeningu nasazené instance (Apache/IIS + PHP front controller).
#
# Instance běží tak, že DOCUMENT ROOT JE KOŘEN CELÉHO BUNDLU — ve veřejném
# prostoru fyzicky leží api/, db/, cmd/, cfg.php, storage/, private/ atd.
# Jediné, co brání jejich stažení, je .htaccess (Apache) / web.config (IIS).
# Selhání téhle ochrany je TICHÉ — nic nespadne, jen jde soubor stáhnout.
# Skript proto zkouší skutečné URL a dívá se na odpověď, nikdy nečte
# konfiguraci — smlouva s hostingem slibuje ÚČINEK, ne hodnotu direktivy.
#
# Co skript ověří:
#   1. Preflight — /api/health přes SPRÁVNÝ hostname musí odpovědět 200.
#      Bez tohohle je celý zbytek báchorka: aplikace, která neběží (502/503),
#      by na sadu citlivých URL taky vrátila "cokoli-jen-ne-200" a skript by
#      tiše vyhlásil vítězství, aniž by cokoliv skutečně otestoval.
#   2. Tenantový host gate samotný — aplikace má gate na hlavičce Host:
#      požadavek, jehož Host neodpovídá app.url ani žádné aktivní doméně
#      firmy, musí dostat 421, a to i na "/" a i na přímý
#      /web/dist/index.html (přímý vstup do SPA nesmí gate obejít).
#   3. Že reverzní proxy před instancí hlavičku Host NEPŘEPISUJE — ověří se
#      přes /api/health, kde appka hlásí, jestli se resolvovaný hostname
#      requestu shoduje s nakonfigurovaným app.url.
#   4. Sadu citlivých URL (cfg.php, api/src, db/, storage/, private/, …) —
#      každá MUSÍ vrátit 403 nebo 404, nic jiného. 403 je vždy silný důkaz
#      (aktivně zablokováno). 404 je silný důkaz JEN tam, kde víme, že
#      testovaný soubor v bundlu skutečně existuje — jinak je to jen "nevíme"
#      a skript to v protokolu odliší (a vypíše, co ověřit ručně na instanci,
#      kde daný soubor prokazatelně existuje).
#
# Skript NIC nezapisuje na testovanou instanci — jen GET a HEAD.
#
# ⚠️ TŘI KONFIGURACE, JEDNA SMLOUVA. Pravidla, která tahle sada vyžaduje
# (cfg.sample.php, cfg.docker.php, VERSION, web.config, portainer-template.json
# a přípony .cmd/.ps1/.sh), musí být v .htaccess, web.config I docker/nginx.conf.
# H-19 je doplnil jen do prvních dvou a Docker instance pak vydávala /VERSION
# i /production.cmd s 200 tam, kde hosting vracel 403. Skript testuje jednu
# instanci, takže tuhle drift sám neuvidí — statickou paritu všech tří
# konfigurací hlídá SensitivePathBlockParityTest (testsuite Architecture).
# Při přidání nové citlivé cesty rozšiř seznam tady i v tom testu.
#
# Použití:
#   pwsh -File cmd/verify-instance-hardening.ps1 -InstanceHost www.myucto.cz
#   pwsh -File cmd/verify-instance-hardening.ps1 -InstanceHost www.myucto.cz -Ip 203.0.113.10
#   pwsh -File cmd/verify-instance-hardening.ps1 -InstanceHost www.myucto.cz -Json
#
# Linuxová obdoba: cmd/verify-instance-hardening.sh (drž oba v synchronizaci — AGENTS.md).

[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [string] $InstanceHost,

    [string] $Ip,
    [ValidateSet('https', 'http')]
    [string] $Scheme = 'https',
    [int] $Port,
    [switch] $Insecure,
    # RFC 2606 .invalid — hostname, který nikdy nemůže reálně existovat.
    [string] $FakeHost = 'verify-instance-hardening-probe.invalid',
    [int] $TimeoutSec = 15,
    [switch] $Json
)

[Console]::OutputEncoding = [Text.Encoding]::UTF8

if (-not $Port) {
    $Port = if ($Scheme -eq 'https') { 443 } else { 80 }
}

$curlCmd = Get-Command curl.exe -ErrorAction SilentlyContinue
if (-not $curlCmd) {
    Write-Error 'curl.exe nenalezen v PATH — bez něj skript neumí nic otestovat (Windows 10/11 ho má nativně v System32).'
    exit 2
}
$curlExe = $curlCmd.Source

$baseUrl = "${Scheme}://${InstanceHost}:${Port}"
$tempDir = [System.IO.Path]::GetTempPath()

function Get-CurlCommonArgs {
    $a = @('-s', '--max-time', "$TimeoutSec")
    if ($Insecure) { $a += '-k' }
    if ($Ip) { $a += @('--resolve', "${InstanceHost}:${Port}:${Ip}") }
    return $a
}

# Jen status kód (HEAD) — pro citlivé URL i pro host-gate probe.
function Get-HeadStatus {
    param([string] $Path, [string] $HostHeader)
    $outFile = Join-Path $tempDir ("verify-hardening-{0}.tmp" -f ([guid]::NewGuid()))
    $args = (Get-CurlCommonArgs) + @('-o', $outFile, '-w', '%{http_code}', '-I', '-H', "Host: $HostHeader", "$baseUrl$Path")
    $status = & $curlExe @args 2>$null
    Remove-Item -Path $outFile -Force -ErrorAction SilentlyContinue
    if ($LASTEXITCODE -ne 0 -or [string]::IsNullOrWhiteSpace($status)) { return '000' }
    return $status.Trim()
}

# Status kód + tělo (potřeba jen pro /api/health kvůli JSON payloadu).
function Get-BodyStatus {
    param([string] $Path, [string] $HostHeader)
    $outFile = Join-Path $tempDir ("verify-hardening-{0}.tmp" -f ([guid]::NewGuid()))
    $args = (Get-CurlCommonArgs) + @('-o', $outFile, '-w', '%{http_code}', '-H', "Host: $HostHeader", "$baseUrl$Path")
    $status = & $curlExe @args 2>$null
    $body = ''
    if (Test-Path $outFile) {
        $body = Get-Content -Path $outFile -Raw -ErrorAction SilentlyContinue
        Remove-Item -Path $outFile -Force -ErrorAction SilentlyContinue
    }
    if ($LASTEXITCODE -ne 0 -or [string]::IsNullOrWhiteSpace($status)) { $status = '000' }
    return [PSCustomObject]@{ Status = $status.Trim(); Body = $body }
}

# JSON tělo z /api/health je plochý objekt s vnořenou "configuration" sekcí;
# klíče se v odpovědi neopakují, takže stačí jednoduché parsování bez závislosti na
# externím JSON parseru (ConvertFrom-Json by taky šel, ale u chybové/prázdné odpovědi
# by jen přidal další místo, kde to může spadnout).
function Get-JsonBool {
    param([string] $Body, [string] $Key)
    if ([string]::IsNullOrEmpty($Body)) { return $null }
    $m = [regex]::Match($Body, "`"$Key`"\s*:\s*(true|false)")
    if (-not $m.Success) { return $null }
    return $m.Groups[1].Value
}

# ---------------------------------------------------------------------------
# Výsledky se sbírají do seznamu objektů pro text i JSON výstup zároveň.
# ---------------------------------------------------------------------------
$rows = New-Object System.Collections.Generic.List[object]
$failCount = 0
$aborted = $false
$abortReason = ''

function Add-Row {
    param([string]$Section, [string]$Label, [string]$Expected, [string]$Got, [string]$Verdict, [string]$Evidence, [string]$Note)
    $rows.Add([PSCustomObject]@{
        Section  = $Section
        Label    = $Label
        Expected = $Expected
        Got      = $Got
        Verdict  = $Verdict
        Evidence = $Evidence
        Note     = $Note
    }) | Out-Null
    if ($Verdict -ne 'PASS' -and $Verdict -ne 'WARN') {
        $script:failCount++
    }
}

# ---------------------------------------------------------------------------
# 1) Preflight — appka musí žít, jinak je celý test bezcenný.
# ---------------------------------------------------------------------------
$preflight = Get-BodyStatus -Path '/api/health' -HostHeader $InstanceHost

if ($preflight.Status -ne '200') {
    $aborted = $true
    $abortReason = "Preflight selhal: GET /api/health přes Host: $InstanceHost vrátil $($preflight.Status) (očekáváno 200). Appka pravděpodobně neběží, nebo Host neodpovídá app.url / žádné aktivní doméně firmy — bez fungující instance je test bezcenný, dál se nepokračuje."
    Add-Row 'preflight' "GET /api/health (Host: $InstanceHost)" '200' $preflight.Status 'FAIL' '-' $abortReason
} else {
    Add-Row 'preflight' "GET /api/health (Host: $InstanceHost)" '200' $preflight.Status 'PASS' '-' 'Instance žije, pokračuji.'
}

# ---------------------------------------------------------------------------
# 2) Proxy Host nesmí přepisovat — /api/health hlásí shodu se svým app.url.
# ---------------------------------------------------------------------------
if (-not $aborted) {
    $matches_ = Get-JsonBool -Body $preflight.Body -Key 'app_url_matches_host'
    $configured = Get-JsonBool -Body $preflight.Body -Key 'app_url_configured'
    switch ($matches_) {
        'true' {
            Add-Row 'proxy' "app_url_matches_host (Host: $InstanceHost)" 'true' 'true' 'PASS' 'STRONG' 'Proxy nepřepisuje Host.'
        }
        'false' {
            Add-Row 'proxy' "app_url_matches_host (Host: $InstanceHost)" 'true' 'false' 'FAIL' 'STRONG' 'Appka vidí jiný hostname, než jsme poslali — reverzní proxy pravděpodobně přepisuje Host, nebo app.url neodpovídá testovanému hostname. Ověřit ručně.'
        }
        default {
            $cfg = if ($configured) { $configured } else { '?' }
            Add-Row 'proxy' "app_url_matches_host (Host: $InstanceHost)" 'true' 'null/chybí' 'WARN' '-' "Nelze ověřit — app.url zřejmě není routing_compatible (app_url_configured=$cfg). Zkontrolovat konfiguraci app.url ručně."
        }
    }
}

# ---------------------------------------------------------------------------
# 3) Tenantový host gate — neznámý Host musí dostat 421, a to na API, na "/"
#    i na přímý /web/dist/index.html (přímý vstup nesmí gate obejít).
#    POZOR: /api/health je z gate schválně vyjmutý (monitoring přes IP musí
#    fungovat i s neznámým Host), proto se pro API probe použije jiná cesta.
# ---------------------------------------------------------------------------
if (-not $aborted) {
    # /api/health hlásí i to, jestli je gate v konfiguraci vůbec zapnutý
    # (isEnabled() && app.url isConfigured()). Bez tohohle by 404 místo 421
    # níže vypadalo jako chyba skriptu — přitom to může být legitimně vypnutý
    # gate na dev/staging instanci (typicky mimo produkci).
    $hostGateEnforced = Get-JsonBool -Body $preflight.Body -Key 'host_gate_enforced'
    $gateDisabledNote = ''
    if ($hostGateEnforced -eq 'false') {
        $gateDisabledNote = ' [POZOR: /api/health hlásí host_gate_enforced=false — gate je v konfiguraci VYPNUTÝ (tenant.domains disabled, nebo app.url nenastavené), proto 421 nepřijde bez ohledu na .htaccess/web.config. Před produkčním nasazením je nutné gate zapnout.]'
        Add-Row 'host_gate' 'host_gate_enforced (dle /api/health)' 'true' 'false' 'WARN' '-' 'Gate je v konfiguraci vypnutý — testy níže proto nemohou vrátit 421, i kdyby webserver i appka jinak fungovaly správně.'
    }

    $gateTargets = @(
        @{ Path = '/api/__verify-instance-hardening-gate-probe__'; Label = 'API (neexistující route — gate běží PŘED routováním)' }
        @{ Path = '/'; Label = 'kořen' }
        @{ Path = '/web/dist/index.html'; Label = 'přímý vstup SPA (nesmí obejít gate)' }
        @{ Path = '/web/dist/'; Label = 'adresář web/dist (nesmí obejít gate)' }
    )
    foreach ($t in $gateTargets) {
        $status = Get-HeadStatus -Path $t.Path -HostHeader $FakeHost
        if ($status -eq '421') {
            Add-Row 'host_gate' "HEAD $($t.Path) (Host: $FakeHost)" '421' $status 'PASS' 'STRONG' $t.Label
        } else {
            Add-Row 'host_gate' "HEAD $($t.Path) (Host: $FakeHost)" '421' $status 'FAIL' 'STRONG' "$($t.Label) — gate neodmítl neznámý Host, jak má.$gateDisabledNote"
        }
    }

    # Sanity: se SPRÁVNÝM Host by 421 dostat NEMĚLA (jinak by test výše mohl
    # procházet i tehdy, když appka vrací 421 na úplně všechno).
    $sanity = Get-HeadStatus -Path '/' -HostHeader $InstanceHost
    if ($sanity -eq '421') {
        Add-Row 'host_gate' "HEAD / (Host: $InstanceHost, sanity)" '≠421' '421' 'FAIL' 'STRONG' 'Se SPRÁVNÝM Host appka taky vrací 421 — buď je gate rozbitý (blokuje i legitimní hostname), nebo testovaný -InstanceHost neodpovídá app.url.'
    } else {
        Add-Row 'host_gate' "HEAD / (Host: $InstanceHost, sanity)" '≠421' $sanity 'PASS' 'STRONG' 'Se správným Host gate nezasahuje.'
    }
}

# ---------------------------------------------------------------------------
# 4) Sada citlivých URL — 403 nebo 404, nic jiného.
# ---------------------------------------------------------------------------
$sensitiveUrls = @(
    @{ Path = '/cfg.php'; Category = 'cfg'; ExpectExists = $true; Note = '' }
    @{ Path = '/cfg.local.php'; Category = 'cfg'; ExpectExists = $true; Note = '' }
    @{ Path = '/cfg.sample.php'; Category = 'blokovaný soubor'; ExpectExists = $true; Note = '' }
    @{ Path = '/cfg.docker.php'; Category = 'blokovaný soubor'; ExpectExists = $true; Note = '' }
    @{ Path = '/api/src/Bootstrap.php'; Category = 'blokovaná složka'; ExpectExists = $true; Note = '' }
    @{ Path = '/api/vendor/autoload.php'; Category = 'blokovaná složka'; ExpectExists = $true; Note = '' }
    @{ Path = '/api/tests/bootstrap.php'; Category = 'blokovaná složka'; ExpectExists = $true; Note = '' }
    @{ Path = '/api/bin/MyInvoiceMigrate.php'; Category = 'blokovaná složka'; ExpectExists = $true; Note = '' }
    @{ Path = '/api/templates/invoice/invoice.twig'; Category = 'blokovaná složka'; ExpectExists = $true; Note = '' }
    @{ Path = '/db/'; Category = 'blokovaná složka'; ExpectExists = $true; Note = '' }
    @{ Path = '/db/migrations/0001_init.sql'; Category = 'blokovaná složka'; ExpectExists = $true; Note = '' }
    @{ Path = '/log/'; Category = 'blokovaná složka'; ExpectExists = $false; Note = 'Runtime adresář, na čerstvé instanci může být prázdný/neexistovat' }
    @{ Path = '/storage/'; Category = 'blokovaná složka'; ExpectExists = $true; Note = '' }
    @{ Path = '/private/'; Category = 'blokovaná složka'; ExpectExists = $false; Note = '.gitignore adresář, nemusí být na hostingu vůbec nasazený' }
    @{ Path = '/tools/export-pdf.ps1'; Category = 'blokovaná složka'; ExpectExists = $true; Note = '' }
    @{ Path = '/tools/export-pdf.sh'; Category = 'blokovaná složka'; ExpectExists = $true; Note = '' }
    @{ Path = '/cmd/audit-gate.sh'; Category = 'blokovaná složka'; ExpectExists = $true; Note = '' }
    @{ Path = '/cmd/audit-gate.ps1'; Category = 'blokovaná složka'; ExpectExists = $true; Note = '' }
    @{ Path = '/docker/entrypoint-alpine.sh'; Category = 'blokovaná složka'; ExpectExists = $true; Note = '' }
    @{ Path = '/.git/config'; Category = 'VCS'; ExpectExists = $false; Note = 'Deploy by .git neměl kopírovat — ověřit ručně tam, kde náhodou existuje' }
    @{ Path = '/.git/HEAD'; Category = 'VCS'; ExpectExists = $false; Note = 'Deploy by .git neměl kopírovat — ověřit ručně tam, kde náhodou existuje' }
    @{ Path = '/.env'; Category = 'blokovaný soubor'; ExpectExists = $false; Note = '.env je Docker-only konvence, na klasickém hostingu nemusí existovat' }
    @{ Path = '/api/composer.json'; Category = 'blokovaný soubor'; ExpectExists = $true; Note = '' }
    @{ Path = '/api/composer.lock'; Category = 'blokovaný soubor'; ExpectExists = $true; Note = '' }
    @{ Path = '/api/phpunit.xml'; Category = 'blokovaný soubor'; ExpectExists = $true; Note = '' }
    @{ Path = '/web/package.json'; Category = 'blokovaný soubor'; ExpectExists = $true; Note = '' }
    @{ Path = '/web/pnpm-lock.yaml'; Category = 'blokovaný soubor'; ExpectExists = $true; Note = '' }
    @{ Path = '/composer.json'; Category = 'blokovaný soubor'; ExpectExists = $false; Note = 'Reálný soubor je api/composer.json, root varianta je jen ze zadání' }
    @{ Path = '/package.json'; Category = 'blokovaný soubor'; ExpectExists = $false; Note = 'Reálný soubor je web/package.json, root varianta je jen ze zadání' }
    @{ Path = '/pnpm-lock.yaml'; Category = 'blokovaný soubor'; ExpectExists = $false; Note = 'Reálný soubor je web/pnpm-lock.yaml, root varianta je jen ze zadání' }
    @{ Path = '/phpunit.xml'; Category = 'blokovaný soubor'; ExpectExists = $false; Note = 'Reálný soubor je api/phpunit.xml, root varianta je jen ze zadání' }
    @{ Path = '/Dockerfile'; Category = 'blokovaný soubor'; ExpectExists = $true; Note = '' }
    @{ Path = '/docker-compose.yml'; Category = 'blokovaný soubor'; ExpectExists = $true; Note = '' }
    @{ Path = '/VERSION'; Category = 'blokovaný soubor'; ExpectExists = $true; Note = '' }
    @{ Path = '/web.config'; Category = 'blokovaný soubor'; ExpectExists = $true; Note = 'IIS chrání .config defaultně samo, Apache a nginx až přidaným pravidlem' }
    @{ Path = '/portainer-template.json'; Category = 'blokovaný soubor'; ExpectExists = $true; Note = '' }
    @{ Path = '/README.md'; Category = 'blokovaný soubor'; ExpectExists = $true; Note = '' }
    @{ Path = '/AGENTS.md'; Category = 'blokovaný soubor'; ExpectExists = $true; Note = '' }
    @{ Path = '/demo.cmd'; Category = 'blokovaná přípona'; ExpectExists = $true; Note = 'Přípony .cmd/.ps1/.sh mimo už chráněné složky' }
    @{ Path = '/production.cmd'; Category = 'blokovaná přípona'; ExpectExists = $true; Note = 'Viz výše' }
    @{ Path = '/docker-entrypoint.sh'; Category = 'blokovaná přípona'; ExpectExists = $true; Note = 'Viz výše' }
)

if (-not $aborted) {
    foreach ($u in $sensitiveUrls) {
        $status = Get-HeadStatus -Path $u.Path -HostHeader $InstanceHost
        $label = "$($u.Path) [$($u.Category)]"
        if ($status -eq '403') {
            Add-Row 'sensitive' $label '403/404' $status 'PASS' 'STRONG' $u.Note
        } elseif ($status -eq '404') {
            if ($u.ExpectExists) {
                Add-Row 'sensitive' $label '403/404' $status 'PASS' 'STRONG' $u.Note
            } else {
                Add-Row 'sensitive' $label '403/404' $status 'PASS' 'WEAK' "404, ale neznáme jistě, že soubor na instanci existuje — nejde odlišit 'zablokováno' od 'neexistuje'. $($u.Note)"
            }
        } else {
            Add-Row 'sensitive' $label '403/404' $status 'FAIL' 'STRONG' $u.Note
        }
    }
}

# ---------------------------------------------------------------------------
# Výstup
# ---------------------------------------------------------------------------
$overall = if ($aborted -or $failCount -gt 0) { 'FAIL' } else { 'PASS' }

if ($Json) {
    $payload = [PSCustomObject]@{
        host        = $InstanceHost
        scheme      = $Scheme
        port        = $Port
        fake_host   = $FakeHost
        aborted     = $aborted
        abort_reason = $abortReason
        overall     = $overall
        fail_count  = $failCount
        checks      = $rows | ForEach-Object {
            [PSCustomObject]@{
                section  = $_.Section
                check    = $_.Label
                expected = $_.Expected
                got      = $_.Got
                verdict  = $_.Verdict
                evidence = $_.Evidence
                note     = $_.Note
            }
        }
    }
    $payload | ConvertTo-Json -Depth 5 -Compress
} else {
    Write-Host "=== H-19 — akceptační test hardeningu instance ==="
    Write-Host "Host:  $InstanceHost   Scheme: $Scheme   Port: $Port"
    if ($Ip) { Write-Host "IP:    $Ip (--resolve)" }
    Write-Host "Fake Host pro test gate: $FakeHost"
    Write-Host ''

    $lastSection = ''
    foreach ($r in $rows) {
        if ($r.Section -ne $lastSection) {
            Write-Host "--- $($r.Section) ---"
            $lastSection = $r.Section
        }
        $color = switch ($r.Verdict) { 'PASS' { 'Green' } 'WARN' { 'Yellow' } default { 'Red' } }
        $evidenceSuffix = if ($r.Evidence -ne '-') { " ($($r.Evidence))" } else { '' }
        Write-Host ("[{0,-4}] {1,-60} očekáváno={2,-8} dostalo={3,-6}{4}" -f $r.Verdict, $r.Label, $r.Expected, $r.Got, $evidenceSuffix) -ForegroundColor $color
        if ($r.Note) { Write-Host "        $($r.Note)" }
    }

    Write-Host ''
    if ($aborted) {
        Write-Host "PŘERUŠENO: $abortReason" -ForegroundColor Red
    }
    Write-Host "=== Výsledek: $overall (selhání: $failCount) ===" -ForegroundColor $(if ($overall -eq 'PASS') { 'Green' } else { 'Red' })
}

exit $(if ($overall -eq 'PASS') { 0 } else { 1 })
