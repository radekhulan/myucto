#!/usr/bin/env bash
# =============================================================================
#  cron-backup-payroll.sh — denní záloha mzdového úložiště
#  (storage/payroll-documents/, payroll-period-exports/, payroll-payment-exports/)
#  do storage/backup/{dbname}-payroll-YYYY-MM-DD.zip
#
#  Oddělené od cron-backup-documents.sh schválně: mzdové soubory leží jinde
#  a do žádné z ostatních záloh nespadaly — po obnově by zbyla metadata bez
#  obsahu, a to u dokumentů se zákonnou archivační lhůtou.
#  Frekvence: 1× denně, doporučeno 02:40 (po cron-backup-documents)
#  Retention: 30 denních + měsíční (1. v měsíci) drženy 365 dní
#
#  crontab:
#    40 2 * * *  /var/www/myucto.cz/cmd/cron-backup-payroll.sh
# =============================================================================
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
PHP_BIN="${MYINVOICE_PHP_BIN:-php}"
LOG_DIR="${MYINVOICE_DATA_DIR:-$PROJECT_ROOT}/log/cron"
mkdir -p "$LOG_DIR"
exec "$PHP_BIN" "$PROJECT_ROOT/api/bin/cron-backup-payroll.php" "$@" \
    >> "$LOG_DIR/backup-payroll-$(date +%Y-%m-%d).log" 2>&1
