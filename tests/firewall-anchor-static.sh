#!/bin/sh
set -eu

ROOT_DIR="$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"
PLUGIN="$ROOT_DIR/src/usr/local/etc/inc/plugins.inc.d/sing_box.inc"

[ -f "$PLUGIN" ]

grep -q '^function sing_box_policy_is_managed()' "$PLUGIN"
grep -q '^function sing_box_policy_is_active()' "$PLUGIN"
grep -q '^function sing_box_load_policy_plan()' "$PLUGIN"
grep -q '^function sing_box_gateway_available($fw, $gateway)' "$PLUGIN"
grep -q '^function sing_box_register_policy_rules($fw, $rules)' "$PLUGIN"
grep -q '^function sing_box_firewall($fw)' "$PLUGIN"
grep -q "is_file('/var/db/os-sing-box/managed-policy')" "$PLUGIN"
grep -Fq "'/var/db/os-sing-box/policy-plan.json'" "$PLUGIN"
grep -Fq "'/var/run/sing-box-policy-active'" "$PLUGIN"
grep -Fq "hash_file('sha256', \$planPath)" "$PLUGIN"
grep -Fq 'hash_equals($currentChecksum, $activeChecksum)' "$PLUGIN"
grep -Fq 'PolicyPlanValidator::assertValid($plan)' "$PLUGIN"
grep -Fq 'FirewallRuleBuilder::build($plan)' "$PLUGIN"
grep -Fq 'registerDestinationNatRule($priority, $rule)' "$PLUGIN"
grep -Fq 'registerFilterRule($priority, $rule)' "$PLUGIN"
grep -Fq "sing_box_gateway_available(\$fw, \$rule['gateway'])" "$PLUGIN"
grep -Fq 'policy route пропущен, fail-closed правило сохранено' "$PLUGIN"
grep -Fq 'sing_box_register_policy_rules($fw, $rules)' "$PLUGIN"

if grep -Eq 'config\.xml|pfctl[[:space:]]|registerAnchor' "$PLUGIN"; then
    echo "Регистрация firewall-правил не должна изменять config.xml, вызывать pfctl или использовать устаревшие anchors" >&2
    exit 1
fi

echo "Безопасная регистрация нативных firewall-правил sing-box проверена"
