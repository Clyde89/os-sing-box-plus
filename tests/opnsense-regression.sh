#!/bin/sh
set -u

# Только чтение: сценарий не меняет конфигурацию, не перезапускает службы и не
# управляет интерфейсами. Reboot и WAN flap выполняются оператором отдельно.

PACKAGE_NAME="os-sing-box"
PACKAGE_ORIGIN="opnsense/os-sing-box"
BUILD_INFO="/usr/local/share/os-sing-box/build-info"
SING_BOX_BINARY="/usr/local/bin/sing-box"
CONFIG_FILE="/usr/local/etc/sing-box/config.json"
READINESS_CONFIG="/usr/local/etc/sing-box/readiness.conf"
RC_FILE="/usr/local/etc/rc.d/sing-box"
RC_STATE="/etc/rc.conf.d/sing_box"
PID_FILE="/var/run/sing-box.pid"
STATE_DIR="/var/db/os-sing-box"
MANAGED_POLICY="$STATE_DIR/managed-policy"
POLICY_PLAN="$STATE_DIR/policy-plan.json"
TUN_INTERFACE_FILE="$STATE_DIR/tun-interface"
POLICY_ACTIVE="/var/run/sing-box-policy-active"
POLICY_READINESS="/usr/local/opnsense/scripts/OPNsense/SingBox/policy_readiness.php"
STAGE="manual"
REQUIRE_MANAGED=0
NETWORK_CHECK=0
PASS_COUNT=0
WARN_COUNT=0
FAIL_COUNT=0

usage()
{
    cat <<'EOF'
Использование: opnsense-regression.sh [параметры]

  --stage post-upgrade|post-reboot|post-wan  Метка контрольного этапа
  --require-managed                         Требовался активный managed policy
  --network                                 Выполнялась E2E-проверка VPN egress
  --help                                    Показана эта справка

Для --network задавались переменные:
  REGRESSION_EGRESS_NAME          Домен IP-echo endpoint из policy-селектора
  REGRESSION_EXPECTED_EGRESS_IP   Ожидаемый публичный IPv4 VPN
  REGRESSION_EGRESS_PORT          HTTPS-порт, по умолчанию 443
  REGRESSION_EGRESS_URL           Полный URL, по умолчанию https://домен:порт/

Ожидаемую версию пакета можно задать в REGRESSION_EXPECTED_PACKAGE_VERSION.
EOF
}

pass()
{
    PASS_COUNT=$((PASS_COUNT + 1))
    printf 'PASS  %s\n' "$*"
}

warn()
{
    WARN_COUNT=$((WARN_COUNT + 1))
    printf 'WARN  %s\n' "$*"
}

fail()
{
    FAIL_COUNT=$((FAIL_COUNT + 1))
    printf 'FAIL  %s\n' "$*" >&2
}

build_value()
{
    key="$1"
    awk -F= -v wanted="$key" '$1 == wanted {sub(/^[^=]*=/, ""); print; exit}' "$BUILD_INFO" 2>/dev/null
}

readiness_value()
{
    key="$1"
    sed -n "s/^${key}=\"\([^\"]*\)\"$/\1/p" "$READINESS_CONFIG" 2>/dev/null | tail -n 1
}

check_mode()
{
    path="$1"
    expected="$2"
    label="$3"

    if [ ! -e "$path" ]; then
        fail "$label отсутствовал"
        return
    fi
    actual="$(stat -f '%Lp' "$path" 2>/dev/null || true)"
    if [ "$actual" = "$expected" ]; then
        pass "$label имел режим $expected"
    else
        fail "$label имел режим ${actual:-неизвестный}, ожидался $expected"
    fi
}

check_owned_file()
{
    path="$1"
    label="$2"
    owner="$(pkg which -q "$path" 2>/dev/null || true)"
    case "$owner" in
        "$PACKAGE_NAME"-*) pass "$label принадлежал установленному пакету" ;;
        *) fail "$label не принадлежал установленному пакету" ;;
    esac
}

