#!/usr/bin/env bash
# Stáhne XSD schémata do api/xsd/: EPO MFČR výkazy (DPH/KH/SH/DPFO/DPPO) +
# ISDOC 6.0.2 + ČSSZ OSVC (přehled OSVČ) + připnuté JMHZ balíčky z portálu MPSV.
# EPO se mění typicky 1× ročně, ISDOC zřídka, ČSSZ per ročník. Default check-in má
# aktuální verze.
#
# Použití:
#   bash cmd/download-xsd.sh           — stáhne všechna schémata (EPO + ISDOC + ČSSZ + JMHZ)
#   bash cmd/download-xsd.sh dphkh1    — stáhne jen jedno EPO schema
#   bash cmd/download-xsd.sh isdoc     — stáhne jen ISDOC schema
#   bash cmd/download-xsd.sh osvc25    — stáhne jen ČSSZ přehled OSVČ (annual)
#   bash cmd/download-xsd.sh jmhz      — stáhne 6 připnutých JMHZ XSD balíčků
#
# Zdroje:
#   EPO:   https://adisspr.mfcr.cz/dpr/adis/idpr_pub/epo2_info/popis_struktury_seznam.faces
#   ISDOC: https://mv.gov.cz/isdoc/clanek/aktualni-verze.aspx
#   ČSSZ:  https://www.cssz.gov.cz/definice-e-podani-osvc  (per ročník mění URL/dokument-ID)

set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DIR="$PROJECT_ROOT/api/xsd"
BASE="https://adisspr.mfcr.cz/adis/jepo/schema"
ISDOC_URL="https://isdoc.cz/6.0.2/xsd/isdoc-invoice-6.0.2.xsd"
# ČSSZ přehled OSVČ — URL i cílový název (osvcYY) měň při novém ročníku (viz README).
CSSZ_OSVC_URL="https://www.cssz.gov.cz/documents/20143/3201321/OSVC25.xsd/5d467add-4c11-0e56-4d54-d455b56c15c9"
FORMS=("dphdp3" "dphkh1" "dphshv" "dpfdp5" "dppdp9" "dpzvd6" "dpsvd2" "dpzmb1" "dpzdb1" "isdoc" "osvc25" "jmhz")

mkdir -p "$DIR"

if [[ $# -gt 0 ]]; then
    FORMS=("$@")
fi

for form in "${FORMS[@]}"; do
    if [[ "$form" == "jmhz" ]]; then
        bash "$PROJECT_ROOT/cmd/download-jmhz-xsd.sh"
        continue
    elif [[ "$form" == "isdoc" ]]; then
        url="$ISDOC_URL"
        target="${DIR}/isdoc-invoice-6.0.2.xsd"
    elif [[ "$form" == "osvc25" ]]; then
        url="$CSSZ_OSVC_URL"
        target="${DIR}/osvc25.xsd"
    else
        url="${BASE}/${form}_epo2.xsd"
        target="${DIR}/${form}.xsd"
    fi
    echo "→ ${form}: ${url}"
    if curl -sSfL "$url" -o "$target.tmp"; then
        # Sanity check: musí začínat XML deklarací
        if head -c 20 "$target.tmp" | grep -q '<?xml'; then
            mv "$target.tmp" "$target"
            size=$(wc -c < "$target")
            echo "  ✓ ${target} (${size} bytes)"
        else
            rm -f "$target.tmp"
            echo "  ✗ ${form}: stažený soubor není XML (možná 404 HTML)"
        fi
    else
        rm -f "$target.tmp" 2>/dev/null || true
        echo "  ✗ ${form}: stažení selhalo"
    fi
done

echo
echo "Hotovo. Schémata v: ${DIR}"
echo "Aplikace je při generování XML automaticky validuje a archivuje výsledek v tax_submissions."
