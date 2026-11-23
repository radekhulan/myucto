#!/usr/bin/env bash
# Update a running MyUcto.cz Docker stack to the latest code.
#
#   1. Pulls (registry mode) or rebuilds (source mode) the app image
#   2. Restarts the stack
#   3. Waits for DB health and runs pending migrations
#
# Detekuje režim z IMAGE běžícího app kontejneru (ne podle compose souborů):
#   - běžící registry image (ghcr.io/...) → registry mode → docker compose pull
#   - běžící lokální build (myucto:latest) → source mode → git pull + build
#   - nic neběží + lokálně je GHCR image → registry (byls GHCR deploy, jen zhasnutý)
#   - nic neběží + .git + build: → source, jinak registry
# Přebití: MYINVOICE_UPDATE_MODE=registry|source.
#
# Idempotent — safe to re-run. Volumes (DB data) persist; backup is your responsibility.
set -Eeuo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$PROJECT_ROOT"

# Žádné tiché selhání (issue #14): dřív se dalo skončit uprostřed updatu bez
# jediného řádku výstupu (např. `x=$(… | grep …)`, kde grep nic nenašel → pod
# `pipefail` nenulový exit → `set -e` ukončí skript beze slova). Trap zajistí,
# že KAŽDÝ neošetřený pád je vidět a vrací nenulový exit kód — watcher i UI to
# pak reportují jako „selhalo", ne jako nedokončenou aktualizaci.
trap 'rc=$?; {
  echo ""
  echo "ERROR: docker-update.sh selhal na řádku ${LINENO} — příkaz: ${BASH_COMMAND}"
  echo "       Exit kód ${rc}. AKTUALIZACE NEBYLA DOKONČENA."
  echo "       Oprav příčinu výše a spusť skript znovu."
} >&2; exit "$rc"' ERR

if ! command -v docker >/dev/null 2>&1; then
  echo "ERROR: docker not found in PATH" >&2; exit 1
fi
if ! docker compose version >/dev/null 2>&1; then
  echo "ERROR: 'docker compose' (v2) plugin required" >&2; exit 1
fi
if [[ ! -f .env ]]; then
  echo "ERROR: .env not found — run docker-install.sh first" >&2; exit 1
fi

# `.env` se PARSUJE, nespouští (issue #14). Dřív tu bylo `set -a; . ./.env; set +a`,
# takže hodnota s mezerou bez uvozovek (`MYINVOICE_SMTP_FROM_NAME=Jan Novak`)
# shell rozsekala a zbytek se pokusil spustit jako příkaz → update umřel hned
# tady. Navíc to byl RCE vektor (`.env` = spustitelný kód).
if [[ ! -f "${PROJECT_ROOT}/cmd/lib/env-load.sh" ]]; then
  echo "ERROR: chybí cmd/lib/env-load.sh — instalace je neúplná, dotáhni repo (git pull)." >&2
  exit 1
fi
# shellcheck source=lib/env-load.sh
. "${PROJECT_ROOT}/cmd/lib/env-load.sh"
dotenv_load ./.env

# Detect mode z IMAGE běžícího app kontejneru (autoritativní — nezávisí na tom,
# který compose file je zrovna po ruce; staré řešení podle compose souborů bylo
# křehké, protože git klon má oba soubory + kolidující název projektu).
#   - běžící image z registru (ghcr.io/...) → registry mode → `docker compose pull`
#   - běžící lokálně stavěný image (myucto:latest, bez registru) → source mode → build
#   - nic neběží → fallback podle přítomných souborů (.git + build: → source, jinak registry)
# Přebití: MYINVOICE_UPDATE_MODE=registry|source.
MODE="${MYINVOICE_UPDATE_MODE:-}"

# `|| true`: když žádný myucto kontejner neběží, grep vrátí 1 → pod `pipefail`
# by `set -e` skript ukončil TIŠE (bez jediného řádku výstupu) ještě před
# detekcí režimu. Prázdný výsledek je tady legitimní stav, ne chyba.
running_image="$(docker ps --filter label=com.docker.compose.service=app --format '{{.Image}}' 2>/dev/null | grep -i myucto | head -1 || true)"