while [ "$#" -gt 0 ]; do
    case "$1" in
        --stage)
            [ "$#" -ge 2 ] || { usage >&2; exit 64; }
            STAGE="$2"
            shift 2
            ;;
        --require-managed)
            REQUIRE_MANAGED=1
            shift
            ;;
        --network)
            NETWORK_CHECK=1
            shift
            ;;
        --help)
            usage
            exit 0
            ;;
        *)
            usage >&2
            exit 64
            ;;
    esac
done

case "$STAGE" in
    manual|post-upgrade|post-reboot|post-wan) ;;
    *) usage >&2; exit 64 ;;
esac

printf '=== os-sing-box: regression %s ===\n' "$STAGE"
printf 'Режим: только чтение\n'

if [ "$(uname -s 2>/dev/null || true)" = "FreeBSD" ] && [ -r /usr/local/opnsense/version/core ]; then
    pass "среда OPNsense подтверждена"
else
    fail "сценарий запускался не в OPNsense"
fi

if [ "$(id -u 2>/dev/null || true)" = "0" ]; then
    pass "права root подтверждены"
else
    fail "для полной проверки требовались права root"
fi

for command_name in pkg sha256 stat service configctl ifconfig ps awk sed grep; do
    if command -v "$command_name" >/dev/null 2>&1; then
        pass "команда $command_name была доступна"
    else
        fail "команда $command_name отсутствовала"
    fi
done

PACKAGE_RECORD="$(pkg query '%n|%v|%o' "$PACKAGE_NAME" 2>/dev/null || true)"
PACKAGE_VERSION="$(printf '%s\n' "$PACKAGE_RECORD" | awk -F'|' 'NR == 1 {print $2}')"
PACKAGE_INSTALLED_ORIGIN="$(printf '%s\n' "$PACKAGE_RECORD" | awk -F'|' 'NR == 1 {print $3}')"
if [ -n "$PACKAGE_VERSION" ]; then
    pass "пакет $PACKAGE_NAME версии $PACKAGE_VERSION был установлен"
else
    fail "пакет $PACKAGE_NAME не был найден"
fi
if [ "$PACKAGE_INSTALLED_ORIGIN" = "$PACKAGE_ORIGIN" ]; then
    pass "origin пакета совпал с $PACKAGE_ORIGIN"
else
    fail "origin пакета не совпал с ожидаемым"
fi

EXPECTED_PACKAGE_VERSION="${REGRESSION_EXPECTED_PACKAGE_VERSION:-}"
if [ -n "$EXPECTED_PACKAGE_VERSION" ]; then
    if [ "$PACKAGE_VERSION" = "$EXPECTED_PACKAGE_VERSION" ]; then
        pass "версия пакета совпала с ожидаемой"
    else
        fail "версия пакета не совпала с ожидаемой $EXPECTED_PACKAGE_VERSION"
    fi
fi

if pkg check -s "$PACKAGE_NAME" >/dev/null 2>&1; then
    pass "контрольные суммы package-owned файлов совпали"
else
    fail "pkg check -s обнаружил изменение или отсутствие package-owned файлов"
fi

for owned_path in "$SING_BOX_BINARY" "$RC_FILE" "$POLICY_READINESS" "$BUILD_INFO"; do
    check_owned_file "$owned_path" "$owned_path"
done

check_mode "$SING_BOX_BINARY" 755 "бинарный файл sing-box"
check_mode "$RC_FILE" 755 "rc.d-сценарий sing-box"
check_mode "$BUILD_INFO" 644 "сведения о сборке"
check_mode "$CONFIG_FILE" 600 "рабочая конфигурация sing-box"
check_mode "$READINESS_CONFIG" 600 "конфигурация readiness"
check_mode "$RC_STATE" 644 "состояние автозапуска sing-box"
check_mode /var/log/sing-box 750 "каталог журнала sing-box"
check_mode /var/log/sing-box/sing-box.log 640 "журнал sing-box"

