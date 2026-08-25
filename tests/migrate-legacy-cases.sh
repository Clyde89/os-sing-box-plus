#!/bin/sh
set -eu

ROOT_DIR="$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT HUP INT TERM

MIGRATOR="$ROOT_DIR/src/usr/local/opnsense/scripts/OPNsense/SingBox/migrate_legacy.php"
STUB_DIR="$ROOT_DIR/tests/stubs"
SNAPSHOT="$TMP_DIR/snapshot.json"
OUTPUT="$TMP_DIR/output.json"
CURRENT="$TMP_DIR/current.json"

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

run_migrator()
{
    current_config="${1:-}"
    rm -f "$OUTPUT"

    if [ -n "$current_config" ]; then
        MIGRATION_TEST_CURRENT_CONFIG="$current_config" MIGRATION_TEST_OUTPUT="$OUTPUT" \
            php -d "include_path=$STUB_DIR" "$MIGRATOR" "$SNAPSHOT"
    else
        MIGRATION_TEST_OUTPUT="$OUTPUT" \
            php -d "include_path=$STUB_DIR" "$MIGRATOR" "$SNAPSHOT"
    fi
}

run_migrator ""
python3 - "$OUTPUT" <<'PY'
import json
import sys

with open(sys.argv[1], 'r', encoding='utf-8') as fh:
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

cp "$SNAPSHOT" "$CURRENT"
run_migrator "$CURRENT"
[ ! -e "$OUTPUT" ] || {
    echo 'Повторная миграция неожиданно изменила уже восстановленную конфигурацию' >&2
    exit 1
}

cat > "$CURRENT" <<'JSON'
{
  "interfaces": {
    "lan": {
      "if": "igc0",
      "descr": "LAN"
    },
    "opt9": {
      "if": "tun_singbox",
      "descr": "TUN",
      "enable": "1"
    }
  },
  "filter": {
    "rule": []
  }
}
JSON
run_migrator "$CURRENT"
python3 - "$OUTPUT" <<'PY'
import json
import sys

with open(sys.argv[1], 'r', encoding='utf-8') as fh:
    config = json.load(fh)

assert 'opt4' not in config['interfaces'], 'legacy-ключ интерфейса не должен дублироваться'
assert config['interfaces']['opt9']['if'] == 'tun_singbox', 'существующий TUN-интерфейс потерян'

rules = [
    rule for rule in config['filter']['rule']
    if rule.get('@attributes', {}).get('uuid') == '762b3ec8-79c2-48b4-9793-c653bb3d2265'
]
assert len(rules) == 1, 'legacy-правило не восстановлено при переназначенном интерфейсе'
assert rules[0].get('interface') == 'opt9', 'interface правила не переназначен'
assert rules[0].get('source', {}).get('network') == 'opt9', 'source.network правила не переназначен'
PY

cat > "$CURRENT" <<'JSON'
{
  "interfaces": {
    "lan": {
      "if": "igc0",
      "descr": "LAN"
    },
    "opt4": {
      "if": "igc4",
      "descr": "Другой интерфейс"
    }
  },
  "filter": {
    "rule": []
  }
}
JSON
rm -f "$OUTPUT"
set +e
MIGRATION_TEST_CURRENT_CONFIG="$CURRENT" MIGRATION_TEST_OUTPUT="$OUTPUT" \
    php -d "include_path=$STUB_DIR" "$MIGRATOR" "$SNAPSHOT" \
    >"$TMP_DIR/conflict.stdout" 2>"$TMP_DIR/conflict.stderr"
status=$?
set -e

[ "$status" -eq 66 ] || {
    echo "Ожидался код 66 при конфликте интерфейса, получен $status" >&2
    exit 1
}
grep -q 'уже занят' "$TMP_DIR/conflict.stderr"
[ ! -e "$OUTPUT" ] || {
    echo 'Конфликтная миграция не должна сохранять изменённую конфигурацию' >&2
    exit 1
}

echo "Граничные сценарии legacy-миграции проверены"
