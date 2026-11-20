#!/usr/bin/env bash
# MyUcto.cz — Docker upgrade watcher.
#
# Sleduje storage/upgrade-requested.json **uvnitř** kontejneru (přes
# `docker compose exec`) a když ho UI vytvoří (POST /api/admin/update/
# trigger), spustí docker-update.sh a výsledek zapíše zpět do containeru
# do storage/upgrade-result.json. UI to v Systém → Aktualizace zobrazí
# jako „aplikováno / selhalo".
#
# Storage je Docker named volume (ne bind-mount), takže host watcher
# musí na flag soubor sahat přes `exec`. Tohle je oprava bugu v3.0.0/3.0.1
# kdy watcher na hostu neviděl flag uvnitř volume.
#
# Provoz:
#   - Pust jako systemd unit, supervisord, nebo "while true; do" smyčku
#     v session přihlášené k host shellu.
#
# Příklad systemd unit (/etc/systemd/system/myucto-update-watcher.service):
#
#   [Unit]
#   Description=MyUcto update watcher
#   After=docker.service
#
#   [Service]
#   Type=simple
#   WorkingDirectory=/opt/myucto
#   ExecStart=/opt/myucto/cmd/docker-update-watcher.sh
#   Restart=always
#
#   [Install]
#   WantedBy=multi-user.target
#
# Idempotent — flag se zpracovává jednou (move před spuštěním).

set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$PROJECT_ROOT"

INTERVAL_S="${MYINVOICE_WATCHER_INTERVAL:-30}"

# Detect compose file — preferuj production.yml pokud existuje a má běžící stack.
#
# Ask compose for the container id instead of grepping the human-readable table:
# older Compose v2 printed "running" in the STATUS column, newer releases print
# the docker-style "Up 2 weeks", so the grep silently stopped matching and the
# watcher always fell back to the default compose file.
COMPOSE_ARGS=()
if [[ -f docker-compose.production.yml ]] \
   && [[ -n "$(docker compose -f docker-compose.production.yml ps --status running -q app 2>/dev/null)" ]]; then
    COMPOSE_ARGS=("-f" "docker-compose.production.yml")
fi

dc() { docker compose "${COMPOSE_ARGS[@]}" "$@"; }

# Storage cesta v kontejneru — od 3.6.0 single-volume default je `/data/storage`,
# starší 3-volume layout používá WORKDIR-relative `storage`. Detekujeme přes ENV.
detect_storage_dir() {
    local data_dir
    data_dir="$(dc exec -T app printenv MYINVOICE_DATA_DIR 2>/dev/null | tr -d '\r' || true)"
    if [[ -n "$data_dir" ]]; then
        echo "${data_dir}/storage"
    else
        echo "storage"
    fi
}

STORAGE_DIR=""

echo "[watcher] start, polling upgrade-requested.json inside container every ${INTERVAL_S}s"
echo "[watcher] compose: ${COMPOSE_ARGS[*]:-<default docker-compose.yml>}"

while true; do
    # Lazy-init storage path — kontejner nemusí běžet hned při startu watcheru.
    if [[ -z "$STORAGE_DIR" ]]; then
        STORAGE_DIR="$(detect_storage_dir)"
        [[ -n "$STORAGE_DIR" ]] && echo "[watcher] storage dir in container: ${STORAGE_DIR}"
    fi

    if dc exec -T app test -f "${STORAGE_DIR}/upgrade-requested.json" 2>/dev/null; then
        FLAG_JSON="$(dc exec -T app cat "${STORAGE_DIR}/upgrade-requested.json" 2>/dev/null || echo '{}')"
        TARGET="$(printf '%s' "$FLAG_JSON" \
            | grep -oE '"target_version"[[:space:]]*:[[:space:]]*"[^"]+"' \
            | head -1 \
            | sed -E 's/.*"target_version"[[:space:]]*:[[:space:]]*"([^"]+)".*/\1/' \
            || true)"
        TARGET="${TARGET:-latest}"

        echo "[watcher] $(date -u +%FT%TZ) upgrade requested → ${TARGET}"

        # Lock: přejmenuj uvnitř kontejneru — vyhne se double-trigger
        dc exec -T app mv -f "${STORAGE_DIR}/upgrade-requested.json" "${STORAGE_DIR}/upgrade-inflight.json" 2>/dev/null || true

        LOG="/tmp/myucto-upgrade-$(date -u +%Y%m%dT%H%M%SZ).log"
        if MYINVOICE_TARGET_VERSION="$TARGET" bash "$PROJECT_ROOT/cmd/docker-update.sh" >"$LOG" 2>&1; then
            STATUS="applied"
            MESSAGE="Upgrade dokončen. Log na hostu: ${LOG}"
            echo "[watcher] OK"
        else
            RC=$?
            STATUS="failed"
            # Do UI posíláme i DŮVOD, ne jen cestu k logu na hostu (do které se
            # uživatel z prohlížeče stejně nedostane) — jinak vypadá selhání jako
            # „nedokončená aktualizace" bez vysvětlení (issue #14).
            REASON="$(grep -a 'ERROR' "$LOG" 2>/dev/null | tail -n 3 | awk '{ printf "%s | ", $0 }' || true)"
            if [[ -z "$REASON" ]]; then
                REASON="$(tail -n 5 "$LOG" 2>/dev/null | awk '{ printf "%s | ", $0 }' || true)"
            fi
            REASON="$(printf '%s' "$REASON" | tr -d '\000-\037' | cut -c1-400)"
            MESSAGE="Upgrade selhal (exit ${RC}). ${REASON}Log na hostu: ${LOG}"
            echo "[watcher] FAILED (rc=${RC}). Viz ${LOG}"
        fi

        # Po `docker-update.sh` se kontejner restartuje — počkej, až bude zpátky.
        for _i in $(seq 1 30); do
            if dc exec -T app true 2>/dev/null; then break; fi
            sleep 2
        done

        APPLIED_AT="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
        # Escape pro JSON — message může obsahovat /, mezery, ne ale uvozovky.
        SAFE_MSG="$(printf '%s' "$MESSAGE" | sed 's/\\/\\\\/g; s/"/\\"/g')"
        RESULT_JSON=$(printf '{"status":"%s","target_version":"%s","applied_at":"%s","message":"%s"}' \
            "$STATUS" "$TARGET" "$APPLIED_AT" "$SAFE_MSG")

        # Po recreate kontejneru se mohla změnit storage cesta (3.5.x → 3.6.0 auto-migrate).
        # Re-detekuj před zápisem výsledku.
        STORAGE_DIR="$(detect_storage_dir)"

        printf '%s' "$RESULT_JSON" \
            | dc exec -T app sh -c "cat > ${STORAGE_DIR}/upgrade-result.json" \
            || echo "[watcher] WARN: nelze zapsat upgrade-result.json"
        dc exec -T app rm -f "${STORAGE_DIR}/upgrade-inflight.json" 2>/dev/null || true
    fi
    sleep "$INTERVAL_S"
done
