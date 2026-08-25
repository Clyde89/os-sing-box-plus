#!/bin/sh
set -eu

ROOT_DIR="$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"
SCRIPT="$ROOT_DIR/src/usr/local/opnsense/scripts/OPNsense/SingBox/runtime_config.php"
ACTIONS="$ROOT_DIR/src/usr/local/opnsense/service/conf/actions.d/actions_sing-box.conf"
CONTROLLER="$ROOT_DIR/src/usr/local/opnsense/mvc/app/controllers/OPNsense/SingBox/Api/SettingsController.php"

[ -f "$SCRIPT" ]
[ -f "$ACTIONS" ]
[ -f "$CONTROLLER" ]

grep -q 'RuntimeConfigBuilder::build' "$SCRIPT"
grep -Fq "(\$plan['apply_ready'] ?? false) !== true" "$SCRIPT"
grep -q "check -c" "$SCRIPT"
grep -q "tempnam" "$SCRIPT"
grep -Fq "chmod(\$tempFile, 0600)" "$SCRIPT"
grep -Fq "copy(TARGET_CONFIG, \$backupFile)" "$SCRIPT"
grep -Fq "chmod(\$backupFile, 0600)" "$SCRIPT"
grep -Fq "rename(\$tempFile, TARGET_CONFIG)" "$SCRIPT"
grep -Fq "chmod(TARGET_CONFIG, 0600)" "$SCRIPT"
grep -q 'restorePreviousConfig' "$SCRIPT"
grep -q 'SETUP_REQUIRED_FILE' "$SCRIPT"
grep -q '^\[apply\]$' "$ACTIONS"
grep -q 'runtime_config.php apply' "$ACTIONS"
grep -q 'function applyAction' "$CONTROLLER"
grep -q "configdRun('sing-box apply')" "$CONTROLLER"

if grep -Eq 'service[[:space:]]+sing-box|configctl[[:space:]]+filter' "$SCRIPT"; then
    echo "Применение runtime-конфигурации не должно автоматически перезапускать службу или firewall" >&2
    exit 1
fi

if grep -q 'file_put_contents(TARGET_CONFIG' "$SCRIPT"; then
    echo "Runtime-конфигурация не должна записываться напрямую в целевой файл до проверки" >&2
    exit 1
fi

echo "Безопасное применение runtime-конфигурации проверено"
