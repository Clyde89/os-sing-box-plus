#!/bin/sh
set -eu
umask 077

PACKAGE_FILE="${1:-dist/os-sing-box.pkg}"
ROOT_DIR="$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"
PACKAGE_FILE="$(realpath "$PACKAGE_FILE")"
TEST_DIR="$(mktemp -d "${TMPDIR:-/tmp}/os-sing-box-freebsd-upgrade.XXXXXX")"
FIXTURE_ROOT="$TEST_DIR/fixture-root"
FIXTURE_META="$TEST_DIR/fixture-meta"
FIXTURE_PACKAGE="$TEST_DIR/os-sing-box-1.0.1.pkg"
UPGRADE_LOG="$TEST_DIR/upgrade.log"

cleanup()
{
    rm -rf "$TEST_DIR"
}
trap cleanup EXIT HUP INT TERM

fail()
{
    echo "Ошибка: $*" >&2
    exit 1
}

[ "$(uname -s)" = "FreeBSD" ] || fail "тест выполнялся не во FreeBSD"
[ "$(id -u)" -eq 0 ] || fail "для package upgrade-теста требовались права root"
[ -s "$PACKAGE_FILE" ] || fail "готовый package-артефакт отсутствовал"
if pkg info -e os-sing-box >/dev/null 2>&1; then
    fail "в чистой FreeBSD VM уже был установлен os-sing-box"
fi

mkdir -p \
    "$FIXTURE_ROOT/usr/local/etc/sing-box" \
    "$FIXTURE_ROOT/etc/rc.conf.d" \
    "$FIXTURE_META"

cp "$ROOT_DIR/src/usr/local/etc/sing-box/config.json.sample" \
    "$FIXTURE_ROOT/usr/local/etc/sing-box/config.json"
printf '%s\n' 'sing_box_enable="NO"' > \
    "$FIXTURE_ROOT/etc/rc.conf.d/sing_box"
chmod 0600 "$FIXTURE_ROOT/usr/local/etc/sing-box/config.json"
chmod 0644 "$FIXTURE_ROOT/etc/rc.conf.d/sing_box"

CONFIG_SHA256="$(sha256 -q "$FIXTURE_ROOT/usr/local/etc/sing-box/config.json")"
RC_SHA256="$(sha256 -q "$FIXTURE_ROOT/etc/rc.conf.d/sing_box")"
CONFIG_SIZE="$(stat -f '%z' "$FIXTURE_ROOT/usr/local/etc/sing-box/config.json")"
RC_SIZE="$(stat -f '%z' "$FIXTURE_ROOT/etc/rc.conf.d/sing_box")"
PKG_ABI="$(pkg config ABI)"
ABI_MAJOR="$(printf '%s\n' "$PKG_ABI" | awk -F: '{print $2}')"

cat > "$FIXTURE_META/+MANIFEST" <<EOF
name: "os-sing-box"
origin: "opnsense/os-sing-box"
version: "1.0.1"
comment: "Legacy fixture для package upgrade-теста"
maintainer: "nobody@example.invalid"
www: "https://example.invalid"
abi: "$PKG_ABI"
arch: "freebsd:${ABI_MAJOR}:x86:64"
prefix: "/usr/local"
categories: [ "net" ]
flatsize: $((CONFIG_SIZE + RC_SIZE))
desc: "Минимальный legacy fixture для проверки обновления os-sing-box."
files: {
    "/usr/local/etc/sing-box/config.json": "1\$$CONFIG_SHA256"
    "/etc/rc.conf.d/sing_box": "1\$$RC_SHA256"
}
EOF
cp "$FIXTURE_META/+MANIFEST" "$FIXTURE_META/+COMPACT_MANIFEST"
cp "$FIXTURE_META/+MANIFEST" "$FIXTURE_ROOT/+MANIFEST"
cp "$FIXTURE_META/+COMPACT_MANIFEST" "$FIXTURE_ROOT/+COMPACT_MANIFEST"

cat > "$TEST_DIR/tarlist" <<'EOF'
+COMPACT_MANIFEST
+MANIFEST
etc/rc.conf.d/sing_box
usr/local/etc/sing-box/config.json
EOF

tar -cPzf "$FIXTURE_PACKAGE" \
    -C "$FIXTURE_ROOT" \
    -s ',^etc,/etc,' \
    -s ',^usr,/usr,' \
    -T "$TEST_DIR/tarlist"
pkg info -F "$FIXTURE_PACKAGE" >/dev/null

pkg add -f "$FIXTURE_PACKAGE" >/dev/null
[ "$(pkg query '%v' os-sing-box)" = "1.0.1" ] \
    || fail "legacy fixture не установился"
[ "$(sha256 -q /usr/local/etc/sing-box/config.json)" = "$CONFIG_SHA256" ] \
    || fail "legacy config.json изменился до обновления"

if ! pkg add -f "$PACKAGE_FILE" >"$UPGRADE_LOG" 2>&1; then
    sed -n '1,240p' "$UPGRADE_LOG" >&2
    fail "реальное обновление package завершилось ошибкой"
fi

if grep -Eiq 'script failed|syntax error|error in command substitution' "$UPGRADE_LOG"; then
    sed -n '1,240p' "$UPGRADE_LOG" >&2
    fail "в журнале реального обновления обнаружилась ошибка lifecycle-сценария"
fi

grep -Fq 'Сохраняется пользовательская конфигурация sing-box' "$UPGRADE_LOG" \
    || fail "PRE-INSTALL не подтвердил сохранение config.json"
grep -Fq 'Восстанавливается пользовательская конфигурация sing-box' "$UPGRADE_LOG" \
    || fail "POST-INSTALL не подтвердил восстановление config.json"

[ "$(pkg query '%v' os-sing-box)" = "1.1.0" ] \
    || fail "после обновления установилась неправильная версия"
[ "$(sha256 -q /usr/local/etc/sing-box/config.json)" = "$CONFIG_SHA256" ] \
    || fail "рабочий config.json изменился при реальном обновлении"
[ "$(stat -f '%Lp' /usr/local/etc/sing-box/config.json)" = "600" ] \
    || fail "режим config.json не сохранился"
[ "$(stat -f '%Lp' /usr/local/etc/sing-box/readiness.conf)" = "600" ] \
    || fail "readiness.conf не был создан безопасно"
grep -Fqx 'sing_box_enable="NO"' /etc/rc.conf.d/sing_box \
    || fail "состояние автозапуска не сохранилось"

for package_owned_file in \
    /usr/local/bin/sing-box \
    /usr/local/etc/rc.d/sing-box \
    /usr/local/opnsense/scripts/OPNsense/SingBox/system_resolver.php \
    /usr/local/share/os-sing-box/build-info
do
    [ "$(stat -f '%Su:%Sg' "$package_owned_file")" = "root:wheel" ] \
        || fail "package-owned файл имел владельца, отличного от root:wheel: $package_owned_file"
done

if pkg which -q /usr/local/etc/sing-box/config.json >/dev/null 2>&1; then
    fail "после обновления пользовательский config.json остался package-owned"
fi

for marker in \
    /var/db/os-sing-box/config.json.upgrade \
    /var/db/os-sing-box/sing_box.rc.upgrade \
    /var/db/os-sing-box/pre-install.started \
    /var/db/os-sing-box/pre-install.complete
do
    [ ! -e "$marker" ] || fail "после обновления остался marker: $marker"
done

pkg check -s os-sing-box >/dev/null \
    || fail "контрольные суммы установленного пакета не совпали"

echo "Реальное обновление FreeBSD package 1.0.1 → 1.1.0 проверено"
