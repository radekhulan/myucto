#!/usr/bin/env bash
# H-19 — akceptační test hardeningu nasazené instance (Apache/IIS + PHP front controller).
#
# Instance běží tak, že DOCUMENT ROOT JE KOŘEN CELÉHO BUNDLU — ve veřejném
# prostoru fyzicky leží api/, db/, cmd/, cfg.php, storage/, private/ atd.
# Jediné, co brání jejich stažení, je .htaccess (Apache) / web.config (IIS).
# Selhání téhle ochrany je TICHÉ — nic nespadne, jen jde soubor stáhnout.
# Skript proto zkouší skutečné URL a dívá se na odpověď, nikdy nečte
# konfiguraci — smlouva s hostingem slibuje ÚČINEK, ne hodnotu direktivy.
#
# Co skript ověří:
#   1. Preflight — /api/health přes SPRÁVNÝ hostname musí odpovědět 200.
#      Bez tohohle je celý zbytek báchorka: aplikace, která neběží (502/503),
#      by na sadu citlivých URL taky vrátila "cokoli-jen-ne-200" a skript by
#      tiše vyhlásil vítězství, aniž by cokoliv skutečně otestoval.
#   2. Tenantový host gate samotný — aplikace má gate na hlavičce Host:
#      požadavek, jehož Host neodpovídá app.url ani žádné aktivní doméně
#      firmy, musí dostat 421, a to i na "/" a i na přímý
#      /web/dist/index.html (přímý vstup do SPA nesmí gate obejít).
#   3. Že reverzní proxy před instancí hlavičku Host NEPŘEPISUJE — ověří se
#      přes /api/health, kde appka hlásí, jestli se resolvovaný hostname
#      requestu shoduje s nakonfigurovaným app.url.
#   4. Sadu citlivých URL (cfg.php, api/src, db/, storage/, private/, …) —
#      každá MUSÍ vrátit 403 nebo 404, nic jiného. 403 je vždy silný důkaz
#      (aktivně zablokováno). 404 je silný důkaz JEN tam, kde víme, že
#      testovaný soubor v bundlu skutečně existuje — jinak je to jen "nevíme"
#      a skript to v protokolu odliší (a vypíše, co ověřit ručně na instanci,
#      kde daný soubor prokazatelně existuje).
#
# Skript NIC nezapisuje na testovanou instanci — jen GET a HEAD.
#
# ⚠️ TŘI KONFIGURACE, JEDNA SMLOUVA. Pravidla, která tahle sada vyžaduje
# (cfg.sample.php, cfg.docker.php, VERSION, web.config, portainer-template.json
# a přípony .cmd/.ps1/.sh), musí být v .htaccess, web.config I docker/nginx.conf.
# H-19 je doplnil jen do prvních dvou a Docker instance pak vydávala /VERSION
# i /production.cmd s 200 tam, kde hosting vracel 403. Skript testuje jednu
# instanci, takže tuhle drift sám neuvidí — statickou paritu všech tří
# konfigurací hlídá SensitivePathBlockParityTest (testsuite Architecture).
# Při přidání nové citlivé cesty rozšiř seznam tady i v tom testu.
#
# Použití:
#   cmd/verify-instance-hardening.sh --host=www.myucto.cz
#   cmd/verify-instance-hardening.sh --host=www.myucto.cz --ip=203.0.113.10
#   cmd/verify-instance-hardening.sh --host=www.myucto.cz --json
#
# Windows ekvivalent: cmd/verify-instance-hardening.ps1 (drž oba v synchronizaci — AGENTS.md).

set -uo pipefail

SCHEME="https"
HOST=""
IP=""
PORT=""
INSECURE=""
FAKE_HOST="verify-instance-hardening-probe.invalid"   # RFC 2606 .invalid — nikdy neexistující doména
TIMEOUT="15"
JSON_OUT="0"

usage() {
    cat <<'EOF'
Použití: cmd/verify-instance-hardening.sh --host=HOST [volby]

Povinné:
  --host=HOST         Hostname instance přesně tak, jak je nastavený v app.url
                       (např. www.myucto.cz). Instance má tenantový gate na
                       hlavičce Host — test proti IP bez správného hostname by
                       dostal 421 na všechno a neotestoval by vůbec nic.

Volitelné:
  --ip=IP              Připojit se na tuto IP místo DNS (curl --resolve).
                        Hlavička Host a TLS SNI zůstávají nastavené na --host.
  --scheme=https|http   Výchozí https.
  --port=PORT           Výchozí 443 (https) / 80 (http).
  --insecure            Nekontrolovat TLS certifikát (curl -k) — self-signed/staging.
  --fake-host=HOST      Host hlavička pro test tenantového gate.
                        Výchozí verify-instance-hardening-probe.invalid.
  --timeout=SEKUND      Timeout jednoho požadavku. Výchozí 15.
  --json                Strojový výstup (JSON) místo čitelného protokolu.
  -h, --help            Tato nápověda.

Návratový kód 0 jen tehdy, když projdou úplně všechny kontroly.
EOF
}

