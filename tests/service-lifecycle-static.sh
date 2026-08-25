#!/bin/sh
set -eu

ROOT_DIR="$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"
RC_SCRIPT="$ROOT_DIR/src/usr/local/etc/rc.d/sing-box"

[ -f "$RC_SCRIPT" ]

grep -q '^process_is_sing_box()' "$RC_SCRIPT"
grep -q '^cleanup_failed_start()' "$RC_SCRIPT"
grep -q '^apply_pending_filter_reload()' "$RC_SCRIPT"
grep -q 'pending_filter_reload="/var/db/os-sing-box/filter-reload.pending"' "$RC_SCRIPT"
grep -q 'cleanup_failed_start "$started_pid"' "$RC_SCRIPT"
grep -q 'chmod 0600 "$pidfile"' "$RC_SCRIPT"
grep -q 'PID-файл указывал на другой процесс' "$RC_SCRIPT"
grep -q 'Процесс sing-box уже запущен, но интерфейс tun_singbox отсутствует.' "$RC_SCRIPT"
grep -q 'Интерфейс tun_singbox не был создан за 20 секунд; запуск отменён.' "$RC_SCRIPT"
grep -q 'Служба sing-box запущена, но отложенные правила firewall не применены.' "$RC_SCRIPT"

if grep -A3 'Интерфейс tun_singbox не был создан за 20 секунд; запуск отменён.' "$RC_SCRIPT" | grep -q '^        return 1$'; then
    grep -A2 'Интерфейс tun_singbox не был создан за 20 секунд; запуск отменён.' "$RC_SCRIPT" | grep -q 'cleanup_failed_start "$started_pid"'
fi

pending_line="$(grep -n 'if ! apply_pending_filter_reload; then' "$RC_SCRIPT" | tail -n 1 | cut -d: -f1)"
success_line="$(grep -n 'echo "Служба запущена, PID \$started_pid."' "$RC_SCRIPT" | cut -d: -f1)"
[ -n "$pending_line" ]
[ -n "$success_line" ]
[ "$pending_line" -lt "$success_line" ]

echo "Защиты жизненного цикла службы sing-box проверены"
