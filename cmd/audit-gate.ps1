# Auditní brána — kontrola účetního a daňového jádra před commitem (fáze F8).
#
# Kroky:
#   1. testy jádra — jeden paralelní běh sady AuditCore: guardy (L0), invarianty
#      a fuzz (L3) a testy účetní a daňové vrstvy. Bez mzdových toků, převodů z cizích
#      systémů a generátorů ukázkových dat (dvě třetiny času plné sady, hlídá je plná
#      sada a brány mezd). Padlé třídy se jednou zopakují sériově, flaky se vypíšou.
#      Proběhla-li nad stejným kódem už plná sada (test-parallel.php bez přepínačů),
#      krok se nespouští znovu: AuditCore je její podmnožina, takže brána jen přečte,
#      zda v jádru něco padlo (test-parallel.php --audit-check).
#   2. invarianty nad DATY    — read-only, proti zvolené databázi
#   3. křížové kontroly (L4)  — read-only, všechny firmy s uzavřeným obdobím
#   4. struktura databáze proti migracím
#   5. smír DPH proti podaným XML a tvar nálezů kontrol (jen když skripty existují)
#
# Kroky 2–5 čtou reálná data a NIC NEZAPISUJÍ, lze je pustit i proti kopii produkce.
# U datových kontrol znamená exit 2 NEOVĚŘENO; u testů je každý nenulový kód selhání.
#
# Použití:
#   pwsh -File cmd/audit-gate.ps1                         (data z databáze v cfg)
#   pwsh -File cmd/audit-gate.ps1 -Database myucto_kopie  (data z jiné databáze)
#   pwsh -File cmd/audit-gate.ps1 -SkipData               (jen testy)
#   pwsh -File cmd/audit-gate.ps1 -Full                   (plná sada místo AuditCore)
#
# Linuxový ekvivalent: cmd/audit-gate.sh (drž oba v synchronizaci — AGENTS.md).

[CmdletBinding()]
param(
    [string] $Database,
    [switch] $SkipData,
    [switch] $Full
)

$ErrorActionPreference = 'Stop'
$repoRoot = Split-Path -Parent $PSScriptRoot
$apiDir = Join-Path $repoRoot 'api'
$PhpBin = if ($env:MYINVOICE_PHP_BIN) { $env:MYINVOICE_PHP_BIN } else { 'php' }
$failed = @()
$unverified = @()
$started = Get-Date

# PHPUnit čte phpunit.xml z PRACOVNÍHO adresáře, ne z cesty k binárce — bez tohohle
# přepnutí nenajde konfiguraci, `--testsuite` je pro něj neznámý přepínač a vypíše
# nápovědu s exit 1. Vypadá to jako selhání testů, přitom se žádné nespustily.
function Invoke-Step {
    param([string] $Name, [scriptblock] $Action, [switch] $AllowNoData)

    Write-Host ''
    Write-Host "=== $Name" -ForegroundColor Cyan
    $stepStart = Get-Date
    Push-Location $apiDir
    try {
        & $Action
    }
    finally {
        Pop-Location
    }
    $seconds = [int] ((Get-Date) - $stepStart).TotalSeconds
    if ($AllowNoData -and $LASTEXITCODE -eq 2) {
        $script:unverified += "$Name (nebylo co kontrolovat)"
        Write-Host "    NEOVĚŘENO — nebylo co kontrolovat (${seconds} s)" -ForegroundColor Yellow
    }
    elseif ($LASTEXITCODE -ne 0) {
        $script:failed += "$Name (exit $LASTEXITCODE)"
        Write-Host "    SELHALO (exit $LASTEXITCODE, ${seconds} s)" -ForegroundColor Red
    }
    else {
        Write-Host "    OK (${seconds} s)" -ForegroundColor DarkGray
    }
}

