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
    SCENARIO_ENVIRONMENT="$SCENARIO_DIR/network-environment.json"
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
      "fakeIpRange": "198.18.0.0/15",
      "policyUpstreamType": "https",
      "policyUpstreamAddress": "203.0.113.53",
      "policyUpstreamPort": "443",
      "policyUpstreamTlsServerName": "dns.example.test",
      "policyUpstreamPath": "/dns-query"
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

    cat > "$SCENARIO_ENVIRONMENT" <<'JSON'
{
  "interfaces": {
    "lan": {
      "device": "igc0",
      "enabled": true,
      "present": true,
      "up": true
    }
  },
  "local_ipv4_addresses": ["127.0.0.1", "192.0.2.1", "192.0.2.70"],
  "local_ipv4_networks": [
    {"device": "lo0", "cidr": "127.0.0.1/8"},
    {"device": "igc0", "cidr": "192.0.2.1/24"}
  ],
  "gateways": {
    "VPN_GW": {
      "ipprotocol": "inet",
      "if": "igc1",
      "disabled": false,
      "defunct": false,
      "force_down": false
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
        RUNTIME_TEST_NETWORK_ENVIRONMENT="$SCENARIO_ENVIRONMENT" \
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

run_adoption()
{
    set +e
    RUNTIME_TEST_MODEL="$SCENARIO_MODEL" \
        MOCK_SERVICE_STATUS=1 \
        MOCK_RESTART_COUNT="$SCENARIO_DIR/restart.count" \
        MOCK_STATE_DIR="$SCENARIO_STATE" \
        MOCK_ACTIVE_FILE="$SCENARIO_ACTIVE" \
        php -d "include_path=$STUB_DIR" "$SCENARIO_SCRIPT" approve-adoption \
        > "$SCENARIO_STDOUT" 2> "$SCENARIO_STDERR"
    ADOPTION_STATUS=$?
    set -e
}

run_preflight()
{
    set +e
    RUNTIME_TEST_MODEL="$SCENARIO_MODEL" \
        RUNTIME_TEST_NETWORK_ENVIRONMENT="$SCENARIO_ENVIRONMENT" \
        php -d "include_path=$STUB_DIR" "$SCENARIO_SCRIPT" preflight \
        > "$SCENARIO_STDOUT" 2> "$SCENARIO_STDERR"
    PREFLIGHT_STATUS=$?
    set -e
}

test_network_preflight_success()
{
    prepare_scenario network-preflight-success
    run_preflight

    [ "$PREFLIGHT_STATUS" -eq 0 ] || fail "успешный сетевой preflight завершился кодом $PREFLIGHT_STATUS"
    assert_log 'OK {"ready":true,"errors":[]}' "$SCENARIO_STDOUT"
    assert_absent "$SCENARIO_STATE/apply.lock"
    assert_absent "$SCENARIO_CONFIG"
}

test_unmanaged_adoption_success()
{
    prepare_scenario adoption-success
    printf '{"state":"unmanaged-original"}\n' > "$SCENARIO_CONFIG"
    cp "$SCENARIO_CONFIG" "$SCENARIO_DIR/expected-config.json"

    run_adoption
    [ "$ADOPTION_STATUS" -eq 0 ] || fail "подтверждение managed-перехода завершилось кодом $ADOPTION_STATUS"
    assert_equal "$SCENARIO_DIR/expected-config.json" "$SCENARIO_CONFIG"
    assert_equal "$SCENARIO_DIR/expected-config.json" "$SCENARIO_STATE/unmanaged-config.original.json"
    [ "$(stat -c '%a' "$SCENARIO_STATE/unmanaged-config.original.json")" = "400" ] || fail "исходная unmanaged-копия получила небезопасные права"
    expected_checksum="$(sha256sum "$SCENARIO_CONFIG" | awk '{print $1}')"
    [ "$(cat "$SCENARIO_STATE/adoption-approved")" = "$expected_checksum" ] || fail "разрешение managed-перехода не связано с SHA-256"
    [ "$(restart_count)" -eq 0 ] || fail "подтверждение перехода изменило состояние службы"

    run_apply 0
    [ "$APPLY_STATUS" -eq 0 ] || fail "подтверждённый managed-переход завершился кодом $APPLY_STATUS"
    assert_file "$SCENARIO_STATE/managed-config"
    assert_absent "$SCENARIO_STATE/adoption-approved"
    assert_equal "$SCENARIO_DIR/expected-config.json" "$SCENARIO_STATE/unmanaged-config.original.json"
    assert_log '"policy-dns-bootstrap"' "$SCENARIO_CONFIG"
    [ "$(restart_count)" -eq 1 ] || fail "managed-переход не выполнил один контролируемый restart"
}

test_adoption_checksum_invalidation()
{
    prepare_scenario adoption-invalidated
    printf '{"state":"unmanaged-original"}\n' > "$SCENARIO_CONFIG"
    run_adoption
    [ "$ADOPTION_STATUS" -eq 0 ] || fail "начальное подтверждение перехода завершилось кодом $ADOPTION_STATUS"

    printf '{"state":"changed-after-approval"}\n' > "$SCENARIO_CONFIG"
    cp "$SCENARIO_CONFIG" "$SCENARIO_DIR/expected-config.json"
    run_apply 1
    [ "$APPLY_STATUS" -eq 65 ] || fail "изменённая unmanaged-конфигурация вернула код $APPLY_STATUS вместо 65"
    assert_equal "$SCENARIO_DIR/expected-config.json" "$SCENARIO_CONFIG"
    assert_absent "$SCENARIO_STATE/managed-config"
    assert_file "$SCENARIO_STATE/adoption-approved"
    [ "$(restart_count)" -eq 0 ] || fail "несовпадающая SHA-256 привела к перезапуску службы"
    assert_log 'до подтверждения перехода для её текущей SHA-256' "$SCENARIO_STDERR"
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
    assert_log '"policy-dns-bootstrap"' "$SCENARIO_CONFIG"
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

test_network_preflight_failure()
{
    prepare_scenario network-preflight-failure
    seed_managed_runtime
    sed -i 's/, "192.0.2.70"//' "$SCENARIO_ENVIRONMENT"
    run_apply 0

    [ "$APPLY_STATUS" -eq 78 ] || fail "ошибка сетевого preflight вернула код $APPLY_STATUS вместо 78"
    assert_equal "$SCENARIO_DIR/expected-config.json" "$SCENARIO_CONFIG"
    assert_equal "$SCENARIO_DIR/expected-policy.json" "$SCENARIO_STATE/policy-plan.json"
    [ "$(restart_count)" -eq 0 ] || fail "ошибка сетевого preflight привела к перезапуску службы"
    assert_log 'Сетевой preflight не пройден' "$SCENARIO_STDERR"
    assert_log 'не назначен OPNsense' "$SCENARIO_STDERR"
}

trap cleanup EXIT HUP INT TERM
test_network_preflight_success
test_unmanaged_adoption_success
test_adoption_checksum_invalidation
test_running_service_success
test_stopped_service_deferred_activation
test_restart_failure_rollback
test_activation_mismatch_rollback
test_network_preflight_failure

echo "Транзакционный Apply, managed-переход, restart и rollback runtime-конфигурации проверены"
