#!/bin/sh
set -eu

ROOT_DIR="$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"
SOURCE_HELPER="$ROOT_DIR/src/usr/local/opnsense/scripts/OPNsense/SingBox/policy_readiness.php"
TEST_ROOT="$(mktemp -d)"
trap 'rm -rf "$TEST_ROOT"' EXIT HUP INT TERM

MOCK_SOCKSTAT="$TEST_ROOT/sockstat"
HELPER="$TEST_ROOT/policy_readiness.php"
PLAN="$TEST_ROOT/policy-plan.json"
INVALID_PLAN="$TEST_ROOT/invalid-policy-plan.json"

cat > "$MOCK_SOCKSTAT" <<'EOF'
#!/bin/sh
printf '%s\n' 'USER COMMAND PID FD PROTO LOCAL ADDRESS FOREIGN ADDRESS'
case "${MOCK_SOCKSTAT_MODE:-both}" in
    both)
        printf 'root sing-box %s 6 tcp4 %s:%s *:*\n' "$MOCK_SOCKET_PID" "$MOCK_SOCKET_ADDRESS" "$MOCK_SOCKET_PORT"
        printf 'root sing-box %s 7 udp4 %s:%s *:*\n' "$MOCK_SOCKET_PID" "$MOCK_SOCKET_ADDRESS" "$MOCK_SOCKET_PORT"
        ;;
    tcp)
        printf 'root sing-box %s 6 tcp4 %s:%s *:*\n' "$MOCK_SOCKET_PID" "$MOCK_SOCKET_ADDRESS" "$MOCK_SOCKET_PORT"
        ;;
    error)
        exit 1
        ;;
esac
EOF
chmod 0755 "$MOCK_SOCKSTAT"

sed \
    -e "s#/usr/local/opnsense#$ROOT_DIR/src/usr/local/opnsense#g" \
    -e "s#/usr/bin/sockstat#$MOCK_SOCKSTAT#g" \
    "$SOURCE_HELPER" > "$HELPER"

cat > "$PLAN" <<'JSON'
{
  "schema_version": 2,
  "managed_by": "os-sing-box-plus",
  "required": true,
  "ready": true,
  "confirmation_required": false,
  "capture_mode": "selected",
  "capture_interfaces": ["lan"],
  "source_ip_cidr": ["192.0.2.10/32"],
  "domain": ["example.org"],
  "domain_suffix": [],
  "dns_listener": {"address": "127.0.0.1", "port": 55353},
  "dns_redirect": {
    "required": true,
    "ready": true,
    "interfaces": ["lan"],
    "protocols": ["udp", "tcp"],
    "destination_port": 53,
    "source_ip_cidr": ["192.0.2.10/32"],
    "target_address": "127.0.0.1",
    "target_port": 55353,
    "scope": "selected"
  },
  "fakeip_route": {"required": true, "ready": true, "network": "198.18.0.0/15", "interface": "tun_singbox", "mode": "sing_box_auto_route"},
  "policy_outbound": {"required": true, "ready": true, "mode": "direct_bind", "tag": "policy-out", "bind_address": "192.0.2.70", "gateway": "VPN_GW", "fail_closed": true},
  "tun_interface": "tun_singbox",
  "tun_address": "172.19.0.1/30",
  "fakeip_ipv4_range": "198.18.0.0/15",
  "dns_query_types": ["A"],
  "requires_opnsense_dns_redirect": true,
  "requires_opnsense_fakeip_route": false,
  "requires_singbox_fakeip_route": true,
  "requires_opnsense_policy_route": true,
  "requires_policy_outbound": true,
  "operations": [
    {"id":"dns-redirect-lan-udp","type":"dns_redirect","interface":"lan","protocol":"udp","source_ip_cidr":["192.0.2.10/32"],"destination_port":53,"target_address":"127.0.0.1","target_port":55353,"scope":"selected"},
    {"id":"dns-redirect-lan-tcp","type":"dns_redirect","interface":"lan","protocol":"tcp","source_ip_cidr":["192.0.2.10/32"],"destination_port":53,"target_address":"127.0.0.1","target_port":55353,"scope":"selected"},
    {"id":"policy-outbound-route","type":"policy_route","source_address":"192.0.2.70","gateway":"VPN_GW"},
    {"id":"policy-outbound-block","type":"policy_block","source_address":"192.0.2.70"}
  ]
}
JSON

php -r '$plan = json_decode(file_get_contents($argv[1]), true); $plan["dns_listener"]["port"] = 55354; file_put_contents($argv[2], json_encode($plan));' "$PLAN" "$INVALID_PLAN"

runCase()
{
    expected_status="$1"
    mode="$2"
    socket_pid="$3"
    socket_address="$4"
    output="$TEST_ROOT/output"

    set +e
    MOCK_SOCKSTAT_MODE="$mode" \
        MOCK_SOCKET_PID="$socket_pid" \
        MOCK_SOCKET_ADDRESS="$socket_address" \
        MOCK_SOCKET_PORT=55353 \
        php "$HELPER" --plan "$PLAN" --pid 4242 > "$output" 2>&1
    actual_status=$?
    set -e
    [ "$actual_status" -eq "$expected_status" ] || {
        cat "$output" >&2
        echo "Ожидался код $expected_status, получен $actual_status" >&2
        exit 1
    }
}

runCase 0 both 4242 127.0.0.1
runCase 75 tcp 4242 127.0.0.1
runCase 75 both 9999 127.0.0.1
runCase 75 both 4242 127.0.0.2
runCase 69 error 4242 127.0.0.1

set +e
MOCK_SOCKSTAT_MODE=both MOCK_SOCKET_PID=4242 MOCK_SOCKET_ADDRESS=127.0.0.1 MOCK_SOCKET_PORT=55353 \
    php "$HELPER" --plan "$INVALID_PLAN" --pid 4242 > "$TEST_ROOT/invalid.output" 2>&1
invalid_status=$?
set -e
[ "$invalid_status" -eq 65 ] || {
    cat "$TEST_ROOT/invalid.output" >&2
    echo "Некорректный policy-план вернул код $invalid_status вместо 65" >&2
    exit 1
}

echo "Readiness TCP/UDP DNS listener и привязка к PID sing-box проверены"
