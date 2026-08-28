#!/bin/sh
set -eu

ROOT_DIR="$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"
HELPER="$ROOT_DIR/src/usr/local/sbin/sing-box-logctl"
ACTIONS="$ROOT_DIR/src/usr/local/opnsense/service/conf/actions.d/actions_sing-box.conf"
TMP_DIR="$(mktemp -d)"
LOG_FILE="$TMP_DIR/sing-box.log"
trap 'rm -rf "$TMP_DIR"' EXIT HUP INT TERM

[ -x "$HELPER" ]
[ -f "$ACTIONS" ]

printf '%s\n' 'test-log-entry' > "$LOG_FILE"
SING_BOX_LOG_FILE="$LOG_FILE" SING_BOX_LOG_OWNER="" SING_BOX_LOG_GROUP="" "$HELPER" clear >/dev/null
[ ! -s "$LOG_FILE" ]
[ "$(stat -c '%a' "$LOG_FILE")" = "640" ]

set +e
SING_BOX_LOG_FILE="$LOG_FILE" SING_BOX_LOG_OWNER="" SING_BOX_LOG_GROUP="" "$HELPER" invalid >/dev/null 2>&1
status=$?
set -e
[ "$status" -eq 64 ]

grep -q '^\[clearlog\]$' "$ACTIONS"
grep -q '^command:/usr/local/sbin/sing-box-logctl clear$' "$ACTIONS"

echo "Безопасное управление журналом sing-box проверено"
