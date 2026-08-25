#!/bin/sh
set -eu

ROOT_DIR="$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"
CONTROLLER="$ROOT_DIR/src/usr/local/opnsense/mvc/app/controllers/OPNsense/SingBox/SettingsController.php"
VIEW="$ROOT_DIR/src/usr/local/opnsense/mvc/app/views/OPNsense/SingBox/settings.volt"
FORM="$ROOT_DIR/src/usr/local/opnsense/mvc/app/controllers/OPNsense/SingBox/forms/settings.xml"
MODEL="$ROOT_DIR/src/usr/local/opnsense/mvc/app/models/OPNsense/SingBox/Settings.xml"
ACL="$ROOT_DIR/src/usr/local/opnsense/mvc/app/models/OPNsense/SingBox/ACL/ACL.xml"
API_CONTROLLER="$ROOT_DIR/src/usr/local/opnsense/mvc/app/controllers/OPNsense/SingBox/Api/SettingsController.php"

[ -f "$CONTROLLER" ]
[ -f "$VIEW" ]
[ -f "$FORM" ]
[ -f "$MODEL" ]
[ -f "$ACL" ]
[ -f "$API_CONTROLLER" ]

grep -q "getForm('settings')" "$CONTROLLER"
grep -q "OPNsense/SingBox/settings" "$CONTROLLER"
grep -q '/api/singbox/settings/get' "$VIEW"
grep -q '/api/singbox/settings/set' "$VIEW"
grep -q '/api/singbox/settings/preview' "$VIEW"
grep -q '/api/singbox/settings/apply' "$VIEW"
grep -q 'Сохранить настройки' "$VIEW"
grep -q 'Предварительный просмотр' "$VIEW"
grep -q 'Применить runtime-конфигурацию' "$VIEW"
grep -q "prop('disabled', !ready)" "$VIEW"
grep -q 'Сохранение параметров не изменяет рабочий' "$VIEW"
grep -q 'Что будет настроено' "$VIEW"
grep -q 'policySummary' "$VIEW"
grep -q 'policyRequirements' "$VIEW"
grep -q 'policyPlanState' "$VIEW"
grep -q 'policyInterfaces' "$VIEW"
grep -q 'Интерфейсы захвата' "$VIEW"
grep -q 'policyDnsRedirect' "$VIEW"
grep -q 'policyFakeIpRoute' "$VIEW"
grep -q 'policyOutbound' "$VIEW"
grep -q 'policyOperationCount' "$VIEW"
grep -q 'Операций OPNsense' "$VIEW"
grep -q 'Технический JSON runtime-конфигурации' "$VIEW"
grep -q '<id>settings.capture.interfaces</id>' "$FORM"
grep -q 'WAN намеренно запрещён' "$FORM"
grep -q '<id>settings.dns.fakeIpRange</id>' "$FORM"
grep -Fq '<interfaces type=".\CaptureInterfaceField">' "$MODEL"
grep -q '<Multiple>Y</Multiple>' "$MODEL"
grep -Fq '<fakeIpRange type=".\FakeIpRangeField">' "$MODEL"
grep -q '<Default>198.18.0.0/15</Default>' "$MODEL"
grep -q "'policy_plan'" "$API_CONTROLLER"
grep -q 'ui/singbox/\*' "$ACL"
grep -q 'api/singbox/settings/\*' "$ACL"

if grep -q 'saveFormToEndpoint.*settings/apply' "$VIEW"; then
    echo "Сохранение MVC-настроек не должно автоматически применять runtime-конфигурацию" >&2
    exit 1
fi

if grep -Eq 'service[[:space:]]+sing-box|/etc/rc.conf.d|file_put_contents' "$VIEW"; then
    echo "MVC WebUI не должен выполнять привилегированные операции напрямую" >&2
    exit 1
fi

if grep -q '\.html(' "$VIEW"; then
    echo "MVC WebUI не должен вставлять данные API через небезопасный html()" >&2
    exit 1
fi

echo "Разделение сохранения, интерфейсов захвата, policy preview и apply в MVC WebUI проверено"
