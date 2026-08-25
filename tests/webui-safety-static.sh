#!/bin/sh
set -eu

ROOT_DIR="$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"
WEBUI="$ROOT_DIR/src/usr/local/www/sing-box.php"

[ -f "$WEBUI" ]

grep -Fq "const SETUP_REQUIRED_FILE = '/var/db/os-sing-box/setup-required';" "$WEBUI"
grep -q '^function setupRequired()' "$WEBUI"
grep -q '^function finishInitialSetup()' "$WEBUI"
grep -q '^function configdAction()' "$WEBUI"
grep -q '^function serviceEnabled()' "$WEBUI"
grep -q '^function serviceEnableAction()' "$WEBUI"
grep -Fq 'chmod($backupFile, 0600)' "$WEBUI"
grep -Fq '@unlink($backupFile)' "$WEBUI"
grep -Fq "(\$action === 'start' || \$action === 'restart') && setupRequired()" "$WEBUI"
grep -Fq "(\$action === 'start' || \$action === 'restart') && !serviceEnabled()" "$WEBUI"
grep -Fq "'enabled' => serviceEnabled()" "$WEBUI"
grep -Fq "'setup_required' => setupRequired()" "$WEBUI"
grep -q "case 'enable_service':" "$WEBUI"
grep -q "case 'disable_service':" "$WEBUI"
grep -q 'Требуется первоначальная настройка' "$WEBUI"
grep -q 'Включить автозапуск' "$WEBUI"
grep -q 'Отключить автозапуск' "$WEBUI"
grep -Fq '($setupRequired || $serviceEnabled)' "$WEBUI"
grep -Fq '($setupRequired || !$serviceEnabled)' "$WEBUI"
grep -q 'configctl' "$WEBUI"

if grep -q '/usr/sbin/service sing-box' "$WEBUI"; then
    echo "WebUI не должен напрямую управлять службой через service" >&2
    exit 1
fi

if grep -q '/etc/rc.conf.d/sing_box' "$WEBUI"; then
    echo "WebUI не должен напрямую изменять rc.conf.d" >&2
    exit 1
fi

echo "Защиты первоначальной настройки WebUI проверены"
