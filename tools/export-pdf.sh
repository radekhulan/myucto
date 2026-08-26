#!/usr/bin/env bash
#
# export-pdf.sh — MyUcto.cz manuál: Markdown -> PDF (md2pdf engine), POSIX runner.
#
# Tenký spouštěč nad sdíleným MD2PDF enginem (Linux/macOS, příp. Git-Bash na
# Windows; ekvivalent tools/export-pdf.ps1). Sloučí kapitoly manuálu
# (manual/NNN_*.md dle manual/INDEX.md) do jednoho manual/manual.pdf pomocí
# tools/md2pdf.config.php. Zdrojové .md jsou READ-ONLY.
#
# Engine se hledá v $MD2PDF_HOME, jinak v obvyklých umístěních (viz níže).
# PHP lze přepsat přes $PHP, jinak se zkusí `php` v PATH a pak c:/inetpub/php/php.exe.
#
# Použití:
#   ./export-pdf.sh
#   ./export-pdf.sh --preview
#   ./export-pdf.sh --config /jiny/md2pdf.config.php

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

CONFIG=""
PREVIEW=0

usage() { sed -n '3,19p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'; }

while [ $# -gt 0 ]; do
  case "$1" in
    -c|--config)  CONFIG="${2:-}"; shift 2 ;;
    --config=*)   CONFIG="${1#*=}"; shift ;;
    -p|--preview) PREVIEW=1; shift ;;
    -h|--help)    usage; exit 0 ;;
    *) echo "Neznámý argument: $1" >&2; usage; exit 1 ;;
  esac
done

# --- Engine (sdílený MD2PDF) ----------------------------------------------
ENGINE=""
for cand in "${MD2PDF_HOME:-}" "/c/work/MD2PDF" "c:/work/MD2PDF" \
            "$SCRIPT_DIR/../../MD2PDF" "/opt/md2pdf" "/usr/local/share/md2pdf"; do
  [ -n "$cand" ] || continue
  if [ -f "$cand/md2pdf.php" ]; then ENGINE="$cand/md2pdf.php"; ENGINE_HOME="$cand"; break; fi
done
[ -n "$ENGINE" ] || { echo "MD2PDF engine nenalezen (nastav \$MD2PDF_HOME na adresář enginu)." >&2; exit 1; }

# --- Config ----------------------------------------------------------------
[ -n "$CONFIG" ] || CONFIG="$SCRIPT_DIR/md2pdf.config.php"
[ -f "$CONFIG" ] || { echo "Config nenalezen: $CONFIG" >&2; exit 1; }
CONFIG="$(cd "$(dirname "$CONFIG")" && pwd)/$(basename "$CONFIG")"  # absolutize

# --- PHP -------------------------------------------------------------------
PHP="${PHP:-}"
if [ -z "$PHP" ]; then
  if command -v php >/dev/null 2>&1; then PHP="php";
  elif [ -x "/c/inetpub/php/php.exe" ]; then PHP="/c/inetpub/php/php.exe";
  else echo "php nenalezen (nastav \$PHP)." >&2; exit 1; fi
fi

# --- Zajisti vendor (mPDF) v enginu ---------------------------------------
if [ ! -f "$ENGINE_HOME/vendor/autoload.php" ]; then
  echo "vendor/ enginu chybí - spouštím composer install..."
  command -v composer >/dev/null 2>&1 || {
    echo "vendor/ chybí a composer není v PATH. Spusť: composer install (v $ENGINE_HOME)" >&2
    exit 1
  }
  ( cd "$ENGINE_HOME" && composer install --no-interaction --no-progress --no-dev )
fi

# --- Export ----------------------------------------------------------------
echo "PHP:    $PHP"
echo "Engine: $ENGINE"
echo "Config: $CONFIG"
echo

"$PHP" "$ENGINE" --config="$CONFIG"

# --- Volitelné: PNG náhledy (GhostScript) ----------------------------------
if [ "$PREVIEW" -eq 1 ]; then
  echo
  echo "Renderuji PNG náhledy..."

  CFGJSON="$("$PHP" "$ENGINE" --config="$CONFIG" --print-config)"
  OUTDIR="$(printf '%s' "$CFGJSON" | "$PHP" -r '$j=json_decode(stream_get_contents(STDIN),true); echo (is_array($j)&&isset($j["output_dir"]))?$j["output_dir"]:"";')"
  [ -n "$OUTDIR" ] || { echo "Nelze načíst output_dir přes --print-config" >&2; exit 1; }
  PREVDIR="$OUTDIR/_preview"

  GS=""
  for c in gs gswin64c; do
    if command -v "$c" >/dev/null 2>&1; then GS="$c"; break; fi
  done
  [ -n "$GS" ] || [ ! -x "/c/inetpub/GhostScript/bin/gswin64c.exe" ] || GS="/c/inetpub/GhostScript/bin/gswin64c.exe"

  if [ -z "$GS" ]; then
    echo "GhostScript (gs) nenalezen - náhledy přeskočeny." >&2
  else
    mkdir -p "$PREVDIR"
    rm -f "$PREVDIR"/*.png 2>/dev/null || true
    for pdf in "$OUTDIR"/*.pdf; do
      [ -e "$pdf" ] || continue
      base="$(basename "$pdf" .pdf)"
      "$GS" -q -dNOPAUSE -dBATCH -sDEVICE=png16m -r110 \
            -dTextAlphaBits=4 -dGraphicsAlphaBits=4 \
            -o "$PREVDIR/${base}-p%02d.png" "$pdf" >/dev/null
      echo "  náhled: $base"
    done
    echo "Hotovo: náhledy v $PREVDIR"
  fi
fi

echo
echo "HOTOVO."
