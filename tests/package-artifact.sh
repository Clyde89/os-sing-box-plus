#!/bin/sh
set -eu

ROOT_DIR="$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"

command -v bsdtar >/dev/null 2>&1 || {
    echo "Для проверки package-артефакта требуется bsdtar" >&2
    exit 69
}
command -v sha256sum >/dev/null 2>&1 || {
    echo "Для проверки package-артефакта требуется sha256sum" >&2
    exit 69
}

TEST_ROOT="$(mktemp -d)"
trap 'rm -rf "$TEST_ROOT"' EXIT HUP INT TERM
MOCK_BIN="$TEST_ROOT/bin"
WORK_DIR="$TEST_ROOT/work"
DIST_DIR="$TEST_ROOT/dist"
FAKE_BINARY="$TEST_ROOT/sing-box"
FAKE_ASSET="$TEST_ROOT/sing-box.xz"

mkdir -p "$MOCK_BIN" "$WORK_DIR" "$DIST_DIR"

cat > "$FAKE_BINARY" <<'EOF'
#!/bin/sh
case "${1:-}" in
    check)
        exit 0
        ;;
    version)
        echo "sing-box version 0.0.0-test"
        exit 0
        ;;
esac
exit 64
EOF
chmod 0755 "$FAKE_BINARY"
xz -c "$FAKE_BINARY" > "$FAKE_ASSET"

cat > "$MOCK_BIN/pkg" <<'EOF'
#!/bin/sh
if [ "${1:-}" = "info" ] && [ "${2:-}" = "-F" ] && [ -s "${3:-}" ]; then
    exit 0
fi
echo "Неподдерживаемый вызов тестового pkg: $*" >&2
exit 64
EOF

cat > "$MOCK_BIN/sha256" <<'EOF'
#!/bin/sh
if [ "${1:-}" = "-q" ] && [ "$#" -eq 2 ]; then
    sha256sum "$2" | awk '{print $1}'
    exit 0
fi
sha256sum "$@"
EOF

cat > "$MOCK_BIN/tar" <<'EOF'
#!/bin/sh
exec bsdtar "$@"
EOF

cat > "$MOCK_BIN/stat" <<'EOF'
#!/bin/sh
if [ "${1:-}" = "-f" ] && [ "${2:-}" = "%Lp" ] && [ "$#" -eq 3 ]; then
    /usr/bin/stat -c '%a' "$3"
    exit 0
fi
exec /usr/bin/stat "$@"
EOF

chmod 0755 "$MOCK_BIN/pkg" "$MOCK_BIN/sha256" "$MOCK_BIN/tar" "$MOCK_BIN/stat"

ASSET_SHA256="$(sha256sum "$FAKE_ASSET" | awk '{print $1}')"
BINARY_SHA256="$(sha256sum "$FAKE_BINARY" | awk '{print $1}')"

PATH="$MOCK_BIN:$PATH" \
WORKDIR="$WORK_DIR" \
DISTDIR="$DIST_DIR" \
OUTPUT_NAME="os-sing-box-test.pkg" \
VERSION="9.9.9-test" \
SING_BOX_RELEASE="v0.0.0-test" \
SING_BOX_ASSET="sing-box.xz" \
SING_BOX_LOCAL_ASSET="$FAKE_ASSET" \
SING_BOX_ASSET_SHA256="$ASSET_SHA256" \
SING_BOX_SHA256="$BINARY_SHA256" \
    "$ROOT_DIR/build.sh" >/dev/null

PACKAGE_FILE="$DIST_DIR/os-sing-box-test.pkg"
[ -s "$PACKAGE_FILE" ]

EXTRACT_DIR="$TEST_ROOT/extracted"
mkdir -p "$EXTRACT_DIR"
bsdtar -xzf "$PACKAGE_FILE" -C "$EXTRACT_DIR"
BUILD_INFO="$(cat "$EXTRACT_DIR/usr/local/share/os-sing-box/build-info")"
printf '%s\n' "$BUILD_INFO" | grep -Fxq 'format_version=1'
printf '%s\n' "$BUILD_INFO" | grep -Fxq 'package_name=os-sing-box'
printf '%s\n' "$BUILD_INFO" | grep -Fxq 'package_version=9.9.9-test'
printf '%s\n' "$BUILD_INFO" | grep -Fxq 'package_origin=opnsense/os-sing-box'
printf '%s\n' "$BUILD_INFO" | grep -Fxq 'core_release=v0.0.0-test'
printf '%s\n' "$BUILD_INFO" | grep -Fxq "core_asset_sha256=$ASSET_SHA256"
printf '%s\n' "$BUILD_INFO" | grep -Fxq "core_binary_sha256=$BINARY_SHA256"

SYSTEM_RESOLVER_HELPER="$EXTRACT_DIR/usr/local/opnsense/scripts/OPNsense/SingBox/system_resolver.php"
[ -f "$SYSTEM_RESOLVER_HELPER" ]
[ "$(stat -c '%a' "$SYSTEM_RESOLVER_HELPER")" = "755" ]

if bsdtar -tf "$PACKAGE_FILE" | sed -e 's#^/##' | grep -Fxq 'usr/local/etc/sing-box/config.json'; then
    echo "Готовый пакет не должен владеть пользовательским config.json" >&2
    exit 1
fi

echo "Состав package-артефакта и сведения о происхождении ядра проверены"
