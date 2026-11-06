#!/usr/bin/env bash
# First-time install for the Docker stack — smart: preferuje GHCR pull, build jen na vyžádání.
#
#   1. Generates .env with random DB password (if missing)
#   2. Generates cfg.docker.php from cfg.sample.php with Docker-friendly defaults (if missing)
#   3. Zvolí režim a získá image:
#        - registry (default, je-li docker-compose.production.yml) → docker compose pull z GHCR
#        - source (--build nebo MYINVOICE_INSTALL_MODE=source nebo chybí production.yml) → local build
#        - registry pull selže + je Dockerfile → automatický fallback na build
#   4. Brings the stack up
#   5. Waits for DB health and runs migrations (entrypoint je spustí sám)
#   6. Prints the URL where the setup wizard is available
#
# Přebití: MYINVOICE_INSTALL_MODE=registry|source   nebo argument  --build.
#
# Konfigurace (jen při prvním vygenerování cfg.docker.php):
#   --url=https://ucto.firma.cz   veřejná adresa (app.url); jinak MYINVOICE_APP_URL,
#                                 výchozí http://localhost:${APP_PORT}
#   --dev                         čistě lokální vývojová instalace (app.env=development);
#                                 nikdy ji nevystavuj do sítě
# Výchozí je produkční režim (app.env=production). Secure cookie se řídí schématem
# app.url: https → cookie_secure=true s prefixem __Host-, http → false (jinak by se
# přes http://localhost nešlo přihlásit).
# Idempotent — safe to re-run.
set -Eeuo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$PROJECT_ROOT"

# Žádné tiché selhání (issue #14) — viz komentář v docker-update.sh.
trap 'rc=$?; {
  echo ""
  echo "ERROR: docker-install.sh selhal na řádku ${LINENO} — příkaz: ${BASH_COMMAND}"
  echo "       Exit kód ${rc}. INSTALACE NEBYLA DOKONČENA."
} >&2; exit "$rc"' ERR

FORCE_BUILD=0
INSTALL_DEV=0
INSTALL_URL=""
for arg in "$@"; do
  case "$arg" in
    --build) FORCE_BUILD=1 ;;
    --dev)   INSTALL_DEV=1 ;;
    --url=*) INSTALL_URL="${arg#--url=}" ;;
    *) echo "    Pozn.: neznámý argument '${arg}' ignoruji (povolené: --build, --dev, --url=URL)." >&2 ;;
  esac
done

if ! command -v docker >/dev/null 2>&1; then
  echo "ERROR: docker not found in PATH" >&2; exit 1
fi
if ! docker compose version >/dev/null 2>&1; then
  echo "ERROR: 'docker compose' (v2) plugin required" >&2; exit 1
fi

# Už něco běží? → spíš update než reinstalace (pokračujeme, je to idempotentní).
running_image="$(docker ps --filter label=com.docker.compose.service=app --format '{{.Image}}' 2>/dev/null | grep -i myucto | head -1 || true)"
if [[ -n "$running_image" ]]; then
  echo "==> Pozn.: app už běží (image '${running_image}'). Pro aktualizaci použij cmd/docker-update.sh."
fi

# --- 1. .env ---------------------------------------------------------------
if [[ ! -f .env ]]; then
  echo "==> Generating .env with random DB password…"
  DB_ROOT_PASSWORD=$(openssl rand -base64 24 | tr -d '=+/' | head -c 28)
  DB_PASSWORD=$(openssl rand -base64 24      | tr -d '=+/' | head -c 28)
  cat > .env <<EOF
# MyUcto.cz — Docker compose env (gitignored)
APP_PORT=8080
DB_PORT=3307
DB_NAME=myucto
DB_USER=myucto
DB_ROOT_PASSWORD=${DB_ROOT_PASSWORD}
DB_PASSWORD=${DB_PASSWORD}
EOF
  echo "    .env written (passwords randomised)"
else
  echo "==> .env already exists (skipping)"