for arg in "$@"; do
    case "$arg" in
        --host=*)      HOST="${arg#*=}" ;;
        --ip=*)        IP="${arg#*=}" ;;
        --scheme=*)    SCHEME="${arg#*=}" ;;
        --port=*)      PORT="${arg#*=}" ;;
        --insecure)    INSECURE="1" ;;
        --fake-host=*) FAKE_HOST="${arg#*=}" ;;
        --timeout=*)   TIMEOUT="${arg#*=}" ;;
        --json)        JSON_OUT="1" ;;
        -h|--help)     usage; exit 0 ;;
        *) echo "Neznámý parametr: $arg" >&2; usage >&2; exit 2 ;;
    esac
done

if [ -z "$HOST" ]; then
    echo "Chybí povinný parametr --host=HOST" >&2
    usage >&2
    exit 2
fi

if [ -z "$PORT" ]; then
    if [ "$SCHEME" = "https" ]; then PORT="443"; else PORT="80"; fi
fi

if ! command -v curl >/dev/null 2>&1; then
    echo "curl není na PATH — bez něj skript neumí nic otestovat." >&2
    exit 2
fi

BASE_URL="${SCHEME}://${HOST}:${PORT}"

CURL_COMMON=(-s --max-time "$TIMEOUT")
[ -n "$INSECURE" ] && CURL_COMMON+=(-k)
[ -n "$IP" ] && CURL_COMMON+=(--resolve "${HOST}:${PORT}:${IP}")

# HEAD požadavek → jen HTTP status kód (server se u blokovaných cest do PHP
# vůbec nedostane, takže HEAD/GET je pro ně jedno; u host-gate probe HEAD
# podporuje TenantDomainMiddleware/HealthAction explicitně).
head_status() {
    local path="$1" host_header="$2"
    curl "${CURL_COMMON[@]}" -o /dev/null -w '%{http_code}' -I \
        -H "Host: ${host_header}" "${BASE_URL}${path}" 2>/dev/null
}

# GET požadavek → status kód + tělo (potřeba jen pro /api/health).
# ⚠️ Nastavuje globální LAST_STATUS/LAST_BODY přímo — NESMÍ se volat přes
# $(...) command substitution, to by běželo v subshellu a proměnné by se
# ven nepropsaly (klasická bashová past).
LAST_STATUS=""
LAST_BODY=""
get_status() {
    local path="$1" host_header="$2"
    local tmp
    tmp="$(mktemp)"
    LAST_STATUS="$(curl "${CURL_COMMON[@]}" -o "$tmp" -w '%{http_code}' \
        -H "Host: ${host_header}" "${BASE_URL}${path}" 2>/dev/null)"
    LAST_BODY="$(cat "$tmp" 2>/dev/null)"
    rm -f "$tmp"
    [ -z "$LAST_STATUS" ] && LAST_STATUS="000"
}

json_escape() {
    local s="$1"
    s="${s//\\/\\\\}"
    s="${s//\"/\\\"}"
    printf '%s' "$s"
}

extract_json_bool() {   # extract_json_bool <json> <klíč> -> true|false|"" (chybí/null)
    printf '%s' "$1" | grep -oE "\"$2\"[[:space:]]*:[[:space:]]*(true|false)" \
        | grep -oE '(true|false)$' | head -n1
}

extract_json_str() {    # extract_json_str <json> <klíč> -> hodnota nebo ""
    printf '%s' "$1" | grep -oE "\"$2\"[[:space:]]*:[[:space:]]*\"[^\"]*\"" \
        | sed -E "s/.*:[[:space:]]*\"([^\"]*)\"/\1/" | head -n1
}

# ---------------------------------------------------------------------------
# Výsledky se sbírají do polí pro text i JSON výstup zároveň.
# ---------------------------------------------------------------------------
declare -a ROWS=()          # "sekce|popis|očekáváno|dostalo|verdikt|důkaz|poznámka"
FAIL_COUNT=0
ABORTED=0
ABORT_REASON=""

