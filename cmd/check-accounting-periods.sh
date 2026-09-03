#!/usr/bin/env bash
# =============================================================================
#  check-accounting-periods.sh — kontrola účetních období existující instalace
#
#  Bez --fix je READ-ONLY (lze pustit i proti produkci). S --fix otevře jen
#  chybějící NAVAZUJÍCÍ období (zapomenutý přelom roku); historii nikdy.
#
#  Použití:
#    ./check-accounting-periods.sh
#    ./check-accounting-periods.sh --supplier=1
#    ./check-accounting-periods.sh --fix
#
#  Návratový kód: 0 = bez nálezu, 1 = zbývá nález, 2 = chyba argumentů.
# =============================================================================
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
PHP_BIN="${MYINVOICE_PHP_BIN:-php}"
exec "$PHP_BIN" "$PROJECT_ROOT/api/bin/check-accounting-periods.php" "$@"
