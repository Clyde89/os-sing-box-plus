#!/bin/sh
set -eu

ROOT_DIR="$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"
BUILD_SCRIPT="$ROOT_DIR/build.sh"
REGRESSION_SCRIPT="$ROOT_DIR/tests/opnsense-regression.sh"
CHECKLIST="$ROOT_DIR/docs/RELEASE-CHECKLIST.md"

for file in "$BUILD_SCRIPT" "$REGRESSION_SCRIPT" "$CHECKLIST"; do
    [ -f "$file" ]
done

grep -Fq 'write_build_info()' "$BUILD_SCRIPT"
grep -Fq 'format_version=1' "$BUILD_SCRIPT"
grep -Fq 'core_release=%s' "$BUILD_SCRIPT"
grep -Fq 'core_asset_sha256=%s' "$BUILD_SCRIPT"
grep -Fq 'core_binary_sha256=%s' "$BUILD_SCRIPT"
grep -Fq 'verify_package_artifact()' "$BUILD_SCRIPT"
grep -Fq 'verify_lifecycle_sources()' "$BUILD_SCRIPT"
grep -Fq 'verify_embedded_lifecycle()' "$BUILD_SCRIPT"
grep -Fq 'PRE-INSTALL не должен рекурсивно запускать pkg внутри package-транзакции' "$BUILD_SCRIPT"
grep -Fq 'embedded lifecycle-сценарий $phase отличался от исходного файла' "$BUILD_SCRIPT"
grep -Fq 'usr/local/share/os-sing-box/build-info' "$BUILD_SCRIPT"
grep -Fq 'verify_package_artifact "$DISTDIR/$OUTPUT_NAME"' "$BUILD_SCRIPT"
grep -Fq 'verify_sha256 \' "$BUILD_SCRIPT"
grep -Fq 'package-archive-list' "$BUILD_SCRIPT"
grep -Fq 'пакет не должен владеть пользовательской runtime-конфигурацией' "$BUILD_SCRIPT"

grep -Fq 'Только чтение' "$REGRESSION_SCRIPT"
grep -Fq 'configctl sing-box preflight' "$REGRESSION_SCRIPT"
grep -Fq 'policy_readiness.php' "$REGRESSION_SCRIPT"
grep -Fq 'pkg check -s' "$REGRESSION_SCRIPT"
grep -Fq 'core_binary_sha256' "$REGRESSION_SCRIPT"
grep -Fq 'post-upgrade' "$REGRESSION_SCRIPT"
grep -Fq 'post-reboot' "$REGRESSION_SCRIPT"
grep -Fq 'post-wan' "$REGRESSION_SCRIPT"

if grep -Eq 'service[[:space:]]+sing-box[[:space:]]+(start|stop|restart)|configctl[[:space:]]+filter[[:space:]]+reload|shutdown[[:space:]]+-r|(^|[[:space:]])reboot($|[[:space:]])|pkg[[:space:]]+(add|delete)' "$REGRESSION_SCRIPT"; then
    echo "Regression-сценарий не должен изменять состояние OPNsense" >&2
    exit 1
fi

grep -Fq 'post-upgrade' "$CHECKLIST"
grep -Fq 'post-reboot' "$CHECKLIST"
grep -Fq 'post-wan' "$CHECKLIST"
grep -Fq -- '--require-managed' "$CHECKLIST"
grep -Fq -- '--network' "$CHECKLIST"

echo "Выпускной шлюз package-артефакта и OPNsense проверен статически"
