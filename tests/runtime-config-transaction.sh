#!/bin/sh
set -eu

ROOT_DIR="$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"
SOURCE_SCRIPT="$ROOT_DIR/src/usr/local/opnsense/scripts/OPNsense/SingBox/runtime_config.php"
STUB_DIR="$ROOT_DIR/tests/stubs/runtime"
TEST_DIR="$(mktemp -d "${TMPDIR:-/tmp}/sing-box-runtime-transaction.XXXXXX")"

cleanup()
{
    rm -rf "$TEST_DIR"
}

fail()
{
    echo "Ошибка: $*" >&2
    exit 1
}

assert_file()
{
    [ -f "$1" ] || fail "отсутствует ожидаемый файл: $1"
}

assert_absent()
{
    [ ! -e "$1" ] || fail "обнаружен неожиданный файл: $1"
}

assert_equal()
{
    cmp -s "$1" "$2" || fail "содержимое файлов не совпало: $1 и $2"
}

assert_log()
{
    grep -Fq "$1" "$2" || fail "в журнале отсутствует запись: $1"
}

restart_count()
{
    if [ ! -f "$SCENARIO_DIR/restart.count" ]; then
        printf '0\n'
        return
    fi
    cat "$SCENARIO_DIR/restart.count"
}

prepare_scenario()
{
    name="$1"
    SCENARIO_DIR="$TEST_DIR/$name"
    SCENARIO_ROOT="$SCENARIO_DIR/root"
    SCENARIO_STATE="$SCENARIO_ROOT/var/db/os-sing-box"
    SCENARIO_ACTIVE="$SCENARIO_ROOT/var/run/sing-box-policy-active"
    SCENARIO_CONFIG="$SCENARIO_ROOT/usr/local/etc/sing-box/config.json"
    SCENARIO_MODEL="$SCENARIO_DIR/model.json"
    SCENARIO_SCRIPT="$SCENARIO_DIR/runtime_config.php"
    SCENARIO_STDOUT="$SCENARIO_DIR/stdout"
    SCENARIO_STDERR="$SCENARIO_DIR/stderr"

    mkdir -p \
        "$SCENARIO_ROOT/usr/local/etc/sing-box" \
        "$SCENARIO_ROOT/usr/local/bin" \
        "$SCENARIO_ROOT/usr/local" \
        "$SCENARIO_ROOT/usr/sbin" \
        "$SCENARIO_ROOT/var/run" \
        "$SCENARIO_STATE"
    ln -s "$ROOT_DIR/src/usr/local/opnsense" "$SCENARIO_ROOT/usr/local/opnsense"

    cat > "$SCENARIO_ROOT/usr/local/bin/sing-box" <<'SH'
#!/bin/sh
set -eu
[ "${1:-}" = "check" ] || exit 64
[ "${MOCK_CHECK_FAIL:-0}" -eq 0 ]
SH

    cat > "$SCENARIO_ROOT/usr/sbin/service" <<'SH'
#!/bin/sh
set -eu

[ "${1:-}" = "sing-box" ] || exit 64
action="${2:-}"

case "$action" in
    status)
        exit "${MOCK_SERVICE_STATUS:-1}"
        ;;
    restart)
        count=0
        if [ -f "$MOCK_RESTART_COUNT" ]; then
            count="$(cat "$MOCK_RESTART_COUNT")"
        fi
        count=$((count + 1))
        printf '%s\n' "$count" > "$MOCK_RESTART_COUNT"

        if [ -n "${MOCK_RESTART_FAIL_ON:-}" ] && [ "$count" -eq "$MOCK_RESTART_FAIL_ON" ]; then
            echo "Имитирован отказ перезапуска sing-box" >&2
            exit 1
        fi

        if [ -n "${MOCK_SKIP_ACTIVATION_ON:-}" ] && [ "$count" -eq "$MOCK_SKIP_ACTIVATION_ON" ]; then
            printf 'incorrect-policy-checksum\n' > "$MOCK_ACTIVE_FILE"
            chmod 0600 "$MOCK_ACTIVE_FILE"
            rm -f "$MOCK_STATE_DIR/filter-reload.pending"
            exit 0
        fi

        if [ -f "$MOCK_STATE_DIR/managed-policy" ] && [ -r "$MOCK_STATE_DIR/policy-plan.json" ]; then
            sha256sum "$MOCK_STATE_DIR/policy-plan.json" | awk '{print $1}' > "$MOCK_ACTIVE_FILE"
            chmod 0600 "$MOCK_ACTIVE_FILE"
        else
            rm -f "$MOCK_ACTIVE_FILE"
        fi
        rm -f "$MOCK_STATE_DIR/filter-reload.pending"
        ;;
    *)
        exit 64
        ;;