if [[ -z "$MODE" ]]; then
  if [[ -n "$running_image" ]]; then
    case "$running_image" in
      */*) MODE="registry" ;;   # má registry/namespace → např. ghcr.io/radekhulan/myucto
      *)   MODE="source"   ;;   # bare lokální tag → myucto:latest
    esac
  elif docker images --format '{{.Repository}}' 2>/dev/null | grep -qiE 'ghcr\.io/.*myucto'; then
    # Stack neběží, ALE lokálně je stažený GHCR image → dřív se pullovalo = registry deploy.
    # (Bez tohohle by se u zhasnutého GHCR stacku v git klonu spadlo na source/build.)
    MODE="registry"
  elif [[ -d .git ]] && grep -qE '^\s*build:' docker-compose.yml 2>/dev/null; then
    MODE="source"
  else
    MODE="registry"
  fi
fi

COMPOSE_ARGS=""
if [[ "$MODE" == "registry" ]] && [[ -f docker-compose.production.yml ]]; then
  COMPOSE_ARGS="-f docker-compose.production.yml"
fi

if [[ -n "$running_image" ]]; then
  echo "==> Detekováno: běžící image '${running_image}' → režim '${MODE}'"
else
  echo "==> Žádný běžící app kontejner → režim '${MODE}' (dle přítomných souborů)"
fi
echo "    (přebít lze přes MYINVOICE_UPDATE_MODE=registry|source)"
[[ -n "$COMPOSE_ARGS" ]] && echo "    compose: ${COMPOSE_ARGS#-f }"

DC=(docker compose)
[[ -n "$COMPOSE_ARGS" ]] && DC+=($COMPOSE_ARGS)

# --- helpers pro robustní restart -----------------------------------------
app_log_tail() { "${DC[@]}" logs --no-color --tail "${1:-40}" app 2>&1 || true; }
app_network_broken() {
  app_log_tail 40 | grep -qiE 'getaddrinfo (for )?db failed|getaddrinfo failed|php_network_getaddresses|Temporary failure in name resolution|Name or service not known'
}
port_holder_status() {
  local port="$1" out name image ports
  out="$(docker ps --format '{{.Names}}|{{.Image}}|{{.Ports}}' 2>/dev/null || true)"
  while IFS='|' read -r name image ports; do
    [[ -z "${ports:-}" ]] && continue
    case "$ports" in *":${port}->"*) ;; *) continue ;; esac
    if [[ "$name" == *myucto* || "$image" == *myucto* ]]; then echo "OURS $name";
    else echo "FOREIGN $name $image"; fi
    return 0
  done <<< "$out"
}

# --- 1. fetch new code/image ---------------------------------------------
target_image=""
pin_compose=""
if [[ "${MODE}" == "source" ]]; then
  if [[ -n "$(git status --porcelain)" ]]; then
    echo "WARN: working tree is dirty — local changes won't be pulled." >&2
    echo "      Consider 'git stash' or commit first. Continuing in 5s…" >&2
    sleep 5
  fi
  echo "==> git pull"
  git pull --ff-only
  echo "==> Rebuilding app image…"
  "${DC[@]}" build --pull app
else
  requested_version="${MYINVOICE_TARGET_VERSION:-}"
  if [[ "$requested_version" =~ ^v?[0-9]{1,6}\.[0-9]{1,6}\.[0-9]{1,6}$ ]]; then
    if [[ ! -f "${PROJECT_ROOT}/cmd/lib/image-tag.sh" ]]; then
      echo "ERROR: Chybí cmd/lib/image-tag.sh, dotáhni repo." >&2
      exit 1
    fi
    # shellcheck source=lib/image-tag.sh
    . "${PROJECT_ROOT}/cmd/lib/image-tag.sh"
    bump="$("${DC[@]}" config | image_tag_bump - "$requested_version")"
    if [[ -n "$bump" ]]; then
      if [[ -n "$COMPOSE_ARGS" ]]; then
        pin_compose="${COMPOSE_ARGS#-f }"
      elif [[ -z "${COMPOSE_FILE:-}" ]]; then
        compose_files=()
        for file in compose.yaml compose.yml docker-compose.yaml docker-compose.yml \
          compose.override.yaml compose.override.yml docker-compose.override.yaml docker-compose.override.yml; do
          [[ ! -f "$file" ]] || compose_files+=("$file")
        done
        if [[ "${#compose_files[@]}" == 1 && "${compose_files[0]}" != *.override.* ]]; then
          pin_compose="${compose_files[0]}"
        fi
      fi
      if [[ -z "$pin_compose" || -L "$pin_compose" || "$(image_tag_bump "$pin_compose" "$requested_version")" != "$bump" ]]; then
        echo "ERROR: Připnutý image služby app nelze jednoznačně změnit v souboru Compose. Nastav cílovou verzi ručně." >&2
        exit 1
      fi
      read -r pinned_image target_image <<< "$bump"
      echo "==> Připnutá verze ${pinned_image##*:} → cílová ${target_image##*:}"
      docker pull "$target_image"
    fi
  fi
  if [[ -z "$target_image" ]]; then
    echo "==> Pulling latest image from registry…"
    "${DC[@]}" pull app
  fi
fi

# --- 1b. detect legacy 3-volume layout and auto-migrate (3.5.x → 3.6.0) --
# Od 3.6.0 je default Compose layout single-volume (`app-data:/data`). Pokud
# existují staré 3-volume volumes (`app-log`, `app-storage`, `app-private`)
# a nový `app-data` ne, je to úvodní migrace — proběhne automaticky, jinak
# by app po `up -d` viděla prázdný `app-data` a žádné staré faktury/uploady.
PROJECT="${COMPOSE_PROJECT_NAME:-$(basename "$PROJECT_ROOT" | tr '[:upper:]' '[:lower:]' | tr -cd '[:alnum:]_-')}"
old_log="${PROJECT}_app-log"
old_storage="${PROJECT}_app-storage"
old_private="${PROJECT}_app-private"
new_data="${PROJECT}_app-data"
has_old=false
for v in "$old_log" "$old_storage" "$old_private"; do
  if docker volume inspect "$v" >/dev/null 2>&1; then has_old=true; break; fi
done
has_new=false
if docker volume inspect "$new_data" >/dev/null 2>&1; then has_new=true; fi

if [[ "$has_old" == "true" ]] && [[ "$has_new" == "false" ]]; then
  echo ""
  echo "############################################################"
  echo "#  MIGRACE VOLUMES (3.5.x → 3.6.0)"
  echo "#"
  echo "#  Detekován starý 3-volume Docker layout. 3.6.0 přechází na"
  echo "#  single-volume (\`/data\`), který drží i cfg.local.php — tím se"
  echo "#  per-instance konfigurace (app.url, auth.require_totp) chová"
  echo "#  korektně i po image updatu."
  echo "#"
  echo "#  Skript teď automaticky:"
  echo "#    1. Snapshotne cfg.local.php z běžícího kontejneru"
  echo "#    2. Zastaví stack (DB volume zůstává)"
  echo "#    3. Zkopíruje data ze starých volumes do nového app-data"
  echo "#    4. Obnoví cfg.local.php v novém volumu"
  echo "#    5. Spustí stack na novém layoutu"
  echo "#"
  echo "#  Staré volumes NEMAZÁM — po ověření je smaž ručně příkazy z výpisu."
  echo "############################################################"
  echo ""
  bash "$PROJECT_ROOT/cmd/docker-migrate-volumes.sh"
  echo ""
fi

# --- pre-flight: hostový port aplikace ------------------------------------
APP_PORT="${APP_PORT:-8080}"
echo "==> Pre-flight: kontrola hostového portu ${APP_PORT}…"
holder="$(port_holder_status "${APP_PORT}")"
if [[ "$holder" == FOREIGN* ]]; then
  echo "ERROR: Host port ${APP_PORT} drží CIZÍ Docker kontejner: ${holder#FOREIGN }" >&2
  echo "       Uvolni ho ('docker stop …') nebo změň APP_PORT v .env a spusť znovu." >&2
  exit 1
elif [[ "$holder" == OURS* ]]; then
  echo "    Port ${APP_PORT} drží vlastní myucto kontejner (${holder#OURS }) — OK."
fi

# --- pre-flight: hostový port databáze ------------------------------------
# Když port během odstávky sebral cizí kontejner, `up` by spadl na 'port already
# allocated'. Mapování je jen loopback konvence pro DB klienta, aplikace uvnitř
# sítě sahá na 'db:3306' — port proto raději posuneme, než aby update selhal.
DB_PORT="${DB_PORT:-3307}"
echo "==> Pre-flight: kontrola hostového portu databáze ${DB_PORT}…"
if [[ "$(port_holder_status "${DB_PORT}")" == FOREIGN* ]]; then
  free=""
  for ((p = DB_PORT + 1; p < DB_PORT + 41; p++)); do
    if [[ "$(port_holder_status "$p")" != FOREIGN* ]] && ! (command -v ss >/dev/null && ss -ltn "( sport = :$p )" 2>/dev/null | grep -q LISTEN); then
      free="$p"; break
    fi
  done
  if [[ -z "$free" ]]; then
    echo "ERROR: Host port ${DB_PORT} je obsazený a v rozsahu $((DB_PORT+1))..$((DB_PORT+40)) není volný." >&2
    echo "       Uvolni port nebo změň DB_PORT v .env ručně." >&2
    exit 1
  fi
  echo "    Port ${DB_PORT} drží cizí kontejner — přepínám DB_PORT na ${free} a zapisuji do .env."
  if grep -q '^DB_PORT=' .env; then
    sed -i.bak "s|^DB_PORT=.*|DB_PORT=${free}|" .env && rm -f .env.bak
  else
    echo "DB_PORT=${free}" >> .env
  fi
  DB_PORT="${free}"
  export DB_PORT
fi

# --- 2. restart -----------------------------------------------------------
# --remove-orphans: uklidí stale kontejnery z jiného compose souboru; jinak
# zbylý app kontejner drží port a nový se nepřipojí k síti ('port already
# allocated' → app nepřeloží 'db' → migrace v cyklu padají).
echo "==> Restarting database…"
"${DC[@]}" up -d --remove-orphans db

# --- 3. wait for DB health -----------------------------------------------
echo "==> Waiting for database to become healthy…"
for i in {1..45}; do
  # `|| true`: dokud compose nevypíše "Health", grep nic nenajde → 1 → pod
  # `pipefail` by `set -e` ukončil skript uprostřed čekání na DB, a to beze slova.
  status=$("${DC[@]}" ps --format json db 2>/dev/null | grep -o '"Health":"[^"]*"' | head -1 | cut -d'"' -f4 || true)
  if [[ "$status" == "healthy" ]]; then echo "    DB ready."; break; fi
  [[ "$status" == "unhealthy" ]] && echo "    DB hlásí 'unhealthy' — čekám dál (attempt $i/45)…"
  sleep 2
  if [[ $i -eq 45 ]]; then
    echo "ERROR: DB failed to become healthy in ~90s. Check '${DC[*]} logs db'." >&2
    exit 1
  fi
done

# App až po zdravé DB. Nový image → compose app tak jako tak rekreuje; s
# --remove-orphans + auto-recovery níže je restart odolný proti kolizi portu/sítě.
if [[ -n "$target_image" ]]; then
  compose_before="$(cksum < "$pin_compose")"
  compose_stage="$(mktemp "${pin_compose}.XXXXXX")"
  trap 'rm -f -- "$compose_stage"' EXIT
  cp -p -- "$pin_compose" "$compose_stage"
  image_tag_write "$compose_stage" "$pinned_image" "$target_image"
  resolved_image="$(docker compose -f "$compose_stage" config | image_tag_app_image -)"
  if [[ "$resolved_image" != "$target_image" || "$(cksum < "$pin_compose")" != "$compose_before" ]]; then
    echo "ERROR: Změnu připnuté verze služby app v $pin_compose se nepodařilo ověřit." >&2
    exit 1
  fi
  mv -f -- "$compose_stage" "$pin_compose"
  trap - EXIT
  echo "    $pin_compose: $target_image"
fi
echo "==> Restarting app…"
"${DC[@]}" up -d --remove-orphans app

# --- 3b. wait for app (+ auto-recovery při chybějící síti) ---------------
# Migrace běží automaticky z entrypointu. Místo druhého explicitního migrate
# (= race condition s entrypointem) jen čekáme na /api/health.
echo "==> Waiting for app to become available (entrypoint runs migrations)…"
recovered=0
for i in {1..90}; do
  if curl -fsS -m 3 -o /dev/null "http://localhost:${APP_PORT}/api/health"; then
    echo "    App ready."
    break
  fi
  if (( i % 5 == 0 )) && [[ "$recovered" == "0" ]] && app_network_broken; then
    echo "    App běží, ale nemá compose síť (DNS 'db' selhává) → auto-recovery: force-recreate app." >&2
    "${DC[@]}" up -d --remove-orphans --force-recreate app >/dev/null 2>&1 || true
    recovered=1
    sleep 3
    continue
  fi
  sleep 2
  if [[ $i -eq 90 ]]; then
    echo "ERROR: App failed to respond in time. Check '${DC[*]} logs app':" >&2
    app_log_tail 25 >&2
    exit 1
  fi
done

if [[ -n "$target_image" ]]; then
  actual_version="$("${DC[@]}" exec -T app cat /var/www/html/VERSION)"
  if [[ "$actual_version" != "${target_image##*:}" ]]; then
    echo "ERROR: Služba app hlásí verzi '$actual_version', očekávána je '${target_image##*:}'." >&2
    exit 1
  fi
fi

# --- 3c. úklid osiřelých vrstev po updatu (bezpečné — jen dangling) -------
echo "==> Úklid dangling image vrstev…"
docker image prune -f >/dev/null 2>&1 || true
echo "    (staré tagované verze uklidíš přes cmd/docker-prune-images.sh)"

# --- 4. report -----------------------------------------------------------
APP_PORT="${APP_PORT:-8080}"
echo ""
echo "============================================================"
echo " Update complete. App: http://localhost:${APP_PORT}"
echo ""
echo " Tail logs:        docker compose logs -f app"
echo " Restart only:     docker compose restart app"
echo "============================================================"