add_row() {
    local section="$1" label="$2" expected="$3" got="$4" verdict="$5" evidence="$6" note="$7"
    ROWS+=("${section}|${label}|${expected}|${got}|${verdict}|${evidence}|${note}")
    if [ "$verdict" != "PASS" ] && [ "$verdict" != "WARN" ]; then
        FAIL_COUNT=$((FAIL_COUNT + 1))
    fi
}

# ---------------------------------------------------------------------------
# 1) Preflight — appka musí žít, jinak je celý test bezcenný.
# ---------------------------------------------------------------------------
get_status "/api/health" "$HOST"
preflight_status="$LAST_STATUS"
preflight_body="$LAST_BODY"

if [ "$preflight_status" != "200" ]; then
    ABORTED=1
    ABORT_REASON="Preflight selhal: GET /api/health přes Host: ${HOST} vrátil ${preflight_status:-000} (očekáváno 200). Appka pravděpodobně neběží, nebo Host neodpovídá app.url / žádné aktivní doméně firmy — bez fungující instance je test bezcenný, dál se nepokračuje."
    add_row "preflight" "GET /api/health (Host: ${HOST})" "200" "${preflight_status:-000}" "FAIL" "-" "$ABORT_REASON"
else
    add_row "preflight" "GET /api/health (Host: ${HOST})" "200" "$preflight_status" "PASS" "-" "Instance žije, pokračuji."
fi

# ---------------------------------------------------------------------------
# 2) Proxy Host nesmí přepisovat — /api/health hlásí shodu se svým app.url.
# ---------------------------------------------------------------------------
if [ "$ABORTED" = "0" ]; then
    app_url_matches="$(extract_json_bool "$preflight_body" "app_url_matches_host")"
    app_url_configured="$(extract_json_bool "$preflight_body" "app_url_configured")"
    case "$app_url_matches" in
        true)
            add_row "proxy" "app_url_matches_host (Host: ${HOST})" "true" "true" "PASS" "STRONG" "Proxy nepřepisuje Host."
            ;;
        false)
            add_row "proxy" "app_url_matches_host (Host: ${HOST})" "true" "false" "FAIL" "STRONG" "Appka vidí jiný hostname, než jsme poslali — reverzní proxy pravděpodobně přepisuje Host, nebo app.url neodpovídá testovanému hostname. Ověřit ručně."
            ;;
        *)
            add_row "proxy" "app_url_matches_host (Host: ${HOST})" "true" "null/chybí" "WARN" "-" "Nelze ověřit — app.url zřejmě není routing_compatible (app_url_configured=${app_url_configured:-?}). Zkontrolovat konfiguraci app.url ručně."
            ;;
    esac
fi

# ---------------------------------------------------------------------------
# 3) Tenantový host gate — neznámý Host musí dostat 421, a to na API, na "/"
#    i na přímý /web/dist/index.html (přímý vstup nesmí gate obejít).
#    POZOR: /api/health je z gate schválně vyjmutý (monitoring přes IP musí
#    fungovat i s neznámým Host), proto se pro API probe použije jiná cesta.
# ---------------------------------------------------------------------------
if [ "$ABORTED" = "0" ]; then
    # /api/health hlásí i to, jestli je gate v konfiguraci vůbec zapnutý
    # (isEnabled() && app.url isConfigured()). Bez tohohle by 404 místo 421
    # níže vypadalo jako chyba skriptu — přitom to může být legitimně vypnutý
    # gate na dev/staging instanci (typicky mimo produkci).
    host_gate_enforced="$(extract_json_bool "$preflight_body" "host_gate_enforced")"
    gate_disabled_note=""
    if [ "$host_gate_enforced" = "false" ]; then
        gate_disabled_note=" [POZOR: /api/health hlásí host_gate_enforced=false — gate je v konfiguraci VYPNUTÝ (tenant.domains disabled, nebo app.url nenastavené), proto 421 nepřijde bez ohledu na .htaccess/web.config. Před produkčním nasazením je nutné gate zapnout.]"
        add_row "host_gate" "host_gate_enforced (dle /api/health)" "true" "false" "WARN" "-" "Gate je v konfiguraci vypnutý — testy níže proto nemohou vrátit 421, i kdyby webserver i appka jinak fungovaly správně."
    fi

    declare -a GATE_TARGETS=(
        "/api/__verify-instance-hardening-gate-probe__|API (neexistující route — gate běží PŘED routováním)"
        "/|kořen"
        "/web/dist/index.html|přímý vstup SPA (nesmí obejít gate)"
        "/web/dist/|adresář web/dist (nesmí obejít gate)"
    )
    for target in "${GATE_TARGETS[@]}"; do
        path="${target%%|*}"
        label="${target#*|}"
        status="$(head_status "$path" "$FAKE_HOST")"
        if [ "$status" = "421" ]; then
            add_row "host_gate" "HEAD ${path} (Host: ${FAKE_HOST})" "421" "$status" "PASS" "STRONG" "$label"
        else
            add_row "host_gate" "HEAD ${path} (Host: ${FAKE_HOST})" "421" "${status:-000}" "FAIL" "STRONG" "${label} — gate neodmítl neznámý Host, jak má.${gate_disabled_note}"
        fi
    done

    # Sanity: se SPRÁVNÝM Host by na stejné cestě 421 dostat NEMĚLA (jinak by
    # test výše mohl procházet i tehdy, když appka vrací 421 na úplně všechno).
    sanity_status="$(head_status "/" "$HOST")"
    if [ "$sanity_status" = "421" ]; then
        add_row "host_gate" "HEAD / (Host: ${HOST}, sanity)" "≠421" "421" "FAIL" "STRONG" "Se SPRÁVNÝM Host appka taky vrací 421 — buď je gate rozbitý (blokuje i legitimní hostname), nebo testovaný --host neodpovídá app.url."
    else
        add_row "host_gate" "HEAD / (Host: ${HOST}, sanity)" "≠421" "$sanity_status" "PASS" "STRONG" "Se správným Host gate nezasahuje."
    fi
