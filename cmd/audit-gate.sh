#!/usr/bin/env bash
# Auditní brána — kontrola účetního a daňového jádra před commitem (fáze F8).
#
# Kroky:
#   1. testy jádra — jeden paralelní běh sady AuditCore: guardy (L0), invarianty
#      a fuzz (L3) a testy účetní a daňové vrstvy. Bez mzdových toků, převodů z cizích
#      systémů a generátorů ukázkových dat (dvě třetiny času plné sady, hlídá je plná
#      sada a brány mezd). Padlé třídy se jednou zopakují sériově, flaky se vypíšou.
#      Prošla-li nad stejným kódem už plná sada (test-parallel.php bez přepínačů),
#      krok se přeskočí — její výsledek pokrývá vše.
#   2. invarianty nad DATY    — read-only, proti zvolené databázi
#   3. křížové kontroly (L4)  — read-only, všechny firmy s uzavřeným obdobím
#   4. struktura databáze proti migracím
#   5. smír DPH proti podaným XML a tvar nálezů kontrol (jen když skripty existují)
#
# Kroky 2–5 čtou reálná data a NIC NEZAPISUJÍ, lze je pustit i proti kopii produkce.
# U datových kontrol znamená exit 2 NEOVĚŘENO; u testů je každý nenulový kód selhání.
#
# Použití:
#   cmd/audit-gate.sh                          (data z databáze v cfg)
#   cmd/audit-gate.sh --database=myucto_kopie  (data z jiné databáze)
#   cmd/audit-gate.sh --skip-data              (jen testy)
#   cmd/audit-gate.sh --full                   (plná sada místo AuditCore)
#
# Windows ekvivalent: cmd/audit-gate.ps1 (drž oba v synchronizaci — AGENTS.md).

set -uo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
API_DIR="$REPO_ROOT/api"
PHP_BIN="${MYINVOICE_PHP_BIN:-php}"
SKIP_DATA=0
FULL=0
DATA_DB=""
FAILED=()
UNVERIFIED=()
STARTED=$SECONDS

for arg in "$@"; do
    case "$arg" in
        --database=*) DATA_DB="${arg#*=}" ;;
        --skip-data)  SKIP_DATA=1 ;;
        --full)       FULL=1 ;;
        *) echo "Neznámý parametr: $arg" >&2; exit 2 ;;
    esac
done

# PHPUnit čte phpunit.xml z PRACOVNÍHO adresáře, ne z cesty k binárce — bez tohohle
# přepnutí nenajde konfiguraci, `--testsuite` je pro něj neznámý přepínač a vypíše
# nápovědu s exit 1. Vypadá to jako selhání testů, přitom se žádné nespustily.
run_step() {
    local allow_no_data=0
    if [ "$1" = "--allow-no-data" ]; then
        allow_no_data=1
        shift
    fi
    local name="$1"; shift
    local step_start=$SECONDS
    echo
    echo "=== $name"
    (cd "$API_DIR" && "$@")
    local code=$?
    local took=$((SECONDS - step_start))
    if [ "$allow_no_data" -eq 1 ] && [ "$code" -eq 2 ]; then
        UNVERIFIED+=("$name (nebylo co kontrolovat)")
        echo "    NEOVĚŘENO — nebylo co kontrolovat (${took} s)"
    elif [ "$code" -ne 0 ]; then
        FAILED+=("$name (exit $code)")
        echo "    SELHALO (exit $code, ${took} s)"
    else
        echo "    OK (${took} s)"
    fi
}

AUDIT_CHECK=1
if [ "$FULL" -eq 0 ]; then
    "$PHP_BIN" "$API_DIR/bin/test-parallel.php" --audit-check
    AUDIT_CHECK=$?
fi
if [ "$FULL" -eq 0 ] && [ "$AUDIT_CHECK" -eq 0 ]; then
    echo '=== Testy — přeskočeno, plná sada nad stejným kódem nemá pád v jádru'
elif [ "$FULL" -eq 0 ] && [ "$AUDIT_CHECK" -eq 2 ]; then
    FAILED+=("Testy účetního a daňového jádra (AuditCore) — padly v plné sadě nad stejným kódem")
    echo '=== Testy — SELHALO v plné sadě nad stejným kódem (viz seznam výše), znovu se nespouští'
elif [ "$FULL" -eq 1 ]; then
    run_step 'Plná testovací sada' \
        "$PHP_BIN" "$API_DIR/bin/test-parallel.php" --rerun-failures
else
    run_step 'Testy účetního a daňového jádra (AuditCore)' \
        "$PHP_BIN" "$API_DIR/bin/test-parallel.php" --testsuite=AuditCore --rerun-failures
fi

if [ "$SKIP_DATA" -eq 0 ]; then
    # Databázi pro data přepínáme až tady: testovací běh si zdroj bere z téže proměnné
    # a musí zůstat na *_test databázi.
    if [ -n "$DATA_DB" ]; then
        export MYINVOICE_DB_NAME="$DATA_DB"
    fi

    run_step --allow-no-data 'Invarianty nad daty (read-only)' \
        "$PHP_BIN" "$API_DIR/bin/check-invariants.php" --quiet

    # Odsouhlasené známé rozdíly instalace (data, ne kód) — viz hlavička cross-check.php.
    ACCEPTED_DIFFS="$REPO_ROOT/private/cross-check-accepted.json"
    if [ -f "$ACCEPTED_DIFFS" ]; then
        run_step --allow-no-data 'Křížové kontroly (read-only)' \
            "$PHP_BIN" "$API_DIR/bin/cross-check.php" "--accepted=$ACCEPTED_DIFFS"
    else
        run_step --allow-no-data 'Křížové kontroly (read-only)' \
            "$PHP_BIN" "$API_DIR/bin/cross-check.php"
    fi

    run_step --allow-no-data 'Struktura databáze proti migracím (read-only)' \
        "$PHP_BIN" "$API_DIR/bin/check-schema.php"

    if [ -f "$REPO_ROOT/private/scripts/compare_dph.php" ]; then
        run_step --allow-no-data 'Smír DPH proti podaným XML' \
            "$PHP_BIN" "$REPO_ROOT/private/scripts/compare_dph.php"
    else
        echo
        echo '=== Smír DPH proti podaným XML — přeskočeno (private/scripts chybí)'
    fi

    # E-1: tvar nálezů kontrol nad OSTRÝMI daty (počet nálezů = vypsané řádky).
    if [ -f "$REPO_ROOT/private/scripts/checks_shape_sweep.php" ]; then
        run_step --allow-no-data 'Tvar nálezů kontrol (počet = vypsané řádky, datum, čeština)' \
            "$PHP_BIN" "$REPO_ROOT/private/scripts/checks_shape_sweep.php"
    else
        echo
        echo '=== Tvar nálezů kontrol — přeskočeno (private/scripts chybí)'
    fi
fi

TOTAL=$((SECONDS - STARTED))
echo
if [ "${#UNVERIFIED[@]}" -gt 0 ]; then
    echo 'NEOVĚŘENO (prázdná data — pusťte s --database nad kopií ostrých dat):'
    for u in "${UNVERIFIED[@]}"; do echo "  - $u"; done
fi
if [ "${#FAILED[@]}" -eq 0 ]; then
    echo "Auditní brána: VŠE PROŠLO (${TOTAL} s)"
    exit 0
fi

echo "Auditní brána: SELHALO (${TOTAL} s)"
for f in "${FAILED[@]}"; do echo "  - $f"; done
exit 1
