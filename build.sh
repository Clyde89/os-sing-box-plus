#!/bin/sh
set -eu

PKG_NAME="${PKG_NAME:-os-sing-box}"
VERSION="${VERSION:-1.1.0}"
ORIGIN="${ORIGIN:-opnsense/os-sing-box}"
COMMENT="${COMMENT:-Интеграция sing-box с OPNsense}"
MAINTAINER="${MAINTAINER:-https://github.com/Clyde89/os-sing-box-plus/issues}"
WWW="${WWW:-https://github.com/Clyde89/os-sing-box-plus}"
PREFIX="${PREFIX:-/usr/local}"
ABI="${ABI:-universal}"
OUTPUT_NAME="${OUTPUT_NAME:-${PKG_NAME}.pkg}"
SING_BOX_RELEASE="${SING_BOX_RELEASE:-v1.13.13-vincent}"
SING_BOX_ASSET="${SING_BOX_ASSET:-bsd-box-reF1nd-freebsd-amd64.xz}"
SING_BOX_ASSET_SHA256="${SING_BOX_ASSET_SHA256:-3ba254d792964cd1005946354e0c8250a05955381bdcbd19f57265a339b199d7}"
SING_BOX_SHA256="${SING_BOX_SHA256:-1da7e84757a5ff5d13d4154b4e4055ea5f99d069c2423687fe8165bf504be7d0}"
SING_BOX_DOWNLOAD_URL="${SING_BOX_DOWNLOAD_URL:-https://github.com/Vincent-Loeng/bsd-box/releases/download/$SING_BOX_RELEASE/$SING_BOX_ASSET}"
SING_BOX_LOCAL_ASSET="${SING_BOX_LOCAL_ASSET:-}"
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
need_file "src/usr/local/sbin/sing-box-service-config"
need_file "src/usr/local/sbin/sing-box-logctl"
need_file "src/usr/local/opnsense/scripts/OPNsense/SingBox/runtime_config.php"
need_file "src/usr/local/opnsense/scripts/OPNsense/SingBox/policy_readiness.php"
need_file "src/usr/local/opnsense/scripts/OPNsense/SingBox/system_resolver.php"
need_file "src/usr/local/opnsense/service/conf/actions.d/actions_sing-box.conf"
need_file "src/usr/local/etc/inc/plugins.inc.d/sing_box.inc"
need_file "src/usr/local/opnsense/mvc/app/models/OPNsense/SingBox/Menu/Menu.xml"
need_file "src/usr/local/opnsense/mvc/app/models/OPNsense/SingBox/ACL/ACL.xml"
need_file "src/usr/local/opnsense/mvc/app/models/OPNsense/SingBox/Settings.php"
need_file "src/usr/local/opnsense/mvc/app/models/OPNsense/SingBox/Settings.xml"
need_file "src/usr/local/opnsense/mvc/app/models/OPNsense/SingBox/FieldTypes/DomainListField.php"
need_file "src/usr/local/opnsense/mvc/app/models/OPNsense/SingBox/FieldTypes/ClientListField.php"
need_file "src/usr/local/opnsense/mvc/app/models/OPNsense/SingBox/FieldTypes/CaptureInterfaceField.php"
need_file "src/usr/local/opnsense/mvc/app/models/OPNsense/SingBox/FieldTypes/FakeIpRangeField.php"
need_file "src/usr/local/opnsense/mvc/app/models/OPNsense/SingBox/Validation/SelectionValidator.php"
need_file "src/usr/local/opnsense/mvc/app/models/OPNsense/SingBox/Runtime/SelectorCompiler.php"
need_file "src/usr/local/opnsense/mvc/app/models/OPNsense/SingBox/Runtime/PolicyPlanBuilder.php"
need_file "src/usr/local/opnsense/mvc/app/models/OPNsense/SingBox/Runtime/PolicyPlanValidator.php"
need_file "src/usr/local/opnsense/mvc/app/models/OPNsense/SingBox/Runtime/NetworkPreflightValidator.php"
need_file "src/usr/local/opnsense/mvc/app/models/OPNsense/SingBox/Runtime/FirewallRuleBuilder.php"
need_file "src/usr/local/opnsense/mvc/app/models/OPNsense/SingBox/Runtime/RuntimeConfigBuilder.php"
need_file "src/usr/local/opnsense/mvc/app/controllers/OPNsense/SingBox/Api/SettingsController.php"
need_file "src/usr/local/opnsense/mvc/app/controllers/OPNsense/SingBox/SettingsController.php"
need_file "src/usr/local/opnsense/mvc/app/controllers/OPNsense/SingBox/forms/settings.xml"
need_file "src/usr/local/opnsense/mvc/app/views/OPNsense/SingBox/settings.volt"
need_file "src/usr/local/www/sing-box.php"
need_file "src/usr/local/www/sing-box_log.php"
need_file "src/usr/local/share/licenses/os-sing-box/LICENSE.plugin"
need_file "src/usr/local/share/licenses/os-sing-box/LICENSE.opnsense"
need_file "src/usr/local/share/licenses/os-sing-box/LICENSE.sing-box"
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

