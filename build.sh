#!/bin/sh
set -eu

PKG_NAME="${PKG_NAME:-os-sing-box}"
VERSION="${VERSION:-1.0.2}"
ORIGIN="${ORIGIN:-opnsense/os-sing-box}"
COMMENT="${COMMENT:-Интеграция sing-box с OPNsense}"
MAINTAINER="${MAINTAINER:-https://github.com/Clyde89/os-sing-box-plus/issues}"
WWW="${WWW:-https://github.com/Clyde89/os-sing-box-plus}"
PREFIX="${PREFIX:-/usr/local}"
ABI="${ABI:-universal}"
OUTPUT_NAME="${OUTPUT_NAME:-${PKG_NAME}.pkg}"
SING_BOX_RELEASE="${SING_BOX_RELEASE:-v1.13.13-vincent}"
SING_BOX_ASSET="${SING_BOX_ASSET:-bsd-box-reF1nd-freebsd-amd64.xz}"
SING_BOX_SHA256="${SING_BOX_SHA256:-1da7e84757a5ff5d13d4154b4e4055ea5f99d069c2423687fe8165bf504be7d0}"
SING_BOX_DOWNLOAD_URL="${SING_BOX_DOWNLOAD_URL:-https://github.com/Vincent-Loeng/bsd-box/releases/download/$SING_BOX_RELEASE/$SING_BOX_ASSET}"
DOWNLOAD_TIMEOUT="${DOWNLOAD_TIMEOUT:-300}"

SCRIPT_DIR="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
WORKDIR="${WORKDIR:-"$SCRIPT_DIR/work/freebsd-pkg"}"
STAGEDIR="$WORKDIR/stage"
METADIR="$WORKDIR/meta"
PLIST="$WORKDIR/pkg-plist"
DISTDIR="${DISTDIR:-"$SCRIPT_DIR/dist"}"
DOWNLOADDIR="$WORKDIR/downloads"

die() {
    echo "Ошибка: $*" >&2
    exit 1
}

need_file() {
    [ -e "$SCRIPT_DIR/$1" ] || die "отсутствует обязательный файл: $1"
}

command -v pkg >/dev/null 2>&1 || die "команда pkg не найдена; сборка выполняется на FreeBSD или OPNsense"
command -v tar >/dev/null 2>&1 || die "команда tar не найдена"
command -v xz >/dev/null 2>&1 || die "команда xz не найдена"
command -v sha256 >/dev/null 2>&1 || die "команда sha256 не найдена"
if ! command -v fetch >/dev/null 2>&1 && ! command -v curl >/dev/null 2>&1; then
    die "для загрузки бинарного файла требуется fetch или curl"
fi

need_file "src/usr/local/etc/sing-box/config.json.sample"
need_file "src/usr/local/etc/sing-box/readiness.conf.sample"
need_file "src/usr/local/etc/rc.d/sing-box"
need_file "src/usr/local/etc/rc.syshook.d/start/70-sing-box-readiness"
need_file "src/usr/local/sbin/sing-box-readiness"
need_file "src/usr/local/opnsense/service/conf/actions.d/actions_sing-box.conf"
need_file "src/usr/local/etc/inc/plugins.inc.d/sing_box.inc"
need_file "src/usr/local/opnsense/mvc/app/models/OPNsense/SingBox/Menu/Menu.xml"
need_file "src/usr/local/opnsense/mvc/app/models/OPNsense/SingBox/ACL/ACL.xml"
need_file "src/usr/local/www/sing-box.php"
need_file "src/usr/local/www/sing-box_log.php"
need_file "packaging/freebsd/+PRE_INSTALL"
need_file "packaging/freebsd/+POST_INSTALL"
need_file "packaging/freebsd/+PRE_DEINSTALL"
need_file "packaging/freebsd/+POST_DEINSTALL"
need_file "packaging/freebsd/pkg-descr"

