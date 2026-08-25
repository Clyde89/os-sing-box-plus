#!/bin/sh
set -eu

ROOT_DIR="$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"
RC_SCRIPT="$ROOT_DIR/src/usr/local/etc/rc.d/sing-box"
TEST_ROOT="$(mktemp -d)"
trap 'rm -rf "$TEST_ROOT"' EXIT HUP INT TERM

MOCK_BIN="$TEST_ROOT/bin"
RC_SUBR="$TEST_ROOT/rc.subr"
RC_COPY="$TEST_ROOT/sing-box.rc"
mkdir -p "$MOCK_BIN"

cat > "$RC_SUBR" <<'EOF'
load_rc_config() { :; }
run_rc_command() { :; }
EOF

cat > "$MOCK_BIN/sha256" <<'EOF'
#!/bin/sh
[ "${1:-}" = "-q" ]
sha256sum "$2" | awk '{print $1}'
EOF

cat > "$MOCK_BIN/configctl" <<'EOF'
#!/bin/sh
printf '%s\n' "$*" >> "$CONFIGCTL_LOG"
[ "${CONFIGCTL_MODE:-success}" != "fail" ]
EOF

chmod 0755 "$MOCK_BIN/sha256" "$MOCK_BIN/configctl"
sed "s#^\. /etc/rc.subr\$#. \"$RC_SUBR\"#" "$RC_SCRIPT" > "$RC_COPY"

PATH="$MOCK_BIN:$PATH"
export PATH

set -- noop
# shellcheck disable=SC1090
. "$RC_COPY"

failPolicyLifecycle()
{
    echo "$1" >&2
    exit 1
}

assertEquals()
{
    expected="$1"
    actual="$2"
    label="$3"
    [ "$expected" = "$actual" ] || failPolicyLifecycle "$label: ожидалось '$expected', получено '$actual'"
}

prepareScenario()
{
    scenario="$1"
    scenario_root="$TEST_ROOT/$scenario"
    state_dir="$scenario_root/state"
    run_dir="$scenario_root/run"
    mkdir -p "$state_dir" "$run_dir"

    pending_filter_reload="$state_dir/filter-reload.pending"
    managed_policy="$state_dir/managed-policy"
    policy_plan="$state_dir/policy-plan.json"
    policy_active="$run_dir/sing-box-policy-active"
    CONFIGCTL_LOG="$scenario_root/configctl.log"
    : > "$CONFIGCTL_LOG"
    export CONFIGCTL_LOG
}

prepareManagedPlan()
{
    printf '%s\n' managed > "$managed_policy"
    printf '%s\n' '{"schema_version":2,"managed_by":"os-sing-box-plus"}' > "$policy_plan"
    printf '%s\n' pending > "$pending_filter_reload"
}

reloadCount()
{
    wc -l < "$CONFIGCTL_LOG" | tr -d ' '
}

prepareScenario activate_success
prepareManagedPlan
CONFIGCTL_MODE=success
export CONFIGCTL_MODE
activate_policy_rules >/dev/null
expected_checksum="$(sha256sum "$policy_plan" | awk '{print $1}')"
assertEquals "$expected_checksum" "$(cat "$policy_active")" "Контрольная сумма активного policy-плана"
assertEquals 600 "$(stat -c '%a' "$policy_active")" "Права признака активного policy-плана"
[ ! -e "$pending_filter_reload" ] || failPolicyLifecycle 'После успешной активации остался pending firewall reload.'
assertEquals 1 "$(reloadCount)" "Количество reload при успешной активации"

prepareScenario activate_failure
prepareManagedPlan
CONFIGCTL_MODE=fail
export CONFIGCTL_MODE
if activate_policy_rules >/dev/null; then
    failPolicyLifecycle 'Активация policy-правил не завершилась ошибкой при отказе firewall reload.'
fi
[ ! -e "$policy_active" ] || failPolicyLifecycle 'После ошибки активации сохранился признак активного policy-плана.'
[ -e "$pending_filter_reload" ] || failPolicyLifecycle 'После ошибки активации был потерян pending firewall reload.'
assertEquals 2 "$(reloadCount)" "Количество reload при откате ошибочной активации"

prepareScenario deactivate_success
active_checksum=deactivate-success-checksum
printf '%s\n' "$active_checksum" > "$policy_active"
chmod 0600 "$policy_active"
CONFIGCTL_MODE=success
export CONFIGCTL_MODE
deactivate_policy_rules >/dev/null
[ ! -e "$policy_active" ] || failPolicyLifecycle 'После успешной деактивации сохранился признак активного policy-плана.'
assertEquals 1 "$(reloadCount)" "Количество reload при успешной деактивации"

prepareScenario deactivate_failure
active_checksum=deactivate-failure-checksum
printf '%s\n' "$active_checksum" > "$policy_active"
chmod 0600 "$policy_active"
CONFIGCTL_MODE=fail
export CONFIGCTL_MODE
if deactivate_policy_rules >/dev/null; then
    failPolicyLifecycle 'Деактивация policy-правил не завершилась ошибкой при отказе firewall reload.'
fi
assertEquals "$active_checksum" "$(cat "$policy_active")" "Восстановление контрольной суммы после ошибки деактивации"
assertEquals 600 "$(stat -c '%a' "$policy_active")" "Права восстановленного признака активного policy-плана"
assertEquals 1 "$(reloadCount)" "Количество reload при ошибке деактивации"

echo "Активация, деактивация и откат policy-правил firewall проверены"
