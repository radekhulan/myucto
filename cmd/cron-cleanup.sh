#!/usr/bin/env bash
# =============================================================================
#  cron-cleanup.sh — denní úklid DB a souborů
#  Frekvence: 1× denně, doporučeno 03:00
#
#  Smaže: login_attempts >24h, expirované sessions, použité password_resets,
#         ARES/VIES cache >30 dní, PDF cache >90 dní, log files nad max_files,
#         nahrané soubory dávek skenů (opuštěné >48 h, skončené >7 dní).
#
#  crontab:
#    0 3 * * *  /var/www/myucto.cz/cmd/cron-cleanup.sh
# =============================================================================
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
PHP_BIN="${MYINVOICE_PHP_BIN:-php}"
LOG_DIR="${MYINVOICE_DATA_DIR:-$PROJECT_ROOT}/log/cron"
mkdir -p "$LOG_DIR"
exec "$PHP_BIN" "$PROJECT_ROOT/api/bin/cron-cleanup.php" "$@" \
    >> "$LOG_DIR/cleanup-$(date +%Y-%m-%d).log" 2>&1