if [ -r "$BUILD_INFO" ]; then
    INFO_FORMAT="$(build_value format_version)"
    INFO_PACKAGE="$(build_value package_name)"
    INFO_VERSION="$(build_value package_version)"
    INFO_ORIGIN="$(build_value package_origin)"
    CORE_RELEASE="$(build_value core_release)"
    CORE_ASSET_SHA256="$(build_value core_asset_sha256)"
    CORE_BINARY_SHA256="$(build_value core_binary_sha256)"

    if [ "$INFO_FORMAT" = "1" ] \
        && [ "$INFO_PACKAGE" = "$PACKAGE_NAME" ] \
        && [ "$INFO_VERSION" = "$PACKAGE_VERSION" ] \
        && [ "$INFO_ORIGIN" = "$PACKAGE_ORIGIN" ]; then
        pass "сведения о package build совпали с установленным пакетом"
    else
        fail "сведения о package build не совпали с установленным пакетом"
    fi
    case "$CORE_ASSET_SHA256:$CORE_BINARY_SHA256" in
        *[!0-9a-f]*:*|*:*[!0-9a-f]*|::*|*::|"")
            fail "сведения о происхождении ядра были неполными"
            ;;
        *)
            if [ -n "$CORE_RELEASE" ] \
                && [ "${#CORE_ASSET_SHA256}" -eq 64 ] \
                && [ "${#CORE_BINARY_SHA256}" -eq 64 ]; then
                pass "релиз и SHA-256 ядра были зафиксированы"
            else
                fail "SHA-256 ядра имели некорректную длину"
            fi
            ;;
    esac

    ACTUAL_BINARY_SHA256="$(sha256 -q "$SING_BOX_BINARY" 2>/dev/null || true)"
    if [ -n "$CORE_BINARY_SHA256" ] && [ "$ACTUAL_BINARY_SHA256" = "$CORE_BINARY_SHA256" ]; then
        pass "SHA-256 установленного ядра совпал со сведениями о сборке"
    else
        fail "SHA-256 установленного ядра не совпал со сведениями о сборке"
    fi
else
    fail "сведения о сборке отсутствовали или не читались"
fi

if "$SING_BOX_BINARY" version >/dev/null 2>&1; then
    pass "установленное ядро sing-box отвечало на version"
else
    fail "установленное ядро sing-box не отвечало на version"
fi
if "$SING_BOX_BINARY" check -c "$CONFIG_FILE" >/dev/null 2>&1; then
    pass "рабочая конфигурация прошла sing-box check"
else
    fail "рабочая конфигурация не прошла sing-box check"
fi

SERVICE_STATE="$(/usr/local/sbin/sing-box-service-config status 2>/dev/null || true)"
if [ "$SERVICE_STATE" = "YES" ]; then
    pass "автозапуск sing-box был включён"
else
    fail "автозапуск sing-box не был включён"
fi
if [ ! -e "$STATE_DIR/setup-required" ]; then
    pass "первоначальная настройка была завершена"
else
    fail "сохранялся признак незавершённой первоначальной настройки"
fi
if service sing-box status >/dev/null 2>&1; then
    pass "служба sing-box работала"
else
    fail "служба sing-box не работала"
fi

PID="$(cat "$PID_FILE" 2>/dev/null || true)"
case "$PID" in
    ''|*[!0-9]*)
        fail "PID sing-box отсутствовал или был некорректным"
        PID=""
        ;;
    *)
        PROCESS_NAME="$(ps -p "$PID" -o comm= 2>/dev/null | awk '{print $1}')"
        case "$PROCESS_NAME" in
            sing-box|*/sing-box) pass "PID-файл принадлежал процессу sing-box" ;;
            *) fail "PID-файл не принадлежал процессу sing-box" ;;
        esac
        ;;
esac

TUN_INTERFACE="$(cat "$TUN_INTERFACE_FILE" 2>/dev/null || true)"
case "$TUN_INTERFACE" in
    ''|*[!A-Za-z0-9_.-]*) TUN_INTERFACE="tun_singbox" ;;
esac
if ifconfig "$TUN_INTERFACE" >/dev/null 2>&1; then
    pass "TUN-интерфейс $TUN_INTERFACE существовал"
else
    fail "TUN-интерфейс $TUN_INTERFACE отсутствовал"
fi

