#!/usr/bin/env bash
# =============================================================================
#  cron-license-renew.sh - pravidelná obnova licenčního tokenu (E4)
#  Frekvence: 1× za hodinu. Služba volá server běžně max. 1× denně,
#  kolem další platby a při past_due max. 1× za hodinu.
#
#  crontab:
#    15 * * * *  /var/www/myucto.cz/cmd/cron-license-renew.sh
# =============================================================================
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
PHP_BIN="${MYINVOICE_PHP_BIN:-php}"
LOG_DIR="${MYINVOICE_DATA_DIR:-$PROJECT_ROOT}/log/cron"
mkdir -p "$LOG_DIR"
exec "$PHP_BIN" "$PROJECT_ROOT/api/bin/cron-license-renew.php" "$@" \
    >> "$LOG_DIR/license-renew-$(date +%Y-%m-%d).log" 2>&1
