#!/bin/sh
set -eu

ROOT_DIR="$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"
PRE_INSTALL="$ROOT_DIR/packaging/freebsd/+PRE_INSTALL"
POST_INSTALL="$ROOT_DIR/packaging/freebsd/+POST_INSTALL"
TEST_DIR="$(mktemp -d "${TMPDIR:-/tmp}/sing-box-package-lifecycle.XXXXXX")"
MOCK_BIN="$TEST_DIR/bin"

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

assert_mode()
{
    expected="$1"
    path="$2"

    if mode="$(stat -f '%Lp' "$path" 2>/dev/null)"; then
        :
    else
        mode="$(stat -c '%a' "$path")"
    fi

    [ "$mode" = "$expected" ] || fail "режим $path равен $mode вместо $expected"
}

assert_log()
{
    pattern="$1"
    log_file="$2"
    grep -Fq "$pattern" "$log_file" || fail "в журнале отсутствует запись: $pattern"
}

assert_log_absent()
{
    pattern="$1"
    log_file="$2"
    if grep -Fq "$pattern" "$log_file"; then
        fail "в журнале обнаружена неожиданная запись: $pattern"
    fi
}

write_mocks()
{
    mkdir -p "$MOCK_BIN"

    cat > "$MOCK_BIN/install" <<'SH'
#!/bin/sh
set -eu

directory=0
mode=""

while [ "$#" -gt 0 ]; do
    case "$1" in
        -d)
            directory=1
            shift
            ;;
        -o|-g)
            [ "$#" -ge 2 ] || exit 64
            shift 2
            ;;
        -m)
            [ "$#" -ge 2 ] || exit 64
            mode="$2"
            shift 2
            ;;
        --)
            shift
            break
            ;;
        -*)
            echo "Неподдерживаемый параметр mock install: $1" >&2
            exit 64
            ;;
        *)
            break
            ;;
    esac
done

if [ "$directory" -eq 1 ]; then
    [ "$#" -gt 0 ] || exit 64
    for target in "$@"; do
        mkdir -p "$target"
        if [ -n "$mode" ]; then
            chmod "$mode" "$target"
        fi
    done
    exit 0
fi

[ "$#" -eq 2 ] || exit 64
source_file="$1"
target_file="$2"
mkdir -p "$(dirname -- "$target_file")"
cp "$source_file" "$target_file"
if [ -n "$mode" ]; then
    chmod "$mode" "$target_file"
fi
SH

    cat > "$MOCK_BIN/pkg" <<'SH'
#!/bin/sh
set -eu

case "${1:-}" in
    query)
        [ "${MOCK_INSTALLED_VERSION:-}" != "" ] || exit 1
        printf '%s\n' "$MOCK_INSTALLED_VERSION"
        ;;
    version)
        [ "${2:-}" = "-t" ] || exit 64
        installed="${3:-}"
        cutoff="${4:-}"
        if [ "$installed" = "$cutoff" ]; then
            printf '=\n'
        elif [ "$installed" = "1.0.1" ] && [ "$cutoff" = "1.0.2" ]; then
            printf '<\n'
        else
            printf '>\n'
        fi
        ;;
    *)
        exit 64
        ;;
esac
SH

    cat > "$MOCK_BIN/service" <<'SH'
#!/bin/sh
set -eu

printf 'service %s\n' "$*" >> "$MOCK_LOG"
if [ "$*" = "sing-box restart" ] && [ "${MOCK_SERVICE_FAIL:-0}" -eq 1 ]; then
    exit 1
fi
SH

    cat > "$MOCK_BIN/configctl" <<'SH'
#!/bin/sh
set -eu

printf 'configctl %s\n' "$*" >> "$MOCK_LOG"
if [ "$*" = "filter reload" ] && [ "${MOCK_CONFIGCTL_FAIL:-0}" -eq 1 ]; then
    exit 1
fi
SH

    cat > "$MOCK_BIN/chown" <<'SH'
#!/bin/sh
exit 0
SH

    cat > "$MOCK_BIN/php" <<'SH'
#!/bin/sh
set -eu

