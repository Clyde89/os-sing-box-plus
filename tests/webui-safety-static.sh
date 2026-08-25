#!/bin/sh
set -eu

ROOT_DIR="$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"
WEBUI="$ROOT_DIR/src/usr/local/www/sing-box.php"

[ -f "$WEBUI" ]

grep -q "const SETUP_REQUIRED_FILE = '/var/db/os-sing-box/setup-required';" "$WEBUI"
grep -q '^function setupRequired()' "$WEBUI"
grep -q '^function finishInitialSetup()' "$WEBUI"
grep -q 'chmod($backupFile, 0600)' "$WEBUI"
grep -q '@unlink($backupFile)' "$WEBUI"
grep -q "($action === 'start' || $action === 'restart') && setupRequired()" "$WEBUI"
grep -q "'setup_required' => setupRequired()" "$WEBUI"
grep -q 'Требуется первоначальная настройка' "$WEBUI"
grep -q "\$setupRequired ? ' disabled' : ''" "$WEBUI"
grep -q 'configctl' "$WEBUI"

if grep -q '/usr/sbin/service sing-box' "$WEBUI"; then
    echo "WebUI не должен напрямую управлять службой через service" >&2
    exit 1
fi

echo "Защиты первоначальной настройки WebUI проверены"
