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

cat > "$MOCK_BIN/route" <<'EOF'
#!/bin/sh
count=0
if [ -f "$NETWORK_COUNT_FILE" ]; then
    count="$(cat "$NETWORK_COUNT_FILE")"
fi
count=$((count + 1))
printf '%s\n' "$count" > "$NETWORK_COUNT_FILE"

if [ "$count" -lt "${NETWORK_READY_AFTER:-1}" ]; then
    exit 1
fi

case "${NETWORK_ROUTE_MODE:-stable}" in
    switch)
        if [ "$count" -eq "${NETWORK_READY_AFTER:-1}" ]; then
            interface="igc0"
        else
            interface="igc1"
        fi
        ;;
    *)
        interface="igc1"
        ;;
esac

printf '  interface: %s\n' "$interface"
EOF

cat > "$MOCK_BIN/ifconfig" <<'EOF'
#!/bin/sh
case "${NETWORK_INTERFACE_MODE:-up}" in
    up)
        printf '%s: flags=1008943<UP,BROADCAST,RUNNING>\n' "$1"
        ;;
    *)
        printf '%s: flags=1008942<BROADCAST,RUNNING>\n' "$1"
        ;;
esac
EOF

chmod 0755 "$MOCK_BIN/php" "$MOCK_BIN/policy-readiness" "$MOCK_BIN/route" "$MOCK_BIN/ifconfig"
sed "s#^\. /etc/rc.subr\$#. \"$RC_SUBR\"#" "$RC_SCRIPT" > "$RC_COPY"

PATH="$MOCK_BIN:$PATH"
export PATH

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

assertNetworkCalls()
{
    expected="$1"
    actual=0
    [ ! -f "$NETWORK_COUNT_FILE" ] || actual="$(cat "$NETWORK_COUNT_FILE")"
    [ "$actual" = "$expected" ] || failReadinessLifecycle "Ожидалось сетевых проверок: $expected, получено: $actual"
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

prepareNetworkScenario()
{
    scenario="$1"
    NETWORK_COUNT_FILE="$TEST_ROOT/network-$scenario.count"
    rm -f "$NETWORK_COUNT_FILE"
    network_readiness_attempts=5
    network_readiness_stable_samples=2
    NETWORK_READY_AFTER=1
    NETWORK_ROUTE_MODE=stable
    NETWORK_INTERFACE_MODE=up
    export NETWORK_COUNT_FILE NETWORK_READY_AFTER NETWORK_ROUTE_MODE NETWORK_INTERFACE_MODE
}

prepareNetworkScenario immediate
wait_for_network_readiness >/dev/null
assertNetworkCalls 2

prepareNetworkScenario delayed
NETWORK_READY_AFTER=3
export NETWORK_READY_AFTER
wait_for_network_readiness >/dev/null
assertNetworkCalls 4

prepareNetworkScenario switched
NETWORK_ROUTE_MODE=switch
export NETWORK_ROUTE_MODE
wait_for_network_readiness >/dev/null
assertNetworkCalls 3

prepareNetworkScenario timeout
NETWORK_READY_AFTER=10
network_readiness_attempts=3
export NETWORK_READY_AFTER
if wait_for_network_readiness > "$TEST_ROOT/network-timeout.output"; then
    failReadinessLifecycle 'Отсутствующий default route не заблокировал запуск.'
fi
assertNetworkCalls 3
grep -Fq 'стабильный default interface отсутствует' "$TEST_ROOT/network-timeout.output"

prepareNetworkScenario interface_down
NETWORK_INTERFACE_MODE=down
network_readiness_attempts=2
export NETWORK_INTERFACE_MODE
if wait_for_network_readiness > "$TEST_ROOT/network-interface.output"; then
    failReadinessLifecycle 'Неподнятый default interface не заблокировал запуск.'
fi
assertNetworkCalls 2
grep -Fq 'стабильный default interface отсутствует' "$TEST_ROOT/network-interface.output"

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

echo "Ожидание сети, readiness DNS listener и fail-closed блокировка проверены"
