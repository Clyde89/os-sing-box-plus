#!/bin/sh
set -eu

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
HELPER="$ROOT/src/usr/local/sbin/sing-box-readiness"
TMPDIR=$(mktemp -d)
RC_FILE="$TMPDIR/sing_box"
CONFIG_FILE="$TMPDIR/readiness.conf"
LOCK_DIR="$TMPDIR/readiness.lock"

cleanup()
{
    rm -rf "$TMPDIR"
}
trap cleanup EXIT HUP INT TERM

run_quiet()
{
    output=$(
        SING_BOX_READINESS_RC_CONF_FILE="$RC_FILE" \
        SING_BOX_READINESS_CONFIG_FILE="$CONFIG_FILE" \
        SING_BOX_READINESS_LOCK_DIR="$LOCK_DIR" \
        "$HELPER" "$@"
    )
    [ -z "$output" ] || {
        echo "Неожиданный вывод: $output" >&2
        return 1
    }
}

cat > "$CONFIG_FILE" <<'EOF'
SING_BOX_READINESS_ENABLE="YES"
SING_BOX_READINESS_INTERFACES="wan"
EOF
printf '%s\n' 'sing_box_enable="NO"' > "$RC_FILE"
run_quiet --event startup

cat > "$CONFIG_FILE" <<'EOF'
SING_BOX_READINESS_ENABLE="NO"
SING_BOX_READINESS_INTERFACES="wan"
EOF
printf '%s\n' 'sing_box_enable="YES"' > "$RC_FILE"
run_quiet --event startup

cat > "$CONFIG_FILE" <<'EOF'
SING_BOX_READINESS_ENABLE="YES"
SING_BOX_READINESS_INTERFACES="wan"
EOF
printf '%s\n' 'sing_box_enable="YES"' > "$RC_FILE"
run_quiet --event newwanip --interfaces lan

printf '%s\n' 'Проверки безопасного отключения механизма восстановления пройдены.'
