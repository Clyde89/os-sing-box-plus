<?php

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

assertPolicySame(1, $emptyPlan['schema_version'], 'Версия схемы policy-плана');
assertPolicySame('os-sing-box-plus', $emptyPlan['managed_by'], 'Владелец policy-плана');
assertPolicySame(false, $emptyPlan['required'], 'Пустой policy-план не должен требовать изменений OPNsense');
assertPolicySame(true, $emptyPlan['ready'], 'Пустой policy-план должен считаться готовым');
assertPolicySame([], $emptyPlan['operations'], 'Пустой policy-план не должен содержать операций');

$selectedPlan = PolicyPlanBuilder::build(
    'selected',
    ['lan'],
    ['192.0.2.10/32', '192.0.2.16/28'],
    ['domain' => ['example.org'], 'domain_suffix' => ['.sub.example.org']],
    '192.0.2.1',
    55353,
    '198.18.0.0/15',
    'tun_singbox',
    '172.19.0.1/30'
);

assertPolicySame(true, $selectedPlan['required'], 'Policy-план с доменами должен требовать изменений OPNsense');
assertPolicySame(false, $selectedPlan['ready'], 'Policy-план должен оставаться неготовым до настройки policy outbound');
assertPolicySame(false, $selectedPlan['confirmation_required'], 'Selected mode не должен требовать подтверждения all_lan');
assertPolicySame(['lan'], $selectedPlan['capture_interfaces'], 'Интерфейсы захвата selected mode');
assertPolicySame(true, $selectedPlan['dns_redirect']['ready'], 'DNS redirect selected mode с клиентами и интерфейсом');
assertPolicySame(['lan'], $selectedPlan['dns_redirect']['interfaces'], 'Интерфейсы DNS redirect');
assertPolicySame(['192.0.2.10/32', '192.0.2.16/28'], $selectedPlan['dns_redirect']['source_ip_cidr'], 'Source selectors DNS redirect');
assertPolicySame('192.0.2.1', $selectedPlan['dns_redirect']['target_address'], 'Целевой адрес DNS redirect');
assertPolicySame(55353, $selectedPlan['dns_redirect']['target_port'], 'Целевой порт DNS redirect');
assertPolicySame(true, $selectedPlan['fakeip_route']['ready'], 'Готовность FakeIP route');
assertPolicySame('198.18.0.0/15', $selectedPlan['fakeip_route']['network'], 'Сеть FakeIP route');
assertPolicySame('tun_singbox', $selectedPlan['fakeip_route']['interface'], 'Интерфейс FakeIP route');
assertPolicySame('unconfigured', $selectedPlan['policy_outbound']['mode'], 'Состояние policy outbound');
assertPolicySame(false, $selectedPlan['policy_outbound']['ready'], 'Policy outbound пока не должен считаться готовым');
assertPolicySame(3, count($selectedPlan['operations']), 'Количество декларативных операций selected mode');

$udpOperation = $selectedPlan['operations'][0] ?? null;
$tcpOperation = $selectedPlan['operations'][1] ?? null;
$routeOperation = $selectedPlan['operations'][2] ?? null;

if (!is_array($udpOperation) || !is_array($tcpOperation) || !is_array($routeOperation)) {
    failPolicyTest('Selected policy-план должен содержать две DNS-операции и FakeIP route.');
}

assertPolicySame('dns-redirect-lan-udp', $udpOperation['id'] ?? null, 'ID UDP DNS redirect');
assertPolicySame('lan', $udpOperation['interface'] ?? null, 'Интерфейс UDP DNS redirect');
assertPolicySame('udp', $udpOperation['protocol'] ?? null, 'Протокол UDP DNS redirect');
assertPolicySame(['192.0.2.10/32', '192.0.2.16/28'], $udpOperation['source_ip_cidr'] ?? null, 'Source selectors UDP DNS redirect');
assertPolicySame('dns-redirect-lan-tcp', $tcpOperation['id'] ?? null, 'ID TCP DNS redirect');
assertPolicySame('tcp', $tcpOperation['protocol'] ?? null, 'Протокол TCP DNS redirect');
assertPolicySame('fakeip-route-ipv4', $routeOperation['id'] ?? null, 'ID FakeIP route');
assertPolicySame('route', $routeOperation['type'] ?? null, 'Тип FakeIP route');

$missingClientsPlan = PolicyPlanBuilder::build(
    'selected',
    ['lan'],
    [],
    ['domain' => ['example.org'], 'domain_suffix' => []],
    '127.0.0.1',
    55353,
    '198.18.0.0/15',
    'tun_singbox',
    '172.19.0.1/30'
);

assertPolicySame(false, $missingClientsPlan['dns_redirect']['ready'], 'Selected mode без клиентов должен блокировать DNS redirect');
assertPolicySame(1, count($missingClientsPlan['operations']), 'Selected mode без клиентов не должен генерировать небезопасные DNS redirect операции');
assertPolicySame('fakeip-route-ipv4', $missingClientsPlan['operations'][0]['id'] ?? null, 'Без клиентов допустим только декларативный FakeIP route');

$missingInterfacesPlan = PolicyPlanBuilder::build(
    'selected',
    [],
    ['192.0.2.10/32'],
    ['domain' => ['example.org'], 'domain_suffix' => []],
    '127.0.0.1',
    55353,
    '198.18.0.0/15',
    'tun_singbox',
    '172.19.0.1/30'
);

assertPolicySame(false, $missingInterfacesPlan['dns_redirect']['ready'], 'Policy-план без интерфейсов должен блокировать DNS redirect');
assertPolicySame(1, count($missingInterfacesPlan['operations']), 'Без интерфейсов не должны формироваться DNS redirect операции');

$allLanPlan = PolicyPlanBuilder::build(
    'all_lan',
    ['lan', 'opt1'],
    [],
    ['domain' => ['example.org'], 'domain_suffix' => []],
    '192.0.2.1',
    55353,
    '198.18.0.0/15',
    'tun_singbox',
    '172.19.0.1/30'
);

assertPolicySame(true, $allLanPlan['confirmation_required'], 'All LAN должен требовать явного подтверждения');
assertPolicySame(true, $allLanPlan['dns_redirect']['ready'], 'All LAN DNS redirect формируется без source selectors при выбранных интерфейсах');
assertPolicySame(['lan', 'opt1'], $allLanPlan['capture_interfaces'], 'Интерфейсы all_lan');
assertPolicySame(5, count($allLanPlan['operations']), 'All LAN на двух интерфейсах должен формировать четыре DNS redirect и FakeIP route');
assertPolicySame([], $allLanPlan['operations'][0]['source_ip_cidr'] ?? null, 'All LAN DNS redirect не должен иметь source filter');
assertPolicySame('all_lan', $allLanPlan['operations'][0]['scope'] ?? null, 'Scope all_lan должен быть явным');

$invalidModeRejected = false;
try {
    PolicyPlanBuilder::build(
        'unexpected',
        [],
        [],
        ['domain' => [], 'domain_suffix' => []],
        '127.0.0.1',
        55353,
        '198.18.0.0/15',
        'tun_singbox',
        '172.19.0.1/30'
    );
} catch (InvalidArgumentException $error) {
    $invalidModeRejected = true;
}

if (!$invalidModeRejected) {
    failPolicyTest('PolicyPlanBuilder должен отклонять неизвестный режим захвата.');
}

echo "Декларативный policy-план OPNsense с интерфейсами захвата проверен\n";