fi

# ---------------------------------------------------------------------------
# 4) Sadа citlivých URL — 403 nebo 404, nic jiného. Formát řádku:
#    "cesta|kategorie|očekává_se_existence(1/0)|poznámka"
# ---------------------------------------------------------------------------
declare -a SENSITIVE_URLS=(
    "/cfg.php|cfg|1|"
    "/cfg.local.php|cfg|1|"
    "/cfg.sample.php|blokovaný soubor|1|"
    "/cfg.docker.php|blokovaný soubor|1|"
    "/api/src/Bootstrap.php|blokovaná složka|1|"
    "/api/vendor/autoload.php|blokovaná složka|1|"
    "/api/tests/bootstrap.php|blokovaná složka|1|"
    "/api/bin/MyInvoiceMigrate.php|blokovaná složka|1|"
    "/api/templates/invoice/invoice.twig|blokovaná složka|1|"
    "/db/|blokovaná složka|1|"
    "/db/migrations/0001_init.sql|blokovaná složka|1|"
    "/log/|blokovaná složka|0|Runtime adresář, na čerstvé instanci může být prázdný/neexistovat"
    "/storage/|blokovaná složka|1|"
    "/private/|blokovaná složka|0|.gitignore adresář, nemusí být na hostingu vůbec nasazený"
    "/tools/export-pdf.ps1|blokovaná složka|1|"
    "/tools/export-pdf.sh|blokovaná složka|1|"
    "/cmd/audit-gate.sh|blokovaná složka|1|"
    "/cmd/audit-gate.ps1|blokovaná složka|1|"
    "/docker/entrypoint-alpine.sh|blokovaná složka|1|"
    "/.git/config|VCS|0|Deploy by .git neměl kopírovat — ověřit ručně tam, kde náhodou existuje"
    "/.git/HEAD|VCS|0|Deploy by .git neměl kopírovat — ověřit ručně tam, kde náhodou existuje"
    "/.env|blokovaný soubor|0|.env je Docker-only konvence, na klasickém hostingu nemusí existovat"
    "/api/composer.json|blokovaný soubor|1|"
    "/api/composer.lock|blokovaný soubor|1|"
    "/api/phpunit.xml|blokovaný soubor|1|"
    "/web/package.json|blokovaný soubor|1|"
    "/web/pnpm-lock.yaml|blokovaný soubor|1|"
    "/composer.json|blokovaný soubor|0|Reálný soubor je api/composer.json, root varianta je jen ze zadání"
    "/package.json|blokovaný soubor|0|Reálný soubor je web/package.json, root varianta je jen ze zadání"
    "/pnpm-lock.yaml|blokovaný soubor|0|Reálný soubor je web/pnpm-lock.yaml, root varianta je jen ze zadání"
    "/phpunit.xml|blokovaný soubor|0|Reálný soubor je api/phpunit.xml, root varianta je jen ze zadání"
    "/Dockerfile|blokovaný soubor|1|"
    "/docker-compose.yml|blokovaný soubor|1|"
    "/VERSION|blokovaný soubor|1|"
    "/web.config|blokovaný soubor|1|IIS chrání .config defaultně samo, Apache a nginx až přidaným pravidlem"
    "/portainer-template.json|blokovaný soubor|1|"
    "/README.md|blokovaný soubor|1|"
    "/AGENTS.md|blokovaný soubor|1|"
    "/demo.cmd|blokovaná přípona|1|Přípony .cmd/.ps1/.sh mimo už chráněné složky"
    "/production.cmd|blokovaná přípona|1|Viz výše"
    "/docker-entrypoint.sh|blokovaná přípona|1|Viz výše"
)

