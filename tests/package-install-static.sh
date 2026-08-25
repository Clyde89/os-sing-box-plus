#!/bin/sh
set -eu

ROOT_DIR="$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"
PRE_INSTALL="$ROOT_DIR/packaging/freebsd/+PRE_INSTALL"
POST_INSTALL="$ROOT_DIR/packaging/freebsd/+POST_INSTALL"
PRE_DEINSTALL="$ROOT_DIR/packaging/freebsd/+PRE_DEINSTALL"
POST_DEINSTALL="$ROOT_DIR/packaging/freebsd/+POST_DEINSTALL"
RC_SCRIPT="$ROOT_DIR/src/usr/local/etc/rc.d/sing-box"

for file in "$PRE_INSTALL" "$POST_INSTALL" "$PRE_DEINSTALL" "$POST_DEINSTALL" "$RC_SCRIPT"; do
    [ -f "$file" ]
done

grep -q 'RC_STATE_FILE=.*sing_box.rc.upgrade' "$PRE_INSTALL"
grep -q 'install -o root -g wheel -m 0600 "$RC_CONF_FILE" "$RC_STATE_FILE"' "$PRE_INSTALL"
grep -q '\[ ! -f "$LEGACY_VERSION_FILE" \]' "$PRE_INSTALL"
grep -q '\[ -f "$SYSTEM_CONFIG" \] && \[ ! -f "$LEGACY_CONFIG_SNAPSHOT" \]' "$PRE_INSTALL"
grep -q 'Сохранён ранее созданный исходный снимок legacy-конфигурации OPNsense' "$PRE_INSTALL"

grep -q 'RC_STATE_FILE=.*sing_box.rc.upgrade' "$POST_INSTALL"
grep -q 'install -o root -g wheel -m 0644 "$RC_STATE_FILE" "$RC_CONF_FILE"' "$POST_INSTALL"
grep -q "echo 'sing_box_enable=\"NO\"' > \"\$RC_CONF_FILE\"" "$POST_INSTALL"
grep -q 'SETUP_REQUIRED_FILE=.*setup-required' "$POST_INSTALL"
grep -q 'fresh_configuration=0' "$POST_INSTALL"
grep -q ': > "$SETUP_REQUIRED_FILE"' "$POST_INSTALL"
grep -q 'Первоначальная настройка sing-box отмечена как незавершённая' "$POST_INSTALL"

if grep -q "echo 'sing_box_enable=\"YES\"' > \"\$RC_CONF_FILE\"" "$POST_INSTALL"; then
    echo "Первая установка не должна автоматически включать sing-box" >&2
    exit 1
fi

grep -q 'cmp -s "$MIGRATION_FILE" "$SING_BOX_CONFIG"' "$POST_INSTALL"
grep -q 'cmp -s "$RC_STATE_FILE" "$RC_CONF_FILE"' "$POST_INSTALL"
grep -q 'PENDING_FILTER_RELOAD=.*filter-reload.pending' "$POST_INSTALL"
grep -q ': > "$PENDING_FILTER_RELOAD"' "$POST_INSTALL"
grep -Fq 'state_dir="/var/db/os-sing-box"' "$RC_SCRIPT"
grep -Fq 'pending_filter_reload="${state_dir}/filter-reload.pending"' "$RC_SCRIPT"
grep -Fq 'managed_policy="${state_dir}/managed-policy"' "$RC_SCRIPT"
grep -Fq 'policy_plan="${state_dir}/policy-plan.json"' "$RC_SCRIPT"
grep -Fq 'policy_active="/var/run/sing-box-policy-active"' "$RC_SCRIPT"
grep -q '^activate_policy_rules()' "$RC_SCRIPT"
grep -q '^deactivate_policy_rules()' "$RC_SCRIPT"
grep -Fq 'cleanup_failed_start "$started_pid"' "$RC_SCRIPT"
grep -Fq 'rm -f "$pending_filter_reload"' "$RC_SCRIPT"

restart_line="$(grep -n 'service sing-box restart' "$POST_INSTALL" | head -n 1 | cut -d: -f1)"
reload_line="$(grep -n 'configctl filter reload' "$POST_INSTALL" | head -n 1 | cut -d: -f1)"
[ -n "$restart_line" ]
[ -n "$reload_line" ]
[ "$restart_line" -lt "$reload_line" ]

grep -q 'kill -KILL "$pid"' "$PRE_DEINSTALL"
grep -q 'удаление пакета остановлено' "$PRE_DEINSTALL"

if grep -q 'rm -f /usr/local/etc/sing-box/config.json$' "$POST_DEINSTALL"; then
    echo "Пользовательский config.json не должен удаляться вместе с пакетом" >&2
    exit 1
fi

if grep -q 'rm -f /usr/local/etc/sing-box/readiness.conf$' "$POST_DEINSTALL"; then
    echo "Пользовательский readiness.conf не должен удаляться вместе с пакетом" >&2
    exit 1
fi

echo "Безопасное состояние установки и удаления пакета проверено"
