#!/bin/sh
set -eu

ROOT_DIR="$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"
WEBUI="$ROOT_DIR/src/usr/local/www/sing-box.php"

[ -f "$WEBUI" ]

grep -Fq "const SETUP_REQUIRED_FILE = '/var/db/os-sing-box/setup-required';" "$WEBUI"
grep -Fq "const MANAGED_CONFIG_FILE = '/var/db/os-sing-box/managed-config';" "$WEBUI"
grep -Fq "const APPLY_LOCK_FILE = '/var/db/os-sing-box/apply.lock';" "$WEBUI"
grep -q '^function setupRequired()' "$WEBUI"
grep -q '^function managedConfig()' "$WEBUI"
grep -q '^function acquireConfigurationLock()' "$WEBUI"
grep -q '^function releaseConfigurationLock(' "$WEBUI"
grep -q '^function finishInitialSetup()' "$WEBUI"
grep -q '^function configdAction(' "$WEBUI"
grep -q '^function serviceEnabled()' "$WEBUI"
grep -q '^function serviceEnableAction(' "$WEBUI"
grep -q '^function clearLog()' "$WEBUI"
grep -Fq 'chmod($backupFile, 0600)' "$WEBUI"
grep -Fq '@unlink($backupFile)' "$WEBUI"
grep -Fq '@flock($handle, LOCK_EX | LOCK_NB)' "$WEBUI"
grep -Fq 'releaseConfigurationLock($lockHandle)' "$WEBUI"
grep -Fq 'if (managedConfig()) {' "$WEBUI"
grep -q 'Изменение экспертного JSON заблокировано' "$WEBUI"
grep -Fq "\$managedConfig ? ' readonly' : ''" "$WEBUI"
grep -Fq "\$managedConfig ? ' disabled' : ''" "$WEBUI"
grep -Fq 'href="/ui/singbox/settings"' "$WEBUI"
grep -Fq "(\$action === 'start' || \$action === 'restart') && setupRequired()" "$WEBUI"
grep -Fq "'enabled' => serviceEnabled()" "$WEBUI"
grep -Fq "'setup_required' => setupRequired()" "$WEBUI"
grep -Fq "configdAction('clearlog')" "$WEBUI"
grep -q "case 'enable_service':" "$WEBUI"
grep -q "case 'disable_service':" "$WEBUI"
grep -q 'Требуется первоначальная настройка' "$WEBUI"
grep -q 'Включить автозапуск' "$WEBUI"
grep -q 'Отключить автозапуск' "$WEBUI"
grep -Fq '($setupRequired || $serviceEnabled !== false)' "$WEBUI"
grep -Fq '($setupRequired || $serviceEnabled !== true)' "$WEBUI"
grep -Fq '$serviceEnabled !== true' "$WEBUI"
grep -q "return 'error';" "$WEBUI"
grep -q 'configctl' "$WEBUI"

if grep -q '/usr/sbin/service sing-box' "$WEBUI"; then
    echo "WebUI не должен напрямую управлять службой через service" >&2
    exit 1
fi

if grep -q '/etc/rc.conf.d/sing_box' "$WEBUI"; then
    echo "WebUI не должен напрямую изменять rc.conf.d" >&2
    exit 1
fi

if grep -q "const SINGBOX_LOG_FILE" "$WEBUI"; then
    echo "WebUI не должен напрямую управлять файлом журнала" >&2
    exit 1
fi

echo "Защиты первоначальной настройки и экспертного режима WebUI проверены"
