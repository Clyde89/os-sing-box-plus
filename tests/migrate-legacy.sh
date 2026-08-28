#!/bin/sh
set -eu

ROOT_DIR="$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT HUP INT TERM

SNAPSHOT="$TMP_DIR/snapshot.json"
OUTPUT="$TMP_DIR/output.json"
MIGRATOR="$ROOT_DIR/src/usr/local/opnsense/scripts/OPNsense/SingBox/migrate_legacy.php"
STUB_DIR="$ROOT_DIR/tests/stubs"

cat > "$SNAPSHOT" <<'JSON'
{
  "interfaces": {
    "opt4": {
      "if": "tun_singbox",
      "descr": "TUN",
      "enable": "1"
    }
  },
  "filter": {
    "rule": [
      {
        "@attributes": {
          "uuid": "762b3ec8-79c2-48b4-9793-c653bb3d2265"
        },
        "type": "pass",
        "interface": "opt4",
        "ipprotocol": "inet",
        "source": {
          "network": "opt4"
        },
        "destination": {
          "any": ""
        },
        "descr": "sing-box TUN Allow"
      }
    ]
  }
}
JSON

MIGRATION_TEST_OUTPUT="$OUTPUT" php -d "include_path=$STUB_DIR" "$MIGRATOR" "$SNAPSHOT"

python3 - "$OUTPUT" <<'PY'
import json
import sys

path = sys.argv[1]
with open(path, 'r', encoding='utf-8') as fh:
    config = json.load(fh)

interface = config['interfaces'].get('opt4')
assert interface is not None, 'legacy-интерфейс не восстановлен'
assert interface.get('if') == 'tun_singbox', 'восстановлен неверный интерфейс'

rules = config['filter']['rule']
matched = [
    rule for rule in rules
    if rule.get('@attributes', {}).get('uuid') == '762b3ec8-79c2-48b4-9793-c653bb3d2265'
]
assert len(matched) == 1, 'legacy-правило firewall не восстановлено'
assert matched[0].get('interface') == 'opt4', 'правило связано с неверным интерфейсом'
assert matched[0].get('source', {}).get('network') == 'opt4', 'источник правила связан с неверным интерфейсом'
PY

echo "Миграция legacy-объектов OPNsense проверена"
