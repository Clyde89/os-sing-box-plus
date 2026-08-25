#!/bin/sh
set -eu

ROOT_DIR="$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"
RC_SCRIPT="$ROOT_DIR/src/usr/local/etc/rc.d/sing-box"

[ -f "$RC_SCRIPT" ]

grep -q '^process_is_sing_box()' "$RC_SCRIPT"
grep -q '^cleanup_failed_start()' "$RC_SCRIPT"
grep -q 'cleanup_failed_start "$started_pid"' "$RC_SCRIPT"
grep -q 'chmod 0600 "$pidfile"' "$RC_SCRIPT"
grep -q 'PID-файл указывал на другой процесс' "$RC_SCRIPT"
grep -q 'Интерфейс tun_singbox не был создан за 20 секунд; запуск отменён.' "$RC_SCRIPT"

if grep -A3 'Интерфейс tun_singbox не был создан за 20 секунд; запуск отменён.' "$RC_SCRIPT" | grep -q '^        return 1$'; then
    grep -A2 'Интерфейс tun_singbox не был создан за 20 секунд; запуск отменён.' "$RC_SCRIPT" | grep -q 'cleanup_failed_start "$started_pid"'
fi

echo "Защиты жизненного цикла службы sing-box проверены"
