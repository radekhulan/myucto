#!/usr/bin/env bash
# =============================================================================
#  property-register-posted-debts.sh — doplnění dluhů k zaúčtovaným předpisům SVJ
#
#  S --dry-run je READ-ONLY a jen vypíše počty. Bez něj zaregistruje dluhy
#  předpisům zaúčtovaným před zavedením hromadné registrace; opakování je bezpečné.
#
#  Použití:
#    ./property-register-posted-debts.sh --dry-run
#    ./property-register-posted-debts.sh --supplier=1
#
#  Návratový kód: 0 = hotovo, 1 = zbývají předpisy s částečně ručními dluhy,
#  2 = chyba argumentů, 3 = chyba při registraci.
# =============================================================================
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
PHP_BIN="${MYINVOICE_PHP_BIN:-php}"
exec "$PHP_BIN" "$PROJECT_ROOT/api/bin/property-register-posted-debts.php" "$@"