printf 'php %s\n' "$*" >> "$MOCK_LOG"
if [ "${MOCK_PHP_FAIL:-0}" -eq 1 ]; then
    exit 1
fi
SH

    chmod 0755 "$MOCK_BIN/install" "$MOCK_BIN/pkg" "$MOCK_BIN/service" \
        "$MOCK_BIN/configctl" "$MOCK_BIN/chown" "$MOCK_BIN/php"
}

prepare_scenario()
{
    name="$1"
    SCENARIO_DIR="$TEST_DIR/$name"
    SCENARIO_ROOT="$SCENARIO_DIR/root"
    SCENARIO_LOG="$SCENARIO_DIR/commands.log"

    mkdir -p \
        "$SCENARIO_ROOT/usr/local/etc/sing-box" \
        "$SCENARIO_ROOT/usr/local/bin" \
        "$SCENARIO_ROOT/usr/local/opnsense/scripts/OPNsense/SingBox" \
        "$SCENARIO_ROOT/etc/rc.conf.d" \
        "$SCENARIO_ROOT/conf"
    : > "$SCENARIO_LOG"

    printf '{"marker":"sample"}\n' > \
        "$SCENARIO_ROOT/usr/local/etc/sing-box/config.json.sample"
    printf 'SING_BOX_READINESS_ENABLE="NO"\n' > \
        "$SCENARIO_ROOT/usr/local/etc/sing-box/readiness.conf.sample"
    printf '<?php\n' > \
        "$SCENARIO_ROOT/usr/local/opnsense/scripts/OPNsense/SingBox/migrate_legacy.php"
    cp "$MOCK_BIN/php" "$SCENARIO_ROOT/usr/local/bin/php"

    sed -E \
        -e 's#/usr/local#@TEST_USR_LOCAL@#g' \
        -e 's#/var#@TEST_VAR@#g' \
        -e 's#(^|[[:space:]"])/etc#\1@TEST_ETC@#g' \
        -e 's#(^|[[:space:]"])/conf#\1@TEST_CONF@#g' \
        -e "s#@TEST_USR_LOCAL@#$SCENARIO_ROOT/usr/local#g" \
        -e "s#@TEST_ETC@#$SCENARIO_ROOT/etc#g" \
        -e "s#@TEST_CONF@#$SCENARIO_ROOT/conf#g" \
        -e "s#@TEST_VAR@#$SCENARIO_ROOT/var#g" \
        "$PRE_INSTALL" > "$SCENARIO_DIR/pre-install"
    sed -E \
        -e 's#/usr/local#@TEST_USR_LOCAL@#g' \
        -e 's#/var#@TEST_VAR@#g' \
        -e 's#(^|[[:space:]"])/etc#\1@TEST_ETC@#g' \
        -e 's#(^|[[:space:]"])/conf#\1@TEST_CONF@#g' \
        -e "s#@TEST_USR_LOCAL@#$SCENARIO_ROOT/usr/local#g" \
        -e "s#@TEST_ETC@#$SCENARIO_ROOT/etc#g" \
        -e "s#@TEST_CONF@#$SCENARIO_ROOT/conf#g" \
        -e "s#@TEST_VAR@#$SCENARIO_ROOT/var#g" \
        "$POST_INSTALL" > "$SCENARIO_DIR/post-install"
}

seed_legacy_installation()
{
    state="$1"

    printf '{"marker":"custom-%s"}\n' "$state" > \
        "$SCENARIO_ROOT/usr/local/etc/sing-box/config.json"
    printf 'sing_box_enable="%s"\n' "$state" > \
        "$SCENARIO_ROOT/etc/rc.conf.d/sing_box"
    printf '<opnsense><marker>%s</marker></opnsense>\n' "$state" > \
        "$SCENARIO_ROOT/conf/config.xml"

    cp "$SCENARIO_ROOT/usr/local/etc/sing-box/config.json" \
        "$SCENARIO_DIR/expected-config.json"
    cp "$SCENARIO_ROOT/etc/rc.conf.d/sing_box" \
        "$SCENARIO_DIR/expected-rc"
    cp "$SCENARIO_ROOT/conf/config.xml" \
        "$SCENARIO_DIR/expected-config.xml"
}

