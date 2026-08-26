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

cat > "$MOCK_BIN/php" <<'EOF'
#!/bin/sh
helper="$1"
shift
exec "$helper" "$@"
EOF

cat > "$MOCK_BIN/policy-readiness" <<'EOF'
#!/bin/sh
count=0
if [ -f "$READINESS_COUNT_FILE" ]; then
    count="$(cat "$READINESS_COUNT_FILE")"
fi
count=$((count + 1))
printf '%s\n' "$count" > "$READINESS_COUNT_FILE"

if [ "$count" -lt "${READY_AFTER:-1}" ]; then
    echo "ERROR DNS listener ещё не готов"
    exit 75
fi

case "${READY_RESULT:-success}" in
    success)
        echo "OK DNS listener готов"
        exit 0
        ;;
    invalid)
        echo "ERROR Policy-план некорректен"
        exit 65
        ;;
    *)
        echo "ERROR DNS listener ещё не готов"
        exit 75
        ;;
esac
EOF

chmod 0755 "$MOCK_BIN/php" "$MOCK_BIN/policy-readiness"
sed "s#^\. /etc/rc.subr\$#. \"$RC_SUBR\"#" "$RC_SCRIPT" > "$RC_COPY"

set -- noop
# shellcheck disable=SC1090
. "$RC_COPY"

php_binary="$MOCK_BIN/php"
policy_readiness_helper="$MOCK_BIN/policy-readiness"
state_dir="$TEST_ROOT/state"
managed_policy="$state_dir/managed-policy"
policy_plan="$state_dir/policy-plan.json"
mkdir -p "$state_dir"
printf '%s\n' '{}' > "$policy_plan"

PROCESS_ALIVE=1
process_is_sing_box()
{
    [ "$PROCESS_ALIVE" -eq 1 ]
}

sleep()
{
    :
}

failReadinessLifecycle()
{
    echo "$1" >&2
    exit 1
}

assertCalls()
{
    expected="$1"
    actual=0
    [ ! -f "$READINESS_COUNT_FILE" ] || actual="$(cat "$READINESS_COUNT_FILE")"
    [ "$actual" = "$expected" ] || failReadinessLifecycle "Ожидалось вызовов readiness: $expected, получено: $actual"
}

prepareScenario()
{
    scenario="$1"
    READINESS_COUNT_FILE="$TEST_ROOT/$scenario.count"
    rm -f "$READINESS_COUNT_FILE"
    export READINESS_COUNT_FILE
    PROCESS_ALIVE=1
    policy_readiness_attempts=4
    READY_AFTER=1
    READY_RESULT=success
    export READY_AFTER READY_RESULT
}

prepareScenario unmanaged
rm -f "$managed_policy"
wait_for_policy_readiness 4242 >/dev/null
assertCalls 0

prepareScenario immediate
printf '%s\n' managed > "$managed_policy"
wait_for_policy_readiness 4242 >/dev/null
assertCalls 1

prepareScenario retry
READY_AFTER=3
export READY_AFTER
wait_for_policy_readiness 4242 >/dev/null
assertCalls 3

prepareScenario invalid_plan
READY_RESULT=invalid
export READY_RESULT
if wait_for_policy_readiness 4242 > "$TEST_ROOT/invalid.output"; then
    failReadinessLifecycle 'Некорректный policy-план не заблокировал readiness.'
fi
assertCalls 1
grep -Fq 'Policy-план некорректен' "$TEST_ROOT/invalid.output"

prepareScenario timeout
READY_AFTER=10
policy_readiness_attempts=2
export READY_AFTER
if wait_for_policy_readiness 4242 > "$TEST_ROOT/timeout.output"; then
    failReadinessLifecycle 'Истечение readiness timeout не заблокировало активацию.'
fi
assertCalls 2
grep -Fq 'DNS listener sing-box не стал готов до firewall-активации.' "$TEST_ROOT/timeout.output"

prepareScenario process_exit
READY_AFTER=10
PROCESS_ALIVE=0
export READY_AFTER
if wait_for_policy_readiness 4242 > "$TEST_ROOT/process.output"; then
    failReadinessLifecycle 'Завершение процесса не прервало readiness-проверку.'
fi
assertCalls 1
grep -Fq 'Процесс sing-box завершился' "$TEST_ROOT/process.output"

echo "Ожидание readiness DNS listener и fail-closed блокировка проверены"