PREFLIGHT_OUTPUT="$(configctl sing-box preflight 2>&1 || true)"
if printf '%s\n' "$PREFLIGHT_OUTPUT" | grep -Fq '"ready":true' \
    && printf '%s\n' "$PREFLIGHT_OUTPUT" | grep -Eq '(^|[[:space:]])OK([[:space:]]|$)'; then
    pass "сетевой preflight OPNsense был успешным"
else
    fail "сетевой preflight OPNsense завершился ошибкой"
fi

if [ -f "$MANAGED_POLICY" ]; then
    pass "managed policy был включён"
    check_mode "$STATE_DIR" 700 "каталог runtime-состояния"
    check_mode "$MANAGED_POLICY" 600 "признак managed policy"
    check_mode "$POLICY_PLAN" 600 "policy-план"
    check_mode "$POLICY_ACTIVE" 600 "активный SHA-256 policy-плана"

    PLAN_SHA256="$(sha256 -q "$POLICY_PLAN" 2>/dev/null || true)"
    ACTIVE_SHA256="$(cat "$POLICY_ACTIVE" 2>/dev/null || true)"
    if [ -n "$PLAN_SHA256" ] && [ "$ACTIVE_SHA256" = "$PLAN_SHA256" ]; then
        pass "активный SHA-256 совпал с policy-планом"
    else
        fail "активный SHA-256 не совпал с policy-планом"
    fi

    if [ -n "$PID" ] && /usr/local/bin/php "$POLICY_READINESS" --plan "$POLICY_PLAN" --pid "$PID" >/dev/null 2>&1; then
        pass "TCP/UDP DNS listener принадлежал текущему PID sing-box"
    else
        fail "readiness DNS listener текущего PID не подтвердилась"
    fi
elif [ "$REQUIRE_MANAGED" -eq 1 ]; then
    fail "обязательный managed policy не был включён"
else
    warn "managed policy отсутствовал; policy-специфичные проверки были пропущены"
fi

