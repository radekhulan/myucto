#!/usr/bin/env bash
# =============================================================================
#  cron-payroll-registration-changes.sh — detekce hlásitelných změn v registru
#  pojištěnců (ČSSZ)
#  Frekvence: 1× denně 05:00
#
#  Detekce zakládá návrh povinnosti a tím rozjíždí osmidenní lhůtu
#  (§ 19 odst. 5 zákona č. 323/2025 Sb.). Bez cronu se spouští jen při otevření
#  karty zaměstnance nebo na tlačítko ve frontě podání — u stovky zaměstnanců
#  tak lhůty tiše utíkají.
#
#  Skript NIC NEODESÍLÁ. Vznikne pouze návrh; podání z něj vyrobí a odešle
#  člověk z fronty Mzdy → Podání a hlášení.
#
#  Běží jen tam, kde jsou zapnuté mzdy (supplier.payroll_enabled). Opakované
#  spuštění je bezpečné — porovnávají se jen vztahy, u kterých se pohnul
#  vodoznak zdroje (payroll_registration_change_scans.source_watermark).
#
#  Volitelné argumenty:
#    --environment=test    detekce v testovacím prostředí (default production)
#    --supplier=ID         jen jedna firma (lze opakovat)
#    --batch=N             velikost porce vztahů (default 200)
#    --max-batches=N       strop porcí na firmu a běh (default 25)
#
#  crontab (denně 05:00):
#     0 5 * * *  /var/www/myucto.cz/cmd/cron-payroll-registration-changes.sh
# =============================================================================
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
PHP_BIN="${MYINVOICE_PHP_BIN:-php}"
LOG_DIR="${MYINVOICE_DATA_DIR:-$PROJECT_ROOT}/log/cron"
mkdir -p "$LOG_DIR"
exec "$PHP_BIN" "$PROJECT_ROOT/api/bin/cron-payroll-registration-changes.php" "$@" \
    >> "$LOG_DIR/payroll-registration-changes-$(date +%Y-%m-%d).log" 2>&1