run_pre_install()
{
    installed_version="$1"

    MOCK_INSTALLED_VERSION="$installed_version" \
        MOCK_LOG="$SCENARIO_LOG" \
        PATH="$MOCK_BIN:/usr/bin:/bin" \
        sh "$SCENARIO_DIR/pre-install"
}

run_post_install()
{
    php_fail="${1:-0}"
    service_fail="${2:-0}"
    configctl_fail="${3:-0}"

    MOCK_PHP_FAIL="$php_fail" \
        MOCK_SERVICE_FAIL="$service_fail" \
        MOCK_CONFIGCTL_FAIL="$configctl_fail" \
        MOCK_LOG="$SCENARIO_LOG" \
        PATH="$MOCK_BIN:/usr/bin:/bin" \
        sh "$SCENARIO_DIR/post-install"
}

remove_installed_state()
{
    rm -f \
        "$SCENARIO_ROOT/usr/local/etc/sing-box/config.json" \
        "$SCENARIO_ROOT/etc/rc.conf.d/sing_box"
}

assert_migration_preserved()
{
    migration_dir="$SCENARIO_ROOT/var/db/os-sing-box"

    assert_file "$migration_dir/config.json.upgrade"
    assert_file "$migration_dir/sing_box.rc.upgrade"
    assert_file "$migration_dir/config.xml.legacy"
    assert_file "$migration_dir/legacy-version"
}

test_successful_enabled_upgrade()
{
    prepare_scenario successful-enabled-upgrade
    seed_legacy_installation YES
    run_pre_install 1.0.1

    migration_dir="$SCENARIO_ROOT/var/db/os-sing-box"
    assert_mode 700 "$migration_dir"
    assert_equal "$SCENARIO_DIR/expected-config.json" "$migration_dir/config.json.upgrade"
    assert_equal "$SCENARIO_DIR/expected-rc" "$migration_dir/sing_box.rc.upgrade"
    assert_equal "$SCENARIO_DIR/expected-config.xml" "$migration_dir/config.xml.legacy"
    assert_mode 600 "$migration_dir/config.json.upgrade"
    assert_mode 600 "$migration_dir/sing_box.rc.upgrade"
    assert_mode 600 "$migration_dir/config.xml.legacy"
    assert_mode 600 "$migration_dir/legacy-version"
    [ "$(cat "$migration_dir/legacy-version")" = "1.0.1" ] || \
        fail "версия legacy-пакета не была сохранена"

    printf '<opnsense><marker>changed</marker></opnsense>\n' > \
        "$SCENARIO_ROOT/conf/config.xml"
    run_pre_install 1.0.1
    assert_equal "$SCENARIO_DIR/expected-config.xml" "$migration_dir/config.xml.legacy"

    remove_installed_state
    run_post_install

    assert_equal "$SCENARIO_DIR/expected-config.json" \
        "$SCENARIO_ROOT/usr/local/etc/sing-box/config.json"
    assert_equal "$SCENARIO_DIR/expected-rc" \
        "$SCENARIO_ROOT/etc/rc.conf.d/sing_box"
    assert_mode 600 "$SCENARIO_ROOT/usr/local/etc/sing-box/config.json"
    assert_mode 644 "$SCENARIO_ROOT/etc/rc.conf.d/sing_box"
    assert_mode 600 "$SCENARIO_ROOT/usr/local/etc/sing-box/readiness.conf"
    assert_log "php $migration_dir/config.xml.legacy" "$SCENARIO_LOG"
    assert_log "service configd restart" "$SCENARIO_LOG"
    assert_log "service sing-box restart" "$SCENARIO_LOG"
    assert_log "configctl filter reload" "$SCENARIO_LOG"
    assert_absent "$SCENARIO_ROOT/var/db/os-sing-box"
}