case "$ABI" in
    universal)
        PKG_ABI="FreeBSD:*:amd64"
        PKG_ARCH="freebsd:*:x86:64"
        ;;
    native)
        PKG_ABI="$(pkg config ABI)"
        case "$PKG_ABI" in
            FreeBSD:*:amd64) ;;
            *) die "неподдерживаемый ABI: $PKG_ABI" ;;
        esac
        ABI_MAJOR="$(printf '%s\n' "$PKG_ABI" | awk -F: '{print $2}')"
        PKG_ARCH="freebsd:${ABI_MAJOR}:x86:64"
        ;;
    FreeBSD:*:amd64)
        PKG_ABI="$ABI"
        ABI_MAJOR="$(printf '%s\n' "$PKG_ABI" | awk -F: '{print $2}')"
        PKG_ARCH="freebsd:${ABI_MAJOR}:x86:64"
        ;;
    *)
        die "неподдерживаемый ABI: $ABI"
        ;;
esac
unset ABI || true

rm -rf "$WORKDIR"
mkdir -p "$STAGEDIR" "$METADIR" "$DISTDIR" "$DOWNLOADDIR"

copy_tree() {
    src="$1"
    dst="$2"
    mkdir -p "$dst"
    (cd "$src" && tar --exclude '.DS_Store' --exclude '._*' --exclude '*.xz' -cf - .) | (cd "$dst" && tar -xf -)
}

download_file() {
    download_url="$1"
    download_dst="$2"

    if command -v curl >/dev/null 2>&1; then
        curl -fL --retry 3 --retry-all-errors --retry-delay 2 --connect-timeout 30 --max-time "$DOWNLOAD_TIMEOUT" -o "$download_dst" "$download_url"
    else
        fetch -T "$DOWNLOAD_TIMEOUT" -q -o "$download_dst" "$download_url"
    fi
}

unpack_binary() {
    archive="$1"
    binary_dst="$2"
    temporary="$binary_dst.tmp"

    rm -f "$temporary" "$binary_dst"
    if xz -t "$archive" >/dev/null 2>&1; then
        xz -dc "$archive" > "$temporary"
    else
        cp "$archive" "$temporary"
    fi

    mv -f "$temporary" "$binary_dst"
    chmod 0755 "$binary_dst"
    [ -s "$binary_dst" ] || die "получен пустой бинарный файл: $archive"
}

verify_binary() {
    binary="$1"
    expected="$2"
    actual="$(sha256 -q "$binary")"

    if [ "$actual" != "$expected" ]; then
        die "контрольная сумма sing-box не совпала: ожидалась $expected, получена $actual"
    fi
}

prepare_binary() {
    asset="$1"
    binary_url="$2"
    binary_dst="$3"
    local_asset="$SCRIPT_DIR/src/usr/local/bin/$asset"
    archive="$binary_dst.download"

    mkdir -p "$DOWNLOADDIR"
    if [ -f "$local_asset" ]; then
        echo "==> Используется локальный бинарный файл $local_asset"
        unpack_binary "$local_asset" "$binary_dst"
    else
        echo "==> Загружается $binary_url"
        rm -f "$archive"
        download_file "$binary_url" "$archive"
        unpack_binary "$archive" "$binary_dst"
    fi

    verify_binary "$binary_dst" "$SING_BOX_SHA256"
    echo "==> Проверена контрольная сумма sing-box $SING_BOX_RELEASE"
}

echo "==> Подготавливаются файлы пакета"
copy_tree "$SCRIPT_DIR/src" "$STAGEDIR"
prepare_binary "$SING_BOX_ASSET" "$SING_BOX_DOWNLOAD_URL" "$DOWNLOADDIR/sing-box"
mkdir -p "$STAGEDIR/usr/local/bin"
install -m 0755 "$DOWNLOADDIR/sing-box" "$STAGEDIR/usr/local/bin/sing-box"
chmod 0700 "$STAGEDIR/usr/local/etc/sing-box"
chmod 0644 "$STAGEDIR/usr/local/etc/sing-box/config.json.sample"
chmod 0644 "$STAGEDIR/usr/local/etc/sing-box/readiness.conf.sample"
chmod 0755 "$STAGEDIR/usr/local/etc/rc.d/sing-box"
chmod 0755 "$STAGEDIR/usr/local/etc/rc.syshook.d/start/70-sing-box-readiness"
chmod 0755 "$STAGEDIR/usr/local/sbin/sing-box-readiness"

