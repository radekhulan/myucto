# Auditní brána — kompletní kontrola účetního jádra (fáze F8).
#
# Pouští v pořadí od nejlevnějšího k nejdražšímu, ať je zpětná vazba rychlá:
#   1. guardy (L0)            — statické, bez DB
#   2. invarianty a fuzz (L3) — fuzz je pure, invarianty nad daty vyžadují DB
#   3. plná sada PHPUnit
#   4. invarianty nad DATY    — read-only, proti zvolené databázi
#   5. křížové kontroly (L4)  — read-only, nad uzavřenými roky
#   6. smír DPH proti podaným XML — povinná brána (jen když existuje)
#
# Kroky 4–6 čtou reálná data. NIC NEZAPISUJÍ, takže je lze pustit i proti produkci.
#
# Použití:
#   pwsh -File cmd/audit-gate.ps1
#   pwsh -File cmd/audit-gate.ps1 -Database myucto_test
#   pwsh -File cmd/audit-gate.ps1 -SkipData        (jen testy, bez kroků nad daty)
#
# Linuxový ekvivalent: cmd/audit-gate.sh (drž oba v synchronizaci — AGENTS.md).

[CmdletBinding()]
param(
    [string] $Database,
    [switch] $SkipData
)

$ErrorActionPreference = 'Stop'
$repoRoot = Split-Path -Parent $PSScriptRoot
$apiDir = Join-Path $repoRoot 'api'
$PhpBin = if ($env:MYINVOICE_PHP_BIN) { $env:MYINVOICE_PHP_BIN } else { 'php' }
$failed = @()

# PHPUnit čte phpunit.xml z PRACOVNÍHO adresáře, ne z cesty k binárce — bez tohohle
# přepnutí nenajde konfiguraci, `--testsuite` je pro něj neznámý přepínač a vypíše
# nápovědu s exit 1. Vypadá to jako selhání testů, přitom se žádné nespustily.
function Invoke-Step {
    param([string] $Name, [scriptblock] $Action)

    Write-Host ''
    Write-Host "=== $Name" -ForegroundColor Cyan
    Push-Location $apiDir
    try {
        & $Action
    }
    finally {
        Pop-Location
    }
    if ($LASTEXITCODE -ne 0) {
        $script:failed += "$Name (exit $LASTEXITCODE)"
        Write-Host "    SELHALO (exit $LASTEXITCODE)" -ForegroundColor Red
    }
}

if ($Database) {
    $env:MYINVOICE_DB_NAME = $Database
}

Invoke-Step 'Guardy (L0)' {
    & $PhpBin (Join-Path $apiDir 'vendor/bin/phpunit') --no-coverage --testsuite Architecture
}

Invoke-Step 'Invarianty a fuzz (L3)' {
    & $PhpBin (Join-Path $apiDir 'vendor/bin/phpunit') --no-coverage --testsuite Invariants
}

Invoke-Step 'Plná testovací sada' {
    & $PhpBin (Join-Path $apiDir 'bin/test-parallel.php') --application
}

if (-not $SkipData) {
    Invoke-Step 'Invarianty nad daty (read-only)' {
        & $PhpBin (Join-Path $apiDir 'bin/check-invariants.php')
    }

    Invoke-Step 'Křížové kontroly (read-only)' {
        & $PhpBin (Join-Path $apiDir 'bin/cross-check.php')
    }

    Invoke-Step 'Struktura databáze proti migracím (read-only)' {
        & $PhpBin (Join-Path $apiDir 'bin/check-schema.php')
    }

    $compareDph = Join-Path $repoRoot 'private/scripts/compare_dph.php'
    if (Test-Path $compareDph) {
        Invoke-Step 'Smír DPH proti podaným XML' { & $PhpBin $compareDph }
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
        Invoke-Step 'Tvar nálezů kontrol (počet = vypsané řádky, datum, čeština)' { & $PhpBin $checksSweep }
    }
    else {
        Write-Host ''
        Write-Host '=== Tvar nálezů kontrol — přeskočeno (private/scripts chybí)' -ForegroundColor DarkGray
    }
}

Write-Host ''
if ($failed.Count -eq 0) {
    Write-Host 'Auditní brána: VŠE PROŠLO' -ForegroundColor Green
    exit 0
}

Write-Host 'Auditní brána: SELHALO' -ForegroundColor Red
$failed | ForEach-Object { Write-Host "  - $_" -ForegroundColor Red }
exit 1
