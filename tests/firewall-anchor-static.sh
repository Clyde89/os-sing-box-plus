#!/bin/sh
set -eu

ROOT_DIR="$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"
PLUGIN="$ROOT_DIR/src/usr/local/etc/inc/plugins.inc.d/sing_box.inc"

[ -f "$PLUGIN" ]

grep -q '^function sing_box_policy_is_managed()' "$PLUGIN"
grep -q '^function sing_box_firewall($fw)' "$PLUGIN"
grep -q "is_file('/var/db/os-sing-box/managed-policy')" "$PLUGIN"
grep -Fq "registerAnchor('sing-box/*', 'rdr')" "$PLUGIN"
grep -Fq "registerAnchor('sing-box/*', 'fw')" "$PLUGIN"

if grep -Eq 'config\.xml|pfctl[[:space:]]' "$PLUGIN"; then
    echo "Регистрация PF anchors не должна напрямую изменять config.xml или вызывать pfctl" >&2
    exit 1
fi

echo "Безопасная регистрация PF anchors sing-box проверена"