esac
SH

    chmod 0755 "$SCENARIO_ROOT/usr/local/bin/sing-box" "$SCENARIO_ROOT/usr/sbin/service"

    sed \
        -e "s#/usr/local#$SCENARIO_ROOT/usr/local#g" \
        -e "s#/usr/sbin#$SCENARIO_ROOT/usr/sbin#g" \
        -e "s#/var#$SCENARIO_ROOT/var#g" \
        "$SOURCE_SCRIPT" > "$SCENARIO_SCRIPT"

    cat > "$SCENARIO_MODEL" <<'JSON'
{
  "settings": {
    "capture": {
      "mode": "selected",
      "interfaces": ["lan"],
      "clients": "192.0.2.10"
    },
    "dns": {
      "listenAddress": "127.0.0.1",
      "listenPort": "55353",
      "redirectDomains": "example.org",
      "fakeIpRange": "198.18.0.0/15"
    },
    "policy": {
      "outboundMode": "direct_bind",
      "bindAddress": "192.0.2.70",
      "gateway": "VPN_GW"
    },
    "tun": {
      "interfaceName": "tun_singbox",
      "address": "172.19.0.1/30",
      "stack": "system"
    }
  }
}
JSON
}

seed_managed_runtime()
{
    printf '{"state":"old"}\n' > "$SCENARIO_CONFIG"
    cp "$SCENARIO_CONFIG" "$SCENARIO_DIR/expected-config.json"
    printf 'managed\n' > "$SCENARIO_STATE/managed-config"
    printf '{"state":"old-policy"}\n' > "$SCENARIO_STATE/policy-plan.json"
    cp "$SCENARIO_STATE/policy-plan.json" "$SCENARIO_DIR/expected-policy.json"
    printf 'managed\n' > "$SCENARIO_STATE/managed-policy"
    sha256sum "$SCENARIO_STATE/policy-plan.json" | awk '{print $1}' > "$SCENARIO_ACTIVE"
    chmod 0600 "$SCENARIO_ACTIVE"
}

run_apply()
{
    service_status="${1:-0}"
    restart_fail_on="${2:-}"
    skip_activation_on="${3:-}"

    set +e
    RUNTIME_TEST_MODEL="$SCENARIO_MODEL" \
        MOCK_SERVICE_STATUS="$service_status" \
        MOCK_RESTART_FAIL_ON="$restart_fail_on" \
        MOCK_SKIP_ACTIVATION_ON="$skip_activation_on" \
        MOCK_RESTART_COUNT="$SCENARIO_DIR/restart.count" \
        MOCK_STATE_DIR="$SCENARIO_STATE" \
        MOCK_ACTIVE_FILE="$SCENARIO_ACTIVE" \
        php -d "include_path=$STUB_DIR" "$SCENARIO_SCRIPT" apply \
        > "$SCENARIO_STDOUT" 2> "$SCENARIO_STDERR"
    APPLY_STATUS=$?
    set -e
}