& $PhpBin (Join-Path $apiDir 'bin/test-parallel.php') --audit-check
$auditCheck = $LASTEXITCODE
if ($auditCheck -eq 0 -and -not $Full) {
    Write-Host '=== Testy — přeskočeno, plná sada nad stejným kódem nemá pád v jádru' -ForegroundColor DarkGray
}
elseif ($auditCheck -eq 2 -and -not $Full) {
    $script:failed += 'Testy účetního a daňového jádra (AuditCore) — padly v plné sadě nad stejným kódem'
    Write-Host '=== Testy — SELHALO v plné sadě nad stejným kódem (viz seznam výše), znovu se nespouští' -ForegroundColor Red
}
elseif ($Full) {
    Invoke-Step 'Plná testovací sada' {
        & $PhpBin (Join-Path $apiDir 'bin/test-parallel.php') --rerun-failures
    }
}
else {
    Invoke-Step 'Testy účetního a daňového jádra (AuditCore)' {
        & $PhpBin (Join-Path $apiDir 'bin/test-parallel.php') --testsuite=AuditCore --rerun-failures
    }
}

if (-not $SkipData) {
    # Databázi pro data přepínáme až tady: testovací běh si zdroj bere z téže proměnné
    # a musí zůstat na *_test databázi.
    $previousDb = $env:MYINVOICE_DB_NAME
    if ($Database) {
        $env:MYINVOICE_DB_NAME = $Database
    }
    try {
        Invoke-Step -AllowNoData 'Invarianty nad daty (read-only)' {
            & $PhpBin (Join-Path $apiDir 'bin/check-invariants.php') --quiet
        }

        # Odsouhlasené známé rozdíly instalace (data, ne kód) — viz hlavička cross-check.php.
        $acceptedDiffs = Join-Path $repoRoot 'private/cross-check-accepted.json'
        Invoke-Step -AllowNoData 'Křížové kontroly (read-only)' {
            if (Test-Path $acceptedDiffs) {
                & $PhpBin (Join-Path $apiDir 'bin/cross-check.php') "--accepted=$acceptedDiffs"
            }
            else {
                & $PhpBin (Join-Path $apiDir 'bin/cross-check.php')
            }
        }

        Invoke-Step -AllowNoData 'Struktura databáze proti migracím (read-only)' {
            & $PhpBin (Join-Path $apiDir 'bin/check-schema.php')
        }

        $compareDph = Join-Path $repoRoot 'private/scripts/compare_dph.php'
        if (Test-Path $compareDph) {
            Invoke-Step -AllowNoData 'Smír DPH proti podaným XML' { & $PhpBin $compareDph }
        }
        else {
            Write-Host ''
            Write-Host '=== Smír DPH proti podaným XML — přeskočeno (private/scripts chybí)' -ForegroundColor DarkGray
        }

        # E-1: tvar nálezů kontrol nad OSTRÝMI daty. Zelená sada tuhle třídu vad nechytá —
        # kontrola, která hlásí 21 nálezů a vypíše 10, projde všemi testy, protože ty pracují
        # s dvouřádkovými zápisy a jedním nálezem. Sweep čte, nic nezapisuje.
        $checksSweep = Join-Path $repoRoot 'private/scripts/checks_shape_sweep.php'
        if (Test-Path $checksSweep) {
            Invoke-Step -AllowNoData 'Tvar nálezů kontrol (počet = vypsané řádky, datum, čeština)' { & $PhpBin $checksSweep }
        }
        else {
            Write-Host ''
            Write-Host '=== Tvar nálezů kontrol — přeskočeno (private/scripts chybí)' -ForegroundColor DarkGray
        }
    }
    finally {
        $env:MYINVOICE_DB_NAME = $previousDb
    }
}

$total = [int] ((Get-Date) - $started).TotalSeconds
Write-Host ''
if ($unverified.Count -gt 0) {
    Write-Host 'NEOVĚŘENO (prázdná data — pusťte s -Database nad kopií ostrých dat):' -ForegroundColor Yellow
    $unverified | ForEach-Object { Write-Host "  - $_" -ForegroundColor Yellow }
}
if ($failed.Count -eq 0) {
    Write-Host "Auditní brána: VŠE PROŠLO (${total} s)" -ForegroundColor Green
    exit 0
}

Write-Host "Auditní brána: SELHALO (${total} s)" -ForegroundColor Red
$failed | ForEach-Object { Write-Host "  - $_" -ForegroundColor Red }
exit 1
