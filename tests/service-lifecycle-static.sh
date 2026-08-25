#!/bin/sh
set -eu

ROOT_DIR="$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"
RC_SCRIPT="$ROOT_DIR/src/usr/local/etc/rc.d/sing-box"

[ -f "$RC_SCRIPT" ]

line_of() {
    grep -nF "$1" "$RC_SCRIPT" | "$2" -n 1 | cut -d: -f1
}

first_line_of() {
    line_of "$1" head
}

last_line_of() {
    line_of "$1" tail
}

grep -q '^configured_tun_interface()' "$RC_SCRIPT"
grep -q '^process_is_sing_box()' "$RC_SCRIPT"
grep -q '^reload_firewall()' "$RC_SCRIPT"
grep -q '^activate_policy_rules()' "$RC_SCRIPT"
grep -q '^deactivate_policy_rules()' "$RC_SCRIPT"
grep -q '^cleanup_failed_start()' "$RC_SCRIPT"
grep -Fq 'state_dir="/var/db/os-sing-box"' "$RC_SCRIPT"
grep -Fq 'pending_filter_reload="${state_dir}/filter-reload.pending"' "$RC_SCRIPT"
grep -Fq 'setup_required="${state_dir}/setup-required"' "$RC_SCRIPT"
grep -Fq 'managed_policy="${state_dir}/managed-policy"' "$RC_SCRIPT"
grep -Fq 'policy_plan="${state_dir}/policy-plan.json"' "$RC_SCRIPT"
grep -Fq 'tun_interface_file="${state_dir}/tun-interface"' "$RC_SCRIPT"
grep -Fq 'policy_active="/var/run/sing-box-policy-active"' "$RC_SCRIPT"
grep -Fq 'Запуск sing-box отклонён: первоначальная настройка не завершена.' "$RC_SCRIPT"
grep -Fq 'cleanup_failed_start "$started_pid"' "$RC_SCRIPT"
grep -Fq 'chmod 0600 "$pidfile"' "$RC_SCRIPT"
grep -Fq 'PID-файл указывал на другой процесс' "$RC_SCRIPT"
grep -Fq 'Процесс sing-box уже запущен, но интерфейс $tun_interface отсутствует.' "$RC_SCRIPT"
grep -Fq 'Процесс sing-box работает, но интерфейс $tun_interface отсутствует.' "$RC_SCRIPT"
grep -Fq 'Интерфейс $tun_interface не был создан за 20 секунд; запуск отменён.' "$RC_SCRIPT"
grep -Fq 'Запуск sing-box отменён: управляемые правила firewall не активированы.' "$RC_SCRIPT"
grep -Fq 'Остановка sing-box отменена: правила firewall не удалось безопасно отключить.' "$RC_SCRIPT"
grep -Fq 'policy_checksum="$(sha256 -q "$policy_plan" 2>/dev/null || true)"' "$RC_SCRIPT"
grep -Fq 'printf '\''%s\n'\'' "$policy_checksum" > "$policy_active" || return 1' "$RC_SCRIPT"
grep -Fq 'chmod 0600 "$policy_active"' "$RC_SCRIPT"
grep -Fq 'rm -f "$policy_active"' "$RC_SCRIPT"
grep -Fq 'reload_firewall >/dev/null 2>&1 || true' "$RC_SCRIPT"
grep -Fq 'printf '\''%s\n'\'' "$active_checksum" > "$policy_active" 2>/dev/null || true' "$RC_SCRIPT"

setup_line="$(first_line_of 'if [ -f "$setup_required" ]; then')"
log_line="$(first_line_of 'if ! ensure_log_path; then')"
[ -n "$setup_line" ]
[ -n "$log_line" ]
[ "$setup_line" -lt "$log_line" ]

interface_timeout_line="$(first_line_of 'Интерфейс $tun_interface не был создан за 20 секунд; запуск отменён.')"
activate_start_line="$(last_line_of 'if ! activate_policy_rules; then')"
activate_error_line="$(first_line_of 'Запуск sing-box отменён: управляемые правила firewall не активированы.')"
activate_cleanup_line="$(last_line_of 'cleanup_failed_start "$started_pid"')"
success_line="$(first_line_of 'echo "Служба запущена, PID $started_pid."')"
[ "$interface_timeout_line" -lt "$activate_start_line" ]
[ "$activate_start_line" -lt "$activate_error_line" ]
[ "$activate_error_line" -lt "$activate_cleanup_line" ]
[ "$activate_cleanup_line" -lt "$success_line" ]

checksum_line="$(first_line_of 'policy_checksum="$(sha256 -q "$policy_plan" 2>/dev/null || true)"')"
active_write_line="$(first_line_of 'printf '\''%s\n'\'' "$policy_checksum" > "$policy_active" || return 1')"
activate_reload_line="$(first_line_of 'if ! reload_firewall; then')"
pending_remove_line="$(first_line_of 'rm -f "$pending_filter_reload"')"
[ "$checksum_line" -lt "$active_write_line" ]
[ "$active_write_line" -lt "$activate_reload_line" ]
[ "$activate_reload_line" -lt "$pending_remove_line" ]

cleanup_deactivate_line="$(first_line_of 'deactivate_policy_rules >/dev/null 2>&1 || true')"
cleanup_process_line="$(first_line_of 'if process_is_sing_box "$pid"; then')"
[ "$cleanup_deactivate_line" -lt "$cleanup_process_line" ]

deactivate_checksum_line="$(first_line_of 'active_checksum="$(cat "$policy_active" 2>/dev/null || true)"')"
deactivate_remove_line="$(last_line_of 'rm -f "$policy_active"')"
deactivate_reload_line="$(last_line_of 'if ! reload_firewall; then')"
deactivate_restore_line="$(first_line_of 'printf '\''%s\n'\'' "$active_checksum" > "$policy_active" 2>/dev/null || true')"
[ "$deactivate_checksum_line" -lt "$deactivate_remove_line" ]
[ "$deactivate_remove_line" -lt "$deactivate_reload_line" ]
[ "$deactivate_reload_line" -lt "$deactivate_restore_line" ]

stop_deactivate_line="$(first_line_of 'if ! deactivate_policy_rules; then')"
stop_kill_line="$(first_line_of 'if kill "$pid" && wait_for_exit "$pid" 10; then')"
[ "$stop_deactivate_line" -lt "$stop_kill_line" ]

echo "Управляемый жизненный цикл службы и policy-правил sing-box проверен"
