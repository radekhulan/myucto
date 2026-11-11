#!/usr/bin/env bash
# =============================================================================
#  cron-payroll-end-agreements.sh — ukončení dohod po uplynutí sjednaného konce
#  Frekvence: 1× denně 00:30
#
#  DPP a DPČ se sjednaným koncem v minulosti ukončí ke dni sjednaného konce
#  stejnou cestou jako ruční akce „Ukončit skončené dohody" (kontrolní seznam
#  odchodu, auditní stopa s aktérem system). Dohodu s mzdovým vstupem,
#  docházkou nebo zúčtovaným během po konci nechá být k posouzení člověkem.
#
#  Běží jen tam, kde jsou zapnuté mzdy (supplier.payroll_enabled). Opakované
#  spuštění je bezpečné — ukončená dohoda už kandidátem není.
#
#  Volitelné argumenty:
#    --supplier=ID         jen jedna firma (lze opakovat)
#    --today=YYYY-MM-DD    rozhodný den (default dnešek)
#
#  crontab (denně 00:30):
#    30 0 * * *  /var/www/myucto.cz/cmd/cron-payroll-end-agreements.sh
# =============================================================================
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
PHP_BIN="${MYINVOICE_PHP_BIN:-php}"
LOG_DIR="${MYINVOICE_DATA_DIR:-$PROJECT_ROOT}/log/cron"
mkdir -p "$LOG_DIR"
exec "$PHP_BIN" "$PROJECT_ROOT/api/bin/cron-payroll-end-agreements.php" "$@" \
    >> "$LOG_DIR/payroll-end-agreements-$(date +%Y-%m-%d).log" 2>&1