test_running_service_success()
{
    prepare_scenario running-success
    seed_managed_runtime
    run_apply 0

    [ "$APPLY_STATUS" -eq 0 ] || fail "транзакция работающей службы завершилась кодом $APPLY_STATUS"
    [ "$(restart_count)" -eq 1 ] || fail "работающая служба не была перезапущена один раз"
    assert_equal "$SCENARIO_DIR/expected-config.json" "$SCENARIO_CONFIG.bak"
    assert_log '"policy-out"' "$SCENARIO_CONFIG"
    assert_log 'Служба sing-box перезапущена, policy-состояние подтверждено.' "$SCENARIO_STDOUT"
    assert_absent "$SCENARIO_STATE/filter-reload.pending"
    expected="$(sha256sum "$SCENARIO_STATE/policy-plan.json" | awk '{print $1}')"
    [ "$(cat "$SCENARIO_ACTIVE")" = "$expected" ] || fail "активирован неверный policy-план"
    [ "$(stat -c '%a' "$SCENARIO_STATE/apply.lock")" = "600" ] || fail "блокировка Apply получила небезопасные права"
}

test_stopped_service_deferred_activation()
{
    prepare_scenario stopped-success
    seed_managed_runtime
    rm -f "$SCENARIO_STATE/managed-policy" "$SCENARIO_STATE/policy-plan.json" "$SCENARIO_ACTIVE"
    run_apply 1

    [ "$APPLY_STATUS" -eq 0 ] || fail "транзакция остановленной службы завершилась кодом $APPLY_STATUS"
    [ "$(restart_count)" -eq 0 ] || fail "остановленная служба была неожиданно запущена"
    assert_file "$SCENARIO_STATE/filter-reload.pending"
    assert_absent "$SCENARIO_ACTIVE"
    assert_log 'активация отложена до следующего запуска' "$SCENARIO_STDOUT"
}

test_restart_failure_rollback()
{
    prepare_scenario restart-failure
    seed_managed_runtime
    printf 'setup\n' > "$SCENARIO_STATE/setup-required"
    rm -f "$SCENARIO_STATE/managed-config"
    run_apply 0 1

    [ "$APPLY_STATUS" -eq 69 ] || fail "ошибка перезапуска вернула код $APPLY_STATUS вместо 69"
    [ "$(restart_count)" -eq 2 ] || fail "после ошибки не был выполнен восстановительный перезапуск"
    assert_equal "$SCENARIO_DIR/expected-config.json" "$SCENARIO_CONFIG"
    assert_equal "$SCENARIO_DIR/expected-policy.json" "$SCENARIO_STATE/policy-plan.json"
    assert_file "$SCENARIO_STATE/setup-required"
    assert_absent "$SCENARIO_STATE/managed-config"
    expected="$(sha256sum "$SCENARIO_STATE/policy-plan.json" | awk '{print $1}')"
    [ "$(cat "$SCENARIO_ACTIVE")" = "$expected" ] || fail "после rollback не был активирован прежний policy-план"
    assert_log 'Не удалось перезапустить sing-box' "$SCENARIO_STDERR"
}

test_activation_mismatch_rollback()
{
    prepare_scenario activation-mismatch
    seed_managed_runtime
    run_apply 0 "" 1

    [ "$APPLY_STATUS" -eq 69 ] || fail "ошибка policy checksum вернула код $APPLY_STATUS вместо 69"
    [ "$(restart_count)" -eq 2 ] || fail "после ошибки policy checksum не был выполнен rollback restart"
    assert_equal "$SCENARIO_DIR/expected-config.json" "$SCENARIO_CONFIG"
    assert_equal "$SCENARIO_DIR/expected-policy.json" "$SCENARIO_STATE/policy-plan.json"
    expected="$(sha256sum "$SCENARIO_STATE/policy-plan.json" | awk '{print $1}')"
    [ "$(cat "$SCENARIO_ACTIVE")" = "$expected" ] || fail "rollback не восстановил прежний policy checksum"
    assert_log 'Контрольная сумма активного policy-плана не совпала' "$SCENARIO_STDERR"
}

trap cleanup EXIT HUP INT TERM
test_running_service_success
test_stopped_service_deferred_activation
test_restart_failure_rollback
test_activation_mismatch_rollback

echo "Транзакционный Apply, restart и rollback runtime-конфигурации проверены"
