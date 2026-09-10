#!/usr/bin/env bash
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
#   cmd/audit-gate.sh
#   cmd/audit-gate.sh --database=myucto_test
#   cmd/audit-gate.sh --skip-data        (jen testy, bez kroků nad daty)
#
# Windows ekvivalent: cmd/audit-gate.ps1 (drž oba v synchronizaci — AGENTS.md).

set -uo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
API_DIR="$REPO_ROOT/api"
PHP_BIN="${MYINVOICE_PHP_BIN:-php}"
SKIP_DATA=0
FAILED=()

for arg in "$@"; do
    case "$arg" in
        --database=*) export MYINVOICE_DB_NAME="${arg#*=}" ;;
        --skip-data)  SKIP_DATA=1 ;;
        *) echo "Neznámý parametr: $arg" >&2; exit 2 ;;
    esac
done

# PHPUnit čte phpunit.xml z PRACOVNÍHO adresáře, ne z cesty k binárce — bez tohohle
# přepnutí nenajde konfiguraci, `--testsuite` je pro něj neznámý přepínač a vypíše
# nápovědu s exit 1. Vypadá to jako selhání testů, přitom se žádné nespustily.
run_step() {
    local name="$1"; shift
    echo
    echo "=== $name"
    if ! (cd "$API_DIR" && "$@"); then
        FAILED+=("$name")
        echo "    SELHALO"
    fi
}

run_step 'Guardy (L0)' \
    "$PHP_BIN" "$API_DIR/vendor/bin/phpunit" --no-coverage --testsuite Architecture

run_step 'Invarianty a fuzz (L3)' \
    "$PHP_BIN" "$API_DIR/vendor/bin/phpunit" --no-coverage --testsuite Invariants

run_step 'Plná testovací sada' \
    "$PHP_BIN" "$API_DIR/bin/test-parallel.php" --application

if [ "$SKIP_DATA" -eq 0 ]; then
    run_step 'Invarianty nad daty (read-only)' \
        "$PHP_BIN" "$API_DIR/bin/check-invariants.php"

    run_step 'Křížové kontroly (read-only)' \
        "$PHP_BIN" "$API_DIR/bin/cross-check.php"

    run_step 'Struktura databáze proti migracím (read-only)' \
        "$PHP_BIN" "$API_DIR/bin/check-schema.php"

    if [ -f "$REPO_ROOT/private/scripts/compare_dph.php" ]; then
        run_step 'Smír DPH proti podaným XML' \
            "$PHP_BIN" "$REPO_ROOT/private/scripts/compare_dph.php"
    else
        echo
        echo '=== Smír DPH proti podaným XML — přeskočeno (private/scripts chybí)'
    fi
fi

echo
if [ "${#FAILED[@]}" -eq 0 ]; then
    echo 'Auditní brána: VŠE PROŠLO'
    exit 0
fi

echo 'Auditní brána: SELHALO'
for f in "${FAILED[@]}"; do echo "  - $f"; done
exit 1