fi
# Load .env so the rest of the script sees the values.
# `.env` se PARSUJE, nespouští (issue #14) — hodnota s mezerou bez uvozovek
# dřív shell rozsekala a zbytek se pokusil spustit jako příkaz.
if [[ ! -f "${PROJECT_ROOT}/cmd/lib/env-load.sh" ]]; then
  echo "ERROR: chybí cmd/lib/env-load.sh — instalace je neúplná, dotáhni repo (git pull)." >&2
  exit 1
fi
# shellcheck source=lib/env-load.sh
. "${PROJECT_ROOT}/cmd/lib/env-load.sh"
dotenv_load ./.env

# --- 2. cfg.docker.php -----------------------------------------------------
# Separate from cfg.php so the same checkout can run both native dev (`php -S`)
# and the Docker stack without one clobbering the other. compose mounts this
# file as /var/www/html/cfg.php inside the container.
if [[ ! -f cfg.docker.php ]]; then
  echo "==> Generating cfg.docker.php from cfg.sample.php with Docker defaults…"
  PEPPER=$(openssl rand -base64 32)
  ENC_KEY=$(openssl rand -base64 32)
  # Nová instalace = nová data, takže samostatný klíč mzdových otisků je bezpečný.
  # (Na existující instalaci se nesmí měnit, viz cfg.sample.php.)
  PAYROLL_HASH_KEY=$(openssl rand -base64 32)

  APP_URL="${INSTALL_URL:-${MYINVOICE_APP_URL:-http://localhost:${APP_PORT}}}"
  APP_URL="${APP_URL%/}"
  url_re='^https?://[][A-Za-z0-9.:-]+$'
  if [[ ! "$APP_URL" =~ $url_re ]]; then
    echo "ERROR: --url musí být jen origin ve tvaru http(s)://host[:port], dostal jsem '${APP_URL}'." >&2
    exit 1
  fi
  APP_LOCAL=0
  local_re='^https?://(localhost|127\.[0-9.]+|\[::1\])(:[0-9]+)?$'
  [[ "$APP_URL" =~ $local_re ]] && APP_LOCAL=1
  APP_ENV="production"
  [[ "$INSTALL_DEV" == "1" ]] && APP_ENV="development"

  cp cfg.sample.php cfg.docker.php
  # In-place tweaks: Docker hostnames + generated secrets.
  # cfg.sample.php has TWO `'host' => '127.0.0.1',` lines (db block then redis block).
  # First occurrence becomes 'db', second becomes 'redis' — done via perl (portable;
  # BSD sed on macOS does not support GNU's `0,/pat/` range addressing).
  perl -i -pe '
      BEGIN { $n = 0 }
      if (/host.*127\.0\.0\.1/) {
          $n++;
          s/127\.0\.0\.1/db/    if $n == 1;
          s/127\.0\.0\.1/redis/ if $n == 2;
      }
  ' cfg.docker.php
  sed -i.bak \
      -e "s|'name'    => 'myucto',|'name'    => '${DB_NAME}',|" \
      -e "s|'user'    => 'root',|'user'    => '${DB_USER}',|" \
      -e "s|'pass'    => 'CHANGE-ME',|'pass'    => '${DB_PASSWORD}',|" \
      -e "s|'pepper' => 'CHANGE-ME',|'pepper' => '${PEPPER}',|" \
      -e "s|'secret_encryption_key' => '',|'secret_encryption_key' => '${ENC_KEY}',|" \
      -e "s|'payroll_hash_key' => '',|'payroll_hash_key' => '${PAYROLL_HASH_KEY}',|" \
      -e "s|'env'    => 'production',|'env'    => '${APP_ENV}',|" \
      -e "s|'url'    => 'https://dev.example.com',|'url'    => '${APP_URL}',|" \
      cfg.docker.php
  # Secure cookie podle schématu: přes plain HTTP by prohlížeč Secure cookie
  # (i prefix __Host-) zahodil a přihlášení by nefungovalo. U https zůstávají
  # produkční hodnoty z cfg.sample.php.
  if [[ "$APP_URL" == http://* ]]; then
    sed -i.bak \
        -e "s|'cookie_name'   => '__Host-myinvoice_session',|'cookie_name'   => 'myinvoice_session',|" \
        -e "s|'cookie_secure' => true,|'cookie_secure' => false,|" \
        -e "s|'trusted_cookie_name'     => '__Host-myinvoice_td',|'trusted_cookie_name'     => 'myinvoice_td',|" \
        cfg.docker.php
  fi
  rm -f cfg.docker.php.bak
  echo "    cfg.docker.php written (app.env=${APP_ENV}, app.url=${APP_URL})"
  if [[ "$APP_URL" == http://* && "$APP_LOCAL" != "1" ]]; then
    echo "    !!  app.url je HTTP mimo localhost: přihlašovací cookie půjde po síti nešifrovaně.  !!" >&2
    echo "    !!  Pro provoz dej před aplikaci HTTPS proxy a nastav https adresu + cookie_secure. !!" >&2
  fi
  if [[ "$APP_ENV" == "development" ]]; then
    echo "    !!  Vývojový režim (--dev): instalaci nevystavuj do sítě.  !!" >&2
  fi
  echo ""
  echo "    !!  Edit cfg.docker.php to fill in SMTP, Cloudflare Turnstile, IP allowlist  !!"
  echo ""
else
  echo "==> cfg.docker.php already exists (skipping)"
fi

# --- 3. zvolit režim + získat image ---------------------------------------
# Default = registry (GHCR pull) — rychlejší a šetří RAM/disk/CPU build. Lokální build
# jen na vyžádání (--build / MYINVOICE_INSTALL_MODE=source) nebo když není production.yml.
MODE="${MYINVOICE_INSTALL_MODE:-}"
if [[ "$FORCE_BUILD" == "1" ]]; then
  MODE="source"
elif [[ -z "$MODE" ]]; then
  if [[ -f docker-compose.production.yml ]]; then MODE="registry"; else MODE="source"; fi
fi

COMPOSE_ARGS=""
if [[ "$MODE" == "registry" ]] && [[ -f docker-compose.production.yml ]]; then
  COMPOSE_ARGS="-f docker-compose.production.yml"
fi
DC=(docker compose)
[[ -n "$COMPOSE_ARGS" ]] && DC+=($COMPOSE_ARGS)

echo "==> Režim instalace: ${MODE}${COMPOSE_ARGS:+ (compose: ${COMPOSE_ARGS#-f })}"
echo "    (registry = GHCR pull; přebij přes --build nebo MYINVOICE_INSTALL_MODE=registry|source)"

if [[ "$MODE" == "registry" ]]; then
  echo "==> Pulling image from GHCR…"
  if ! "${DC[@]}" pull app; then
    if [[ -f Dockerfile ]]; then
      echo "    GHCR pull selhal → fallback na lokální build." >&2
      MODE="source"; COMPOSE_ARGS=""; DC=(docker compose)
    else
      echo "ERROR: GHCR pull selhal a není Dockerfile pro build." >&2; exit 1
    fi
  fi
fi

if [[ "$MODE" == "source" ]]; then
  if ! docker image inspect myucto:latest >/dev/null 2>&1; then
    echo "==> Building image…"
    "${DC[@]}" build app
  fi
fi

# --- helpers pro robustní start -------------------------------------------
# Poslední N řádků logu app služby (diagnostika síťové chyby ve fázi čekání).
app_log_tail() { "${DC[@]}" logs --no-color --tail "${1:-40}" app 2>&1 || true; }

# TRVALÁ síťová chyba 'app běží, ale nemá compose síť' (DNS 'db' nejde) —
# odliší od dočasného 'DB ještě nabíhá' (to by byl Connection refused).
app_network_broken() {
  app_log_tail 40 | grep -qiE 'getaddrinfo (for )?db failed|getaddrinfo failed|php_network_getaddresses|Temporary failure in name resolution|Name or service not known'
}

# Kdo drží hostový port: "OURS <name>" (vlastní myucto — OK), "FOREIGN <name> <image>"
# (cizí kontejner — konflikt), nebo prázdné (nikdo přes docker). Host proces mimo
# docker neřešíme (ss/lsof nemusí být přítomné) — up --force-recreate stale endpoint uvolní.
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

# --- pre-flight: hostový port aplikace ------------------------------------
# Kolize portu (starý/half-created app kontejner držící 8080) → nový app se
# nepřipojí k síti ('port already allocated') → nepřeloží 'db' → migrace v cyklu
# padají. Odhal cizího držitele předem.
echo "==> Pre-flight: kontrola hostového portu ${APP_PORT}…"
holder="$(port_holder_status "${APP_PORT}")"
if [[ "$holder" == FOREIGN* ]]; then
  echo "ERROR: Host port ${APP_PORT} drží CIZÍ Docker kontejner: ${holder#FOREIGN }" >&2
  echo "       Uvolni ho ('docker stop …') nebo změň APP_PORT v .env a spusť znovu." >&2
  exit 1
elif [[ "$holder" == OURS* ]]; then
  echo "    Port ${APP_PORT} drží vlastní myucto kontejner (${holder#OURS }) — OK."
fi

# Port databáze: mapuje se na loopback jen kvůli klientovi zvenčí. Souběh
# MyInvoice a MyÚčta na jednom stroji je běžný scénář (převod dat), takže kolize
# je očekávatelná a nemá kvůli ní instalace padat — port posuneme a zapíšeme do
# .env. Aplikace na něm nezávisí, uvnitř sítě sahá na 'db:3306'.
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

# Fallback (GHCR pull selhal) i čistý build → vynuť čistou rekreaci app, ať
# nezůstane půl-vytvořený kontejner držící port bez sítě. DB data ve volume zůstávají.
FORCE_RECREATE=0
[[ "$MODE" == "source" ]] && FORCE_RECREATE=1

# --- 4. up databáze --------------------------------------------------------
# --remove-orphans: uklidí kontejnery z jiného compose souboru (fallback
# registry→source); jinak stale kontejnery drží porty/sítě.
echo "==> Starting database…"
"${DC[@]}" up -d --remove-orphans db

# --- 5. wait for DB health -------------------------------------------------
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

# --- 4b. up app ------------------------------------------------------------
# App až po zdravé DB (depends_on to hlídá, ale takhle máme jasné stage hlášení).
APP_UP=(up -d --remove-orphans)
if [[ "$FORCE_RECREATE" == "1" ]]; then
  APP_UP+=(--force-recreate)
  echo "==> Starting app (source/fallback režim → --force-recreate)…"
else
  echo "==> Starting app…"
fi
"${DC[@]}" "${APP_UP[@]}" app

# --- 6. wait for app (entrypoint runs migrations) -------------------------
# Migrace se spouští automaticky z entrypointu před spuštěním web serveru.
# Místo druhého explicitního migrate (= race condition s entrypointem na některých
# migracích, např. 0015 FK rename) jen čekáme, až app odpoví na /api/health
# (v ALLOWED_PATHS pro FirstRunLockMiddleware → 200 i ve fresh-install state).
echo "==> Waiting for app to become available (entrypoint runs migrations)…"
recovered=0
for i in {1..90}; do
  if curl -fsS -m 3 -o /dev/null "http://localhost:${APP_PORT}/api/health"; then
    echo "    App ready."
    break
  fi
  # Každý 5. neúspěšný pokus: rozliš trvalou síťovou chybu (app bez sítě) od
  # dočasného 'migrace ještě běží' a proveď jednorázové auto-recovery.
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

# --- 6. report -------------------------------------------------------------
APP_PORT="${APP_PORT:-8080}"
echo ""
echo "============================================================"
echo " MyUcto.cz is up at:  http://localhost:${APP_PORT}"
echo ""
echo " The browser will land on the setup wizard:"
echo "   1. Admin user (name, email, password ≥ 12 chars)"
echo "   2. Supplier (IČO → Načíst z ARES → bank account)"
echo "   3. Optional sample data"
echo ""
echo " Useful:"
echo "   ${DC[*]} logs -f app    # tail app logs"
echo "   ${DC[*]} down           # stop stack (data persists)"
echo "   ${DC[*]} down -v        # stop + WIPE volumes (destroys DB)"
echo "============================================================"
