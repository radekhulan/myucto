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

image_tag_app_line() {
  awk '
    function is_mapping(key, name, pattern) {
      pattern = "^(" name "|\"" name "\"|" quote name quote "): *(#.*)?$"
      return key ~ pattern
    }
    BEGIN { quote = sprintf("%c", 39) }
    {
      if (found) next
      key = $0
      sub(/\r$/, "", key)
      if (key ~ /^ *(#|$)/) next
      match(key, /^ */)
      indent = RLENGTH
      key = substr(key, indent + 1)
      if (!indent) {
        services = is_mapping(key, "services")
        service_indent = 0
        app = 0
        next
      }
      if (!services) next
      if (!service_indent) service_indent = indent
      if (indent == service_indent) {
        app = is_mapping(key, "app")
        property_indent = 0
        next
      }
      if (!app) next
      if (!property_indent) property_indent = indent
      if (indent != property_indent || key !~ /^image: +/) next
      sub(/^image: +/, "", key)
      sub(/ +#.*$/, "", key)
      sub(/ +$/, "", key)
      if (substr(key, 1, 1) == substr(key, length(key), 1) &&
          (substr(key, 1, 1) == "\"" || substr(key, 1, 1) == quote)) {
        key = substr(key, 2, length(key) - 2)
      }
      if (key ~ /^[^[:space:]]+$/) print NR, key
      found = 1
    }
  ' "$1"
}

image_tag_app_image() {
  local line
  line="$(image_tag_app_line "$1")" || return
  printf '%s\n' "${line#* }"
}

# Vypise "<pripnuty image> <cilovy image>", kdyz ma sluzba app pripnutou
# verzi X.Y.Z nizsi nez cilova verze. Podporuje soubor i vystup compose config (-).
image_tag_bump() {
  local compose="$1" target="${2#v}" current version newest
  [[ ( "$compose" == - || -f "$compose" ) && "$target" =~ ^[0-9]{1,6}\.[0-9]{1,6}\.[0-9]{1,6}$ ]] || return 0
  current="$(image_tag_app_image "$compose")" || return
  [[ "$current" =~ (^|/)myucto:[0-9]{1,6}\.[0-9]{1,6}\.[0-9]{1,6}$ ]] || return 0
  version="${current##*:}"
  newest="$(printf '%s\n%s\n' "$version" "$target" | sort -u -t. -k1,1n -k2,2n -k3,3n | tail -1)"
  [[ "$newest" == "$target" && "$version" != "$target" ]] || return 0
  printf '%s %s\n' "$current" "${current%:*}:$target"
}

image_tag_write() {
  local compose="$1" old="$2" new="$3" line line_number current tmp
  line="$(image_tag_app_line "$compose")" || return
  read -r line_number current <<< "$line"
  if [[ -z "$line_number" || "$current" != "$old" ]]; then
    echo "ERROR: V $compose neodpovídá image služby app očekávané hodnotě $old." >&2
    return 1
  fi

  tmp="$(mktemp "${compose}.XXXXXX")" || return
  if cp -p -- "$compose" "$tmp" && awk -v line="$line_number" -v old="$old" -v new="$new" '
    NR == line {
      i = index($0, old)
      $0 = substr($0, 1, i - 1) new substr($0, i + length(old))
    }
    { print }
  ' "$compose" > "$tmp" && mv -f -- "$tmp" "$compose"; then
    return 0
  fi
  rm -f -- "$tmp"
  return 1
}

if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
  case "${1:-}" in
    --bump)  image_tag_bump "$2" "$3" ;;
    --write) image_tag_write "$2" "$3" "$4" ;;
    *) echo "usage: $0 --bump <compose> <verze> | --write <compose> <stara> <nova>" >&2; exit 2 ;;
  esac
fi
