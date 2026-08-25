<?php

require_once __DIR__ . '/../src/usr/local/opnsense/mvc/app/models/OPNsense/SingBox/Runtime/PolicyPlanValidator.php';
require_once __DIR__ . '/../src/usr/local/opnsense/mvc/app/models/OPNsense/SingBox/Runtime/PolicyPlanBuilder.php';

use OPNsense\SingBox\Runtime\PolicyPlanBuilder;

function failPolicyTest(string $message): void
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function assertPolicySame($expected, $actual, string $label): void
{
    if ($expected !== $actual) {
        failPolicyTest($label . ': получено неожиданное значение: ' . var_export($actual, true));
    }
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

assertPolicySame(2, $emptyPlan['schema_version'], 'Версия схемы policy-плана');
assertPolicySame('os-sing-box-plus', $emptyPlan['managed_by'], 'Владелец policy-плана');
assertPolicySame(false, $emptyPlan['required'], 'Пустой policy-план не должен требовать правил');
assertPolicySame(true, $emptyPlan['ready'], 'Пустой policy-план должен считаться готовым');
assertPolicySame([], $emptyPlan['operations'], 'Пустой policy-план не должен содержать операций');

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

assertPolicySame(true, $selectedPlan['required'], 'Policy-план с доменами должен быть активным');
assertPolicySame(true, $selectedPlan['ready'], 'Настроенный selected policy-план должен быть готов');
assertPolicySame(false, $selectedPlan['confirmation_required'], 'Selected mode не должен требовать подтверждения all_lan');
assertPolicySame(['lan'], $selectedPlan['capture_interfaces'], 'Интерфейсы захвата selected mode');
assertPolicySame(true, $selectedPlan['dns_redirect']['ready'], 'DNS redirect selected mode');
assertPolicySame(['192.0.2.10/32', '192.0.2.16/28'], $selectedPlan['dns_redirect']['source_ip_cidr'], 'Source selectors DNS redirect');
assertPolicySame('sing_box_auto_route', $selectedPlan['fakeip_route']['mode'], 'FakeIP route должен управляться sing-box');
assertPolicySame(false, $selectedPlan['requires_opnsense_fakeip_route'], 'Отдельный route OPNsense для FakeIP не должен требоваться');
assertPolicySame(true, $selectedPlan['requires_singbox_fakeip_route'], 'FakeIP route должен требоваться в TUN runtime');
assertPolicySame('192.0.2.70', $selectedPlan['policy_outbound']['bind_address'], 'IPv4-адрес policy outbound');
assertPolicySame('VPN_GW', $selectedPlan['policy_outbound']['gateway'], 'Gateway policy outbound');
assertPolicySame(true, $selectedPlan['policy_outbound']['fail_closed'], 'Policy outbound должен использовать fail-closed');
assertPolicySame(4, count($selectedPlan['operations']), 'Selected plan должен содержать два DNS redirect и два policy правила');
assertPolicySame('dns_redirect', $selectedPlan['operations'][0]['type'] ?? null, 'Тип UDP DNS redirect');
assertPolicySame('dns_redirect', $selectedPlan['operations'][1]['type'] ?? null, 'Тип TCP DNS redirect');
assertPolicySame('policy_route', $selectedPlan['operations'][2]['type'] ?? null, 'Тип policy route');
assertPolicySame('VPN_GW', $selectedPlan['operations'][2]['gateway'] ?? null, 'Gateway операции policy route');
assertPolicySame('policy_block', $selectedPlan['operations'][3]['type'] ?? null, 'Тип fail-closed операции');

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
assertPolicySame(false, $missingGatewayPlan['ready'], 'Policy-план без gateway должен оставаться неготовым');
assertPolicySame(false, $missingGatewayPlan['policy_outbound']['ready'], 'Policy outbound без gateway должен быть неготовым');
assertPolicySame(2, count($missingGatewayPlan['operations']), 'Без gateway должны оставаться только DNS redirect операции');

$missingClientsPlan = PolicyPlanBuilder::build(
    'selected',
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
assertPolicySame(false, $missingClientsPlan['ready'], 'Selected mode без клиентов должен быть неготовым');
assertPolicySame(false, $missingClientsPlan['dns_redirect']['ready'], 'Selected DNS redirect без клиентов должен быть неготовым');
assertPolicySame(2, count($missingClientsPlan['operations']), 'Без клиентов должны формироваться только policy route и fail-closed');

$missingInterfacesPlan = PolicyPlanBuilder::build(
    'selected',
    [],
    ['192.0.2.10/32'],
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
assertPolicySame(false, $missingInterfacesPlan['ready'], 'Policy-план без интерфейсов должен быть неготовым');
assertPolicySame(2, count($missingInterfacesPlan['operations']), 'Без интерфейсов не должны формироваться DNS redirect операции');

$allLanPlan = PolicyPlanBuilder::build(
    'all_lan',
    ['lan', 'opt1'],
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
assertPolicySame(true, $allLanPlan['confirmation_required'], 'All LAN должен требовать отдельного подтверждения');
assertPolicySame(true, $allLanPlan['ready'], 'Декларативный all_lan plan может быть готов до подтверждения');
assertPolicySame(6, count($allLanPlan['operations']), 'All LAN на двух интерфейсах должен содержать четыре DNS redirect и два policy правила');
assertPolicySame([], $allLanPlan['operations'][0]['source_ip_cidr'] ?? null, 'All LAN DNS redirect не должен иметь source filter');

$invalidModeRejected = false;
try {
    PolicyPlanBuilder::build(
        'unexpected', [], [], ['domain' => [], 'domain_suffix' => []],
        '127.0.0.1', 55353, '198.18.0.0/15', 'tun_singbox', '172.19.0.1/30'
    );
} catch (InvalidArgumentException $error) {
    $invalidModeRejected = true;
}
if (!$invalidModeRejected) {
    failPolicyTest('PolicyPlanBuilder должен отклонять неизвестный режим захвата.');
}

echo "Декларативный policy-план DNS/FakeIP/gateway/fail-closed проверен\n";
