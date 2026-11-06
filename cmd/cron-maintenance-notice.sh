#!/usr/bin/env bash
# =============================================================================
#  cron-maintenance-notice.sh — stažení ohlášení plánované údržby
#  Frekvence: každých 15 minut.
#
#  Stáhne hlášky provozovatele (od–do, text) do tabulky `app_meta`.
#  Aplikace je pak ukazuje v pruhu nahoře a na přihlášení.
#
#  crontab:
#    */15 * * * *  /var/www/myucto.cz/cmd/cron-maintenance-notice.sh
# =============================================================================
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
PHP_BIN="${MYINVOICE_PHP_BIN:-php}"
LOG_DIR="${MYINVOICE_DATA_DIR:-$PROJECT_ROOT}/log/cron"
mkdir -p "$LOG_DIR"
exec "$PHP_BIN" "$PROJECT_ROOT/api/bin/cron-maintenance-notice.php" "$@" \
    >> "$LOG_DIR/maintenance-notice-$(date +%Y-%m-%d).log" 2>&1
