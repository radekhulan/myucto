#!/usr/bin/env bash
# =============================================================================
#  cron-scheduled-pohoda-export.sh — plánovaný export vydaných dokladů do Pohody
#  (XML e-mailem účetní). Frekvence: 1× denně, doporučeno 06:30.
#
#  crontab:  30 6 * * *  /var/www/myucto.cz/cmd/cron-scheduled-pohoda-export.sh
# =============================================================================
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
PHP_BIN="${MYINVOICE_PHP_BIN:-php}"
LOG_DIR="${MYINVOICE_DATA_DIR:-$PROJECT_ROOT}/log/cron"
mkdir -p "$LOG_DIR"
exec "$PHP_BIN" "$PROJECT_ROOT/api/bin/cron-scheduled-pohoda-export.php" "$@" \
    >> "$LOG_DIR/scheduled-pohoda-export-$(date +%Y-%m-%d).log" 2>&1