if [ "$ABORTED" = "0" ]; then
    for entry in "${SENSITIVE_URLS[@]}"; do
        IFS='|' read -r path category expect_exists note <<<"$entry"
        status="$(head_status "$path" "$HOST")"
        case "$status" in
            403)
                add_row "sensitive" "${path} [${category}]" "403/404" "$status" "PASS" "STRONG" "$note"
                ;;
            404)
                if [ "$expect_exists" = "1" ]; then
                    add_row "sensitive" "${path} [${category}]" "403/404" "$status" "PASS" "STRONG" "$note"
                else
                    add_row "sensitive" "${path} [${category}]" "403/404" "$status" "PASS" "WEAK" "404, ale neznáme jistě, že soubor na instanci existuje — nejde odlišit 'zablokováno' od 'neexistuje'. ${note}"
                fi
                ;;
            *)
                add_row "sensitive" "${path} [${category}]" "403/404" "${status:-000}" "FAIL" "STRONG" "$note"
                ;;
        esac
    done
fi

# ---------------------------------------------------------------------------
# Výstup
# ---------------------------------------------------------------------------
overall="PASS"
if [ "$ABORTED" = "1" ] || [ "$FAIL_COUNT" -gt 0 ]; then
    overall="FAIL"
fi

if [ "$JSON_OUT" = "1" ]; then
    printf '{'
    printf '"host":"%s","scheme":"%s","port":"%s","fake_host":"%s",' \
        "$(json_escape "$HOST")" "$(json_escape "$SCHEME")" "$(json_escape "$PORT")" "$(json_escape "$FAKE_HOST")"
    printf '"aborted":%s,"abort_reason":"%s",' \
        "$([ "$ABORTED" = "1" ] && echo true || echo false)" "$(json_escape "$ABORT_REASON")"
    printf '"overall":"%s","fail_count":%d,' "$overall" "$FAIL_COUNT"
    printf '"checks":['
    first=1
    for row in "${ROWS[@]}"; do
        IFS='|' read -r section label expected got verdict evidence note <<<"$row"
        [ "$first" = "1" ] && first=0 || printf ','
        printf '{"section":"%s","check":"%s","expected":"%s","got":"%s","verdict":"%s","evidence":"%s","note":"%s"}' \
            "$(json_escape "$section")" "$(json_escape "$label")" "$(json_escape "$expected")" \
            "$(json_escape "$got")" "$(json_escape "$verdict")" "$(json_escape "$evidence")" "$(json_escape "$note")"
    done
    printf ']}'
    echo
else
    echo "=== H-19 — akceptační test hardeningu instance ==="
    echo "Host:  ${HOST}   Scheme: ${SCHEME}   Port: ${PORT}"
    [ -n "$IP" ] && echo "IP:    ${IP} (--resolve)"
    echo "Fake Host pro test gate: ${FAKE_HOST}"
    echo

    last_section=""
    for row in "${ROWS[@]}"; do
        IFS='|' read -r section label expected got verdict evidence note <<<"$row"
        if [ "$section" != "$last_section" ]; then
            echo "--- ${section} ---"
            last_section="$section"
        fi
        printf '[%-4s] %-60s očekáváno=%-8s dostalo=%-6s' "$verdict" "$label" "$expected" "$got"
        [ "$evidence" != "-" ] && printf ' (%s)' "$evidence"
        echo
        [ -n "$note" ] && echo "        ${note}"
    done

    echo
    if [ "$ABORTED" = "1" ]; then
        echo "PŘERUŠENO: ${ABORT_REASON}"
    fi
    echo "=== Výsledek: ${overall} (selhání: ${FAIL_COUNT}) ==="
fi

if [ "$overall" = "PASS" ]; then
    exit 0
fi
exit 1
