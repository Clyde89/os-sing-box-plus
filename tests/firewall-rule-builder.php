<?php

require_once __DIR__ . '/../src/usr/local/opnsense/mvc/app/models/OPNsense/SingBox/Runtime/PolicyPlanValidator.php';
require_once __DIR__ . '/../src/usr/local/opnsense/mvc/app/models/OPNsense/SingBox/Runtime/PolicyPlanBuilder.php';
require_once __DIR__ . '/../src/usr/local/opnsense/mvc/app/models/OPNsense/SingBox/Runtime/FirewallRuleBuilder.php';

use OPNsense\SingBox\Runtime\FirewallRuleBuilder;
use OPNsense\SingBox\Runtime\PolicyPlanBuilder;

function failFirewallRuleTest(string $message): void
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function assertFirewallSame($expected, $actual, string $label): void
{
    if ($expected !== $actual) {
        failFirewallRuleTest($label . ': получено неожиданное значение: ' . var_export($actual, true));
    }
}

function assertFirewallRejected(array $plan, string $label): void
{
    try {
        FirewallRuleBuilder::build($plan);
    } catch (RuntimeException $error) {
        return;
    }

    failFirewallRuleTest($label . ': ожидался отказ от генерации firewall-правил.');
}

$emptyPlan = PolicyPlanBuilder::build(
    'selected',
    [],
    [],
    ['domain' => [], 'domain_suffix' => []],
    '127.0.0.1',
    55353,
    '198.18.0.0/15',
    'tun_singbox',
    '172.19.0.1/30'
);
assertFirewallSame(
    ['destination_nat' => [], 'filter' => []],
    FirewallRuleBuilder::build($emptyPlan),
    'Пустой policy-план'
);

$selectedPlan = PolicyPlanBuilder::build(
    'selected',
    ['lan'],
    ['192.0.2.10/32', '192.0.2.16/28'],
    ['domain' => ['example.org'], 'domain_suffix' => ['.sub.example.org']],
    '127.0.0.1',
    55353,
    '198.18.0.0/15',
    'tun_singbox',
    '172.19.0.1/30',
    'direct_bind',
    '192.0.2.70',
    'VPN_GW'
);
$rules = FirewallRuleBuilder::build($selectedPlan);

assertFirewallSame(4, count($rules['destination_nat']), 'Количество DNS redirect правил');
assertFirewallSame(2, count($rules['filter']), 'Количество policy filter правил');

$expectedDnsRules = [
    ['dns-redirect-lan-udp', 'udp', '192.0.2.10/32'],
    ['dns-redirect-lan-udp', 'udp', '192.0.2.16/28'],
    ['dns-redirect-lan-tcp', 'tcp', '192.0.2.10/32'],
    ['dns-redirect-lan-tcp', 'tcp', '192.0.2.16/28'],
];
foreach ($expectedDnsRules as $index => [$operation, $protocol, $source]) {
    $rule = $rules['destination_nat'][$index] ?? [];
    assertFirewallSame(2, $rule['#priority'] ?? null, 'Приоритет DNS redirect #' . ($index + 1));
    assertFirewallSame($operation, $rule['#operation'] ?? null, 'Операция DNS redirect #' . ($index + 1));
    assertFirewallSame('lan', $rule['interface'] ?? null, 'Интерфейс DNS redirect #' . ($index + 1));
    assertFirewallSame($protocol, $rule['protocol'] ?? null, 'Протокол DNS redirect #' . ($index + 1));
    assertFirewallSame($source, $rule['from'] ?? null, 'Source selector DNS redirect #' . ($index + 1));
    assertFirewallSame('53', $rule['to_port'] ?? null, 'Перехватываемый порт DNS redirect #' . ($index + 1));
    assertFirewallSame('127.0.0.1', $rule['target'] ?? null, 'Целевой адрес DNS redirect #' . ($index + 1));
    assertFirewallSame('55353', $rule['localport'] ?? null, 'Целевой порт DNS redirect #' . ($index + 1));
    assertFirewallSame(true, $rule['pass'] ?? null, 'Pass DNS redirect #' . ($index + 1));
    assertFirewallSame('disable', $rule['natreflection'] ?? null, 'NAT reflection DNS redirect #' . ($index + 1));
}

$routeRule = $rules['filter'][0] ?? [];
assertFirewallSame(2, $routeRule['#priority'] ?? null, 'Приоритет policy route');
assertFirewallSame('policy-outbound-route', $routeRule['#operation'] ?? null, 'Операция policy route');
assertFirewallSame('pass', $routeRule['type'] ?? null, 'Действие policy route');
assertFirewallSame('out', $routeRule['direction'] ?? null, 'Направление policy route');
assertFirewallSame(true, $routeRule['quick'] ?? null, 'Quick policy route');
assertFirewallSame('192.0.2.70', $routeRule['from'] ?? null, 'Исходящий адрес policy route');
assertFirewallSame('VPN_GW', $routeRule['gateway'] ?? null, 'Gateway policy route');
assertFirewallSame(true, $routeRule['skip_rules_gw_down'] ?? null, 'Пропуск policy route при недоступном gateway');

$blockRule = $rules['filter'][1] ?? [];
assertFirewallSame(3, $blockRule['#priority'] ?? null, 'Приоритет fail-closed');
assertFirewallSame('policy-outbound-block', $blockRule['#operation'] ?? null, 'Операция fail-closed');
assertFirewallSame('block', $blockRule['type'] ?? null, 'Действие fail-closed');
assertFirewallSame('out', $blockRule['direction'] ?? null, 'Направление fail-closed');
assertFirewallSame(true, $blockRule['quick'] ?? null, 'Quick fail-closed');
assertFirewallSame('192.0.2.70', $blockRule['from'] ?? null, 'Исходящий адрес fail-closed');
assertFirewallSame(false, array_key_exists('gateway', $blockRule), 'Fail-closed не должен зависеть от gateway');

$missingGatewayPlan = PolicyPlanBuilder::build(
    'selected',
    ['lan'],
    ['192.0.2.10/32'],
    ['domain' => ['example.org'], 'domain_suffix' => []],
    '127.0.0.1',
    55353,
    '198.18.0.0/15',
    'tun_singbox',
    '172.19.0.1/30',
    'direct_bind',
    '192.0.2.70',
    ''
);
assertFirewallRejected($missingGatewayPlan, 'Policy-план без gateway');

$allLanPlan = PolicyPlanBuilder::build(
    'all_lan',
    ['lan'],
    [],
    ['domain' => ['example.org'], 'domain_suffix' => []],
    '127.0.0.1',
    55353,
    '198.18.0.0/15',
    'tun_singbox',
    '172.19.0.1/30',
    'direct_bind',
    '192.0.2.70',
    'VPN_GW'
);
assertFirewallRejected($allLanPlan, 'Неподтверждённый all_lan policy-план');

echo "Генерация DNS redirect, policy route и fail-closed правил OPNsense проверена\n";
