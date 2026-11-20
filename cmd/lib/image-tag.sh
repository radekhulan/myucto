#!/usr/bin/env bash
# MyUcto.cz - cilova verze aktualizace u pripnute znacky image.
#
# Manual doporucuje v produkci pripnout v compose konkretni verzi
# (`image: ghcr.io/radekhulan/myucto:6.70.1`). `docker compose pull` pak stahne
# zase tu samou verzi, takze tlacitko Aktualizovat v aplikaci nic nezmenilo.
# Tyhle funkce pripnutou verzi prepisou na cilovou verzi z tlacitka.
# Plovouci znacky (`latest`, `6`, `6.70`) ani nizsi cilovou verzi nemeni.
#
# Windows protejsek: cmd/lib/image-tag.ps1 (drz obe varianty v synchronizaci).
#
# Pouziti (source):
#   . cmd/lib/image-tag.sh
#   bump="$(image_tag_bump docker-compose.production.yml 6.71.0)"   # "stara nova" nebo nic
#   image_tag_write docker-compose.production.yml "$old" "$new"
#
# Prime spusteni (test suite):
#   bash cmd/lib/image-tag.sh --bump <compose> <verze>
#   bash cmd/lib/image-tag.sh --write <compose> <stara> <nova>

# Vypise "<pripnuty image> <cilovy image>", kdyz ma compose u myucto image
# pripnutou verzi X.Y.Z nizsi nez cilova verze. Jinak nevypise nic.
image_tag_bump() {
  local compose="$1" target="${2#v}" current version newest
  [[ -f "$compose" && "$target" =~ ^[0-9]{1,6}\.[0-9]{1,6}\.[0-9]{1,6}$ ]] || return 0
  current="$(sed -nE 's#^[[:space:]]*image:[[:space:]]*["'\'']?([^[:space:]"'\'']*myucto:[0-9]{1,6}\.[0-9]{1,6}\.[0-9]{1,6})["'\'']?([[:space:]].*)?$#\1#p' "$compose" | head -1)"
  [[ -n "$current" ]] || return 0
  version="${current##*:}"
  [[ "$version" != "$target" ]] || return 0
  newest="$(printf '%s\n%s\n' "$version" "$target" | sort -t. -k1,1n -k2,2n -k3,3n | tail -1)"
  [[ "$newest" == "$target" ]] || return 0
  printf '%s %s\n' "$current" "${current%:*}:${target}"
}

# Prepise v compose souboru pripnuty image na cilovy (jen radek `image:`).
image_tag_write() {
  local compose="$1" old="$2" new="$3" tmp
  tmp="${compose}.tmp.$$"
  awk -v old="$old" -v new="$new" '
    !done && $1 == "image:" && (i = index($0, old)) {
      $0 = substr($0, 1, i - 1) new substr($0, i + length(old)); done = 1
    }
    { print }
  ' "$compose" > "$tmp" && cat "$tmp" > "$compose" && rm -f "$tmp"
}

if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
  case "${1:-}" in
    --bump)  image_tag_bump "$2" "$3" ;;
    --write) image_tag_write "$2" "$3" "$4" ;;
    *) echo "usage: $0 --bump <compose> <verze> | --write <compose> <stara> <nova>" >&2; exit 2 ;;
  esac
fi