verify_sha256() {
    file="$1"
    expected="$2"
    label="$3"
    actual="$(sha256 -q "$file")"

    if [ "$actual" != "$expected" ]; then
        die "контрольная сумма $label не совпала: ожидалась $expected, получена $actual"
    fi
}

verify_lifecycle_sources() {
    for lifecycle_file in \
        "$SCRIPT_DIR/packaging/freebsd/+PRE_INSTALL" \
        "$SCRIPT_DIR/packaging/freebsd/+POST_INSTALL" \
        "$SCRIPT_DIR/packaging/freebsd/+PRE_DEINSTALL" \
        "$SCRIPT_DIR/packaging/freebsd/+POST_DEINSTALL"
    do
        /bin/sh -n "$lifecycle_file" \
            || die "lifecycle-сценарий не прошёл shell syntax check: $lifecycle_file"
    done

    if grep -Eq '(^|[[:space:]])pkg[[:space:]]+(query|version)([[:space:]]|$)' \
        "$SCRIPT_DIR/packaging/freebsd/+PRE_INSTALL"; then
        die "PRE-INSTALL не должен рекурсивно запускать pkg внутри package-транзакции"
    fi
}

verify_embedded_lifecycle() {
    manifest="$1"
    phase="$2"
    source_file="$3"
    embedded_file="$4"

    awk -v marker="\"${phase}\": <<EOS" '
        index($0, marker) {copy = 1; next}
        copy && $0 == "EOS" {exit}
        copy {print}
    ' "$manifest" > "$embedded_file"

    [ -s "$embedded_file" ] \
        || die "в готовом пакете отсутствовал lifecycle-сценарий $phase"
    /bin/sh -n "$embedded_file" \
        || die "embedded lifecycle-сценарий $phase не прошёл shell syntax check"
    cmp -s "$source_file" "$embedded_file" \
        || die "embedded lifecycle-сценарий $phase отличался от исходного файла"
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

prepare_binary() {
    asset="$1"
    binary_url="$2"
    binary_dst="$3"
    local_asset="${SING_BOX_LOCAL_ASSET:-$SCRIPT_DIR/src/usr/local/bin/$asset}"
    archive="$binary_dst.download"
    source_archive=""

    mkdir -p "$DOWNLOADDIR"
    if [ -f "$local_asset" ]; then
        echo "==> Используется локальный release-артефакт $local_asset"
        source_archive="$local_asset"
    else
        echo "==> Загружается $binary_url"
        rm -f "$archive"
        download_file "$binary_url" "$archive"
        source_archive="$archive"
    fi

    verify_sha256 "$source_archive" "$SING_BOX_ASSET_SHA256" "release-артефакта sing-box $SING_BOX_RELEASE"
    echo "==> Проверена контрольная сумма release-артефакта sing-box $SING_BOX_RELEASE"

    unpack_binary "$source_archive" "$binary_dst"
    verify_sha256 "$binary_dst" "$SING_BOX_SHA256" "бинарного файла sing-box $SING_BOX_RELEASE"
    echo "==> Проверена контрольная сумма бинарного файла sing-box $SING_BOX_RELEASE"
}

write_build_info() {
    build_info_dir="$STAGEDIR/usr/local/share/os-sing-box"
    build_info="$build_info_dir/build-info"

    install -d -m 0755 "$build_info_dir"
    {
        printf 'format_version=1\n'
        printf 'package_name=%s\n' "$PKG_NAME"
        printf 'package_version=%s\n' "$VERSION"
        printf 'package_origin=%s\n' "$ORIGIN"
        printf 'core_release=%s\n' "$SING_BOX_RELEASE"
        printf 'core_asset=%s\n' "$SING_BOX_ASSET"
        printf 'core_asset_sha256=%s\n' "$SING_BOX_ASSET_SHA256"
        printf 'core_binary_sha256=%s\n' "$SING_BOX_SHA256"
    } > "$build_info"
    chmod 0644 "$build_info"
}

verify_package_artifact() {
    package_file="$1"
    verify_root="$WORKDIR/package-verify"
    archive_list="$WORKDIR/package-archive-list"

    rm -rf "$verify_root"
    mkdir -p "$verify_root"
    tar -tf "$package_file" |
        sed -e 's#^\./##' -e 's#^/##' -e 's#/$##' |
        sort -u > "$archive_list"

    if grep -Eq '(^|/)\.\.(/|$)' "$archive_list"; then
        die "пакет содержит небезопасный путь с переходом в родительский каталог"
    fi

    while IFS= read -r required_file; do
        [ -n "$required_file" ] || continue
        if ! grep -Fxq "$required_file" "$archive_list"; then
            die "в пакете отсутствует обязательный файл: /$required_file"
        fi
    done <<'EOF'
+COMPACT_MANIFEST
+MANIFEST
usr/local/bin/sing-box
usr/local/etc/rc.d/sing-box
usr/local/etc/sing-box/config.json.sample
usr/local/etc/sing-box/readiness.conf.sample
usr/local/opnsense/scripts/OPNsense/SingBox/policy_readiness.php
usr/local/opnsense/scripts/OPNsense/SingBox/system_resolver.php
usr/local/opnsense/scripts/OPNsense/SingBox/runtime_config.php
usr/local/opnsense/mvc/app/models/OPNsense/SingBox/Runtime/NetworkPreflightValidator.php
usr/local/opnsense/mvc/app/controllers/OPNsense/SingBox/Api/SettingsController.php
usr/local/opnsense/mvc/app/views/OPNsense/SingBox/settings.volt
usr/local/opnsense/service/conf/actions.d/actions_sing-box.conf
usr/local/opnsense/version/sing-box
usr/local/share/os-sing-box/build-info
usr/local/share/licenses/os-sing-box/LICENSE.plugin
usr/local/share/licenses/os-sing-box/LICENSE.opnsense
usr/local/share/licenses/os-sing-box/LICENSE.sing-box
EOF

    if grep -Fxq 'usr/local/etc/sing-box/config.json' "$archive_list" \
        || grep -Fxq 'usr/local/etc/sing-box/readiness.conf' "$archive_list"; then
        die "пакет не должен владеть пользовательской runtime-конфигурацией"
    fi

    tar -xzf "$package_file" -C "$verify_root"

    if [ "$(uname -s)" = "FreeBSD" ]; then
        while IFS= read -r archive_path; do
            [ -n "$archive_path" ] || continue
            archive_owner="$(stat -f '%Su:%Sg' "$verify_root/$archive_path")"
            [ "$archive_owner" = "root:wheel" ] \
                || die "файл /$archive_path внутри пакета имел владельца $archive_owner вместо root:wheel"
        done < "$archive_list"
    fi

    verify_embedded_lifecycle \
        "$verify_root/+MANIFEST" \
        pre-install \
        "$SCRIPT_DIR/packaging/freebsd/+PRE_INSTALL" \
        "$verify_root/pre-install.embedded"
    verify_embedded_lifecycle \
        "$verify_root/+MANIFEST" \
        post-install \
        "$SCRIPT_DIR/packaging/freebsd/+POST_INSTALL" \
        "$verify_root/post-install.embedded"
    verify_embedded_lifecycle \
        "$verify_root/+MANIFEST" \
        pre-deinstall \
        "$SCRIPT_DIR/packaging/freebsd/+PRE_DEINSTALL" \
        "$verify_root/pre-deinstall.embedded"
    verify_embedded_lifecycle \
        "$verify_root/+MANIFEST" \
        post-deinstall \
        "$SCRIPT_DIR/packaging/freebsd/+POST_DEINSTALL" \
        "$verify_root/post-deinstall.embedded"
    verify_sha256 \
        "$verify_root/usr/local/bin/sing-box" \
        "$SING_BOX_SHA256" \
        "бинарного файла sing-box внутри готового пакета"

    if ! cmp -s \
        "$STAGEDIR/usr/local/share/os-sing-box/build-info" \
        "$verify_root/usr/local/share/os-sing-box/build-info"; then
        die "сведения о сборке внутри готового пакета не совпали со staged-версией"
    fi

    [ "$(stat -f '%Lp' "$verify_root/usr/local/bin/sing-box")" = "755" ] \
        || die "бинарный файл sing-box внутри пакета имеет небезопасный режим доступа"
    [ "$(stat -f '%Lp' "$verify_root/usr/local/share/os-sing-box/build-info")" = "644" ] \
        || die "сведения о сборке внутри пакета имеют некорректный режим доступа"
    [ "$(stat -f '%Lp' "$verify_root/usr/local/opnsense/scripts/OPNsense/SingBox/system_resolver.php")" = "755" ] \
        || die "helper системного resolver внутри пакета имеет некорректный режим доступа"
}

verify_lifecycle_sources
echo "==> Lifecycle-сценарии прошли статическую проверку"
echo "==> Подготавливаются файлы пакета"
copy_tree "$SCRIPT_DIR/src" "$STAGEDIR"
prepare_binary "$SING_BOX_ASSET" "$SING_BOX_DOWNLOAD_URL" "$DOWNLOADDIR/sing-box"

if ! "$DOWNLOADDIR/sing-box" check -c "$SCRIPT_DIR/src/usr/local/etc/sing-box/config.json.sample" >/dev/null 2>&1; then
    die "базовый config.json.sample не прошёл проверку sing-box $SING_BOX_RELEASE"
fi
echo "==> Базовая конфигурация проверена sing-box $SING_BOX_RELEASE"

mkdir -p "$STAGEDIR/usr/local/bin"
install -m 0755 "$DOWNLOADDIR/sing-box" "$STAGEDIR/usr/local/bin/sing-box"
write_build_info
chmod 0700 "$STAGEDIR/usr/local/etc/sing-box"
chmod 0644 "$STAGEDIR/usr/local/etc/sing-box/config.json.sample"
chmod 0644 "$STAGEDIR/usr/local/etc/sing-box/readiness.conf.sample"
chmod 0755 "$STAGEDIR/usr/local/etc/rc.d/sing-box"
chmod 0755 "$STAGEDIR/usr/local/etc/rc.syshook.d/start/70-sing-box-readiness"
chmod 0755 "$STAGEDIR/usr/local/sbin/sing-box-readiness"
chmod 0755 "$STAGEDIR/usr/local/sbin/sing-box-service-config"
chmod 0755 "$STAGEDIR/usr/local/sbin/sing-box-logctl"
chmod 0755 "$STAGEDIR/usr/local/opnsense/scripts/OPNsense/SingBox/runtime_config.php"
chmod 0755 "$STAGEDIR/usr/local/opnsense/scripts/OPNsense/SingBox/system_resolver.php"
chmod 0644 "$STAGEDIR/usr/local/share/licenses/os-sing-box/LICENSE.plugin"
chmod 0644 "$STAGEDIR/usr/local/share/licenses/os-sing-box/LICENSE.opnsense"
chmod 0644 "$STAGEDIR/usr/local/share/licenses/os-sing-box/LICENSE.sing-box"

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
    printf 'categories: [ "net" ]\n'
    printf 'licenselogic: "multi"\n'
    printf 'licenses: [ "MIT", "BSD2CLAUSE", "GPLv3+" ]\n'
    printf 'flatsize: %s\n' "$FLATSIZE"
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
    printf 'EOS\n'
    printf '    "post-install": <<EOS\n'
    cat "$SCRIPT_DIR/packaging/freebsd/+POST_INSTALL"
    printf 'EOS\n'
    printf '    "pre-deinstall": <<EOS\n'
    cat "$SCRIPT_DIR/packaging/freebsd/+PRE_DEINSTALL"
    printf 'EOS\n'
    printf '    "post-deinstall": <<EOS\n'
    cat "$SCRIPT_DIR/packaging/freebsd/+POST_DEINSTALL"
    printf 'EOS\n'
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
if [ "$(uname -s)" = "FreeBSD" ]; then
    [ "$(id -u)" -eq 0 ] \
        || die "FreeBSD package должен был собираться с правами root"
    chown -R root:wheel "$PKGROOT"
fi
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
verify_package_artifact "$DISTDIR/$OUTPUT_NAME"
echo "==> Состав, происхождение и контрольная сумма ядра внутри пакета проверены"
sha256 "$DISTDIR/$OUTPUT_NAME"