test_fresh_install()
{
    prepare_scenario fresh-install
    cp "$SCENARIO_ROOT/usr/local/etc/sing-box/config.json.sample" \
        "$SCENARIO_DIR/expected-sample.json"
    run_pre_install ""
    run_post_install

    assert_equal "$SCENARIO_DIR/expected-sample.json" \
        "$SCENARIO_ROOT/usr/local/etc/sing-box/config.json"
    assert_mode 600 "$SCENARIO_ROOT/usr/local/etc/sing-box/config.json"
    assert_mode 600 "$SCENARIO_ROOT/usr/local/etc/sing-box/readiness.conf"
    assert_mode 644 "$SCENARIO_ROOT/etc/rc.conf.d/sing_box"
    grep -Fqx 'sing_box_enable="NO"' \
        "$SCENARIO_ROOT/etc/rc.conf.d/sing_box" || \
        fail "первая установка не оставила автозапуск отключённым"
    assert_file "$SCENARIO_ROOT/var/db/os-sing-box/setup-required"
    assert_mode 600 "$SCENARIO_ROOT/var/db/os-sing-box/setup-required"
    assert_log "service configd restart" "$SCENARIO_LOG"
    assert_log_absent "service sing-box restart" "$SCENARIO_LOG"
    assert_log_absent "configctl filter reload" "$SCENARIO_LOG"
    assert_log_absent "php " "$SCENARIO_LOG"
}

test_migrator_failure()
{
    prepare_scenario migrator-failure
    seed_legacy_installation YES
    run_pre_install 1.0.1
    remove_installed_state

    if run_post_install 1 0 0; then
        fail "ошибка legacy-мигратора не остановила обновление"
    fi

    assert_migration_preserved
    assert_equal "$SCENARIO_DIR/expected-config.json" \
        "$SCENARIO_ROOT/usr/local/etc/sing-box/config.json"
    assert_equal "$SCENARIO_DIR/expected-rc" \
        "$SCENARIO_ROOT/etc/rc.conf.d/sing_box"
    assert_log "php $SCENARIO_ROOT/var/db/os-sing-box/config.xml.legacy" "$SCENARIO_LOG"
    assert_log_absent "service sing-box restart" "$SCENARIO_LOG"
    assert_log_absent "configctl filter reload" "$SCENARIO_LOG"
}

test_service_restart_failure()
{
    prepare_scenario service-restart-failure
    seed_legacy_installation YES
    run_pre_install 1.0.1
    remove_installed_state

    if run_post_install 0 1 0; then
        fail "ошибка перезапуска sing-box не остановила legacy-обновление"
    fi

    assert_migration_preserved
    assert_log "service sing-box restart" "$SCENARIO_LOG"
    assert_log_absent "configctl filter reload" "$SCENARIO_LOG"
}

test_filter_reload_failure()
{
    prepare_scenario filter-reload-failure
    seed_legacy_installation YES
    run_pre_install 1.0.1
    remove_installed_state

    if run_post_install 0 0 1; then
        fail "ошибка firewall reload не остановила legacy-обновление"
    fi

    assert_migration_preserved
    assert_log "service sing-box restart" "$SCENARIO_LOG"
    assert_log "configctl filter reload" "$SCENARIO_LOG"
    assert_absent "$SCENARIO_ROOT/var/db/os-sing-box/filter-reload.pending"
}

test_disabled_upgrade()
{
    prepare_scenario disabled-upgrade
    seed_legacy_installation NO
    run_pre_install 1.0.1
    remove_installed_state
    run_post_install

    migration_dir="$SCENARIO_ROOT/var/db/os-sing-box"
    assert_file "$migration_dir/filter-reload.pending"
    assert_mode 600 "$migration_dir/filter-reload.pending"
    assert_absent "$migration_dir/config.json.upgrade"
    assert_absent "$migration_dir/sing_box.rc.upgrade"
    assert_absent "$migration_dir/config.xml.legacy"
    assert_absent "$migration_dir/legacy-version"
    assert_log_absent "service sing-box restart" "$SCENARIO_LOG"
    assert_log_absent "configctl filter reload" "$SCENARIO_LOG"
}

trap cleanup EXIT HUP INT TERM
write_mocks
test_successful_enabled_upgrade
test_fresh_install
test_migrator_failure
test_service_restart_failure
test_filter_reload_failure
test_disabled_upgrade

echo "Жизненный цикл установки и legacy-обновления пакета проверен"