if [ "$NETWORK_CHECK" -eq 1 ]; then
    EGRESS_NAME="${REGRESSION_EGRESS_NAME:-}"
    EXPECTED_EGRESS_IP="${REGRESSION_EXPECTED_EGRESS_IP:-}"
    EGRESS_PORT="${REGRESSION_EGRESS_PORT:-443}"
    EGRESS_URL="${REGRESSION_EGRESS_URL:-}"
    SOURCE_IP="$(readiness_value SING_BOX_READINESS_SOURCE_IP)"
    UNDERLAY_IP="$(readiness_value SING_BOX_READINESS_UNDERLAY_IP)"
    UNDERLAY_PORT="$(readiness_value SING_BOX_READINESS_UNDERLAY_PORT)"
    DNS_IP="$(readiness_value SING_BOX_READINESS_DNS_LISTEN_IP)"
    DNS_PORT="$(readiness_value SING_BOX_READINESS_DNS_LISTEN_PORT)"
    POLICY_SUFFIX="$(readiness_value SING_BOX_READINESS_POLICY_SUFFIX)"
    FAKE_IP_RANGE="$(/usr/local/bin/php -r '
        $plan = json_decode(file_get_contents($argv[1]), true);
        echo is_array($plan) ? (string)($plan["fakeip_ipv4_range"] ?? "") : "";
    ' "$POLICY_PLAN" 2>/dev/null || true)"

    if [ -z "$EGRESS_URL" ] && [ -n "$EGRESS_NAME" ]; then
        EGRESS_URL="https://${EGRESS_NAME}:${EGRESS_PORT}/"
    fi
    if [ -z "$EGRESS_NAME" ] || [ -z "$EXPECTED_EGRESS_IP" ] || [ -z "$FAKE_IP_RANGE" ] \
        || [ -z "$SOURCE_IP" ] || [ -z "$UNDERLAY_IP" ] || [ -z "$UNDERLAY_PORT" ] \
        || [ -z "$DNS_IP" ] || [ -z "$DNS_PORT" ] || [ -z "$POLICY_SUFFIX" ]; then
        fail "для сетевой проверки не были заданы обязательные безопасные параметры"
    elif ! printf '%s\n' "$EGRESS_NAME" | grep -Eq '^[A-Za-z0-9][A-Za-z0-9.-]*[A-Za-z0-9]$' \
        || ! printf '%s\n' "$EGRESS_PORT" | grep -Eq '^[0-9]+$' \
        || [ "$EGRESS_PORT" -lt 1 ] || [ "$EGRESS_PORT" -gt 65535 ] \
        || ! /usr/local/bin/php -r 'exit(filter_var($argv[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? 0 : 1);' \
            "$EXPECTED_EGRESS_IP"; then
        fail "параметры E2E endpoint были некорректными"
    elif ! printf '%s\n' "$EGRESS_URL" | grep -Eq "^https://${EGRESS_NAME}(:${EGRESS_PORT})?(/|$)"; then
        fail "E2E URL не соответствовал HTTPS-домену проверки"
    elif ! command -v drill >/dev/null 2>&1 || ! command -v nc >/dev/null 2>&1 \
        || ! command -v curl >/dev/null 2>&1; then
        fail "для сетевой проверки отсутствовали drill, nc или curl"
    else
        if nc -s "$SOURCE_IP" -z -w 3 "$UNDERLAY_IP" "$UNDERLAY_PORT" >/dev/null 2>&1; then
            pass "underlay policy-пути был доступен с заданного source IP"
        else
            fail "underlay policy-пути был недоступен с заданного source IP"
        fi

        POLICY_PROBE="regression-$(date +%s)-$$.${POLICY_SUFFIX}"
        POLICY_DNS_OUTPUT="$(drill -p "$DNS_PORT" "$POLICY_PROBE" @"$DNS_IP" HTTPS 2>&1 || true)"
        if printf '%s\n' "$POLICY_DNS_OUTPUT" | grep -Eq 'rcode: (NOERROR|NXDOMAIN)'; then
            pass "policy DNS bootstrap отвечал через локальный listener"
        else
            fail "policy DNS bootstrap не отвечал через локальный listener"
        fi

        EGRESS_DNS_OUTPUT="$(drill -p "$DNS_PORT" "$EGRESS_NAME" @"$DNS_IP" A 2>&1 || true)"
        FAKE_IP="$(printf '%s\n' "$EGRESS_DNS_OUTPUT" | awk '$4 == "A" {print $5; exit}')"
        if [ -n "$FAKE_IP" ] && /usr/local/bin/php -r '
            $ip = ip2long($argv[1]);
            $parts = explode("/", $argv[2], 2);
            $network = isset($parts[0]) ? ip2long($parts[0]) : false;
            $prefix = isset($parts[1]) ? filter_var($parts[1], FILTER_VALIDATE_INT) : false;
            if ($ip === false || $network === false || $prefix === false || $prefix < 0 || $prefix > 32) {
                exit(1);
            }
            $mask = $prefix === 0 ? 0 : ((0xffffffff << (32 - $prefix)) & 0xffffffff);
            exit((($ip & $mask) === ($network & $mask)) ? 0 : 1);
        ' "$FAKE_IP" "$FAKE_IP_RANGE"; then
            pass "policy-домен разрешился в IPv4 FakeIP"
            EGRESS_RESULT="$(curl -4 -sS --noproxy '*' --connect-timeout 4 --max-time 10 \
                --resolve "${EGRESS_NAME}:${EGRESS_PORT}:${FAKE_IP}" "$EGRESS_URL" 2>/dev/null || true)"
            EGRESS_RESULT="$(printf '%s' "$EGRESS_RESULT" | tr -d '[:space:]')"
            if [ "$EGRESS_RESULT" = "$EXPECTED_EGRESS_IP" ]; then
                pass "E2E policy-поток вышел через ожидаемый VPN IPv4"
            else
                fail "E2E policy-поток не подтвердил ожидаемый VPN IPv4"
            fi
        else
            fail "policy-домен не разрешился в настроенный IPv4 FakeIP-диапазон"
        fi
    fi
fi

printf '=== Результат: PASS=%s WARN=%s FAIL=%s ===\n' "$PASS_COUNT" "$WARN_COUNT" "$FAIL_COUNT"
[ "$FAIL_COUNT" -eq 0 ]