echo "==> Формируется список файлов"
find "$STAGEDIR" -type f | sed "s#^$STAGEDIR##" | sort > "$PLIST"

FLATSIZE=0
while IFS= read -r file; do
    size="$(wc -c < "$STAGEDIR$file" | tr -d ' ')"
    FLATSIZE=$((FLATSIZE + size))
done < "$PLIST"

echo "==> Формируются метаданные"
{
    printf 'name: "%s"\n' "$PKG_NAME"
    printf 'origin: "%s"\n' "$ORIGIN"
    printf 'version: "%s"\n' "$VERSION"
    printf 'comment: "%s"\n' "$COMMENT"
    printf 'maintainer: "%s"\n' "$MAINTAINER"
    printf 'www: "%s"\n' "$WWW"
    printf 'abi: "%s"\n' "$PKG_ABI"
    printf 'arch: "%s"\n' "$PKG_ARCH"
    printf 'prefix: "%s"\n' "$PREFIX"
    printf 'flatsize: %s\n' "$FLATSIZE"
    printf 'deps: {\n'
    printf '    curl: { origin: "ftp/curl", version: ">=0" }\n'
    printf '}\n'
    printf 'desc: <<EOD\n'
    cat "$SCRIPT_DIR/packaging/freebsd/pkg-descr"
    printf '\nEOD\n'
    printf 'files: {\n'
    while IFS= read -r file; do
        checksum="$(sha256 -q "$STAGEDIR$file")"
        printf '    "%s": "1$%s"\n' "$file" "$checksum"
    done < "$PLIST"
    printf '}\n'
    printf 'scripts: {\n'
    printf '    "pre-install": <<EOS\n'
    cat "$SCRIPT_DIR/packaging/freebsd/+PRE_INSTALL"
    printf '\nEOS\n'
    printf '    "post-install": <<EOS\n'
    cat "$SCRIPT_DIR/packaging/freebsd/+POST_INSTALL"
    printf '\nEOS\n'
    printf '    "pre-deinstall": <<EOS\n'
    cat "$SCRIPT_DIR/packaging/freebsd/+PRE_DEINSTALL"
    printf '\nEOS\n'
    printf '    "post-deinstall": <<EOS\n'
    cat "$SCRIPT_DIR/packaging/freebsd/+POST_DEINSTALL"
    printf '\nEOS\n'
    printf '}\n'
} > "$METADIR/+MANIFEST"
cp "$METADIR/+MANIFEST" "$METADIR/+COMPACT_MANIFEST"

echo "==> Создаётся пакет для $PKG_ABI"
PKGROOT="$WORKDIR/package-root"
TARLIST="$WORKDIR/pkg-tarlist"
rm -rf "$PKGROOT"
mkdir -p "$PKGROOT"
install -m 0644 "$METADIR/+COMPACT_MANIFEST" "$PKGROOT/+COMPACT_MANIFEST"
install -m 0644 "$METADIR/+MANIFEST" "$PKGROOT/+MANIFEST"
copy_tree "$STAGEDIR" "$PKGROOT"
{
    printf '%s\n' '+COMPACT_MANIFEST' '+MANIFEST'
    find "$PKGROOT" -type f ! -name '+COMPACT_MANIFEST' ! -name '+MANIFEST' |
        sed "s#^$PKGROOT/##" |
        sort
} > "$TARLIST"

tar -cPzf "$DISTDIR/$OUTPUT_NAME" \
    -C "$PKGROOT" \
    -s ',^etc,/etc,' \
    -s ',^usr,/usr,' \
    -T "$TARLIST"

echo "==> Пакет: $DISTDIR/$OUTPUT_NAME"
pkg info -F "$DISTDIR/$OUTPUT_NAME" >/dev/null
echo "==> Метаданные пакета проверены"
sha256 "$DISTDIR/$OUTPUT_NAME"
