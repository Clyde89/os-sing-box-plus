#!/bin/sh
set -eu

ROOT_DIR="$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"
HELPER="$ROOT_DIR/src/usr/local/sbin/sing-box-service-config"
ACTIONS="$ROOT_DIR/src/usr/local/opnsense/service/conf/actions.d/actions_sing-box.conf"
TMP_DIR="$(mktemp -d)"
RC_FILE="$TMP_DIR/sing_box"
trap 'rm -rf "$TMP_DIR"' EXIT HUP INT TERM

[ -x "$HELPER" ]
[ -f "$ACTIONS" ]

state="$(SING_BOX_RC_CONF_FILE="$RC_FILE" "$HELPER" status)"
[ "$state" = "NO" ]

SING_BOX_RC_CONF_FILE="$RC_FILE" "$HELPER" enable >/dev/null
grep -q '^sing_box_enable="YES"$' "$RC_FILE"
[ "$(stat -c '%a' "$RC_FILE")" = "644" ]
[ "$(SING_BOX_RC_CONF_FILE="$RC_FILE" "$HELPER" status)" = "YES" ]

SING_BOX_RC_CONF_FILE="$RC_FILE" "$HELPER" disable >/dev/null
grep -q '^sing_box_enable="NO"$' "$RC_FILE"
[ "$(SING_BOX_RC_CONF_FILE="$RC_FILE" "$HELPER" status)" = "NO" ]

set +e
SING_BOX_RC_CONF_FILE="$RC_FILE" "$HELPER" invalid >/dev/null 2>&1
status=$?
set -e
[ "$status" -eq 64 ]

grep -q '^\[enable\]$' "$ACTIONS"
grep -q '^command:/usr/local/sbin/sing-box-service-config enable$' "$ACTIONS"
grep -q '^\[disable\]$' "$ACTIONS"
grep -q '^command:/usr/local/sbin/sing-box-service-config disable$' "$ACTIONS"
grep -q '^\[enabled\]$' "$ACTIONS"
grep -q '^command:/usr/local/sbin/sing-box-service-config status$' "$ACTIONS"

echo "Управление автозапуском sing-box проверено"
