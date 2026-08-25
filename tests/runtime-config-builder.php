<?php

require_once __DIR__ . '/../src/usr/local/opnsense/mvc/app/models/OPNsense/SingBox/Validation/SelectionValidator.php';
require_once __DIR__ . '/../src/usr/local/opnsense/mvc/app/models/OPNsense/SingBox/Runtime/SelectorCompiler.php';
require_once __DIR__ . '/../src/usr/local/opnsense/mvc/app/models/OPNsense/SingBox/Runtime/PolicyPlanBuilder.php';
require_once __DIR__ . '/../src/usr/local/opnsense/mvc/app/models/OPNsense/SingBox/Runtime/RuntimeConfigBuilder.php';

use OPNsense\SingBox\Runtime\RuntimeConfigBuilder;

function failTest(string $message): void
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function assertSameValue($expected, $actual, string $label): void
{
    if ($expected !== $actual) {
        failTest($label . ': получено неожиданное значение: ' . var_export($actual, true));
    }
}

$basePlan = RuntimeConfigBuilder::build([
    'capture' => [
        'mode' => 'selected',
        'interfaces' => '',
        'clients' => '',
    ],
    'dns' => [
        'listenAddress' => '127.0.0.1',
        'listenPort' => '55353',
        'redirectDomains' => '',
        'fakeIpRange' => '198.18.0.0/15',
    ],
    'policy' => [
        'outboundMode' => 'direct_bind',
        'bindAddress' => '',
    ],
    'tun' => [
        'interfaceName' => 'tun_singbox',
        'address' => '172.19.0.1/30',
        'stack' => 'system',
    ],
]);

assertSameValue(true, $basePlan['apply_ready'], 'Базовый план должен быть готов к применению');
assertSameValue([], $basePlan['warnings'], 'Базовый план не должен содержать предупреждений');
assertSameValue('tun_singbox', $basePlan['config']['inbounds'][0]['interface_name'], 'Имя TUN-интерфейса');
assertSameValue(['172.19.0.1/30'], $basePlan['config']['inbounds'][0]['address'], 'Адрес TUN-интерфейса');
assertSameValue('127.0.0.1', $basePlan['config']['inbounds'][1]['listen'], 'Адрес DNS-listener');
assertSameValue(55353, $basePlan['config']['inbounds'][1]['listen_port'], 'Порт DNS-listener');
assertSameValue('hijack-dns', $basePlan['config']['route']['rules'][0]['action'], 'DNS hijack action');
assertSameValue([], $basePlan['selectors']['capture_interfaces'], 'Базовый план не должен содержать интерфейсы захвата');
assertSameValue([], $basePlan['selectors']['source_ip_cidr'], 'Базовый план не должен содержать скомпилированные адреса клиентов');
assertSameValue('198.18.0.0/15', $basePlan['policy_plan']['fakeip_ipv4_range'], 'Базовый диапазон FakeIP');
assertSameValue(1, $basePlan['policy_plan']['schema_version'], 'Версия декларативного policy-плана');
assertSameValue(false, $basePlan['policy_plan']['required'], 'Базовый policy-план не должен требовать изменений OPNsense');
assertSameValue([], $basePlan['policy_plan']['operations'], 'Базовый policy-план не должен содержать операций OPNsense');
assertSameValue(1, count($basePlan['config']['outbounds']), 'Базовый план не должен добавлять policy outbound без доменов');

$encoded = RuntimeConfigBuilder::encodeConfig($basePlan);
$decoded = json_decode($encoded, true);
if (!is_array($decoded)) {
    failTest('Сформированная runtime-конфигурация должна быть корректным JSON.');
}

$selectionPlan = RuntimeConfigBuilder::build([
    'capture' => [
        'mode' => 'selected',
        'interfaces' => 'lan',
        'clients' => "192.0.2.10-192.0.2.20\n2001:db8::10\n",
    ],
    'dns' => [
        'listenAddress' => '127.0.0.1',
        'listenPort' => 55353,
        'redirectDomains' => "Example.org.\n*.Sub.Example.org.\n",
        'fakeIpRange' => '198.20.0.0/16',
    ],
    'policy' => [
        'outboundMode' => 'direct_bind',
        'bindAddress' => '192.0.2.70',
    ],
    'tun' => [
        'interfaceName' => 'tun_test',
        'address' => '172.20.0.1/30',
        'stack' => 'system',
    ],
]);

assertSameValue(false, $selectionPlan['apply_ready'], 'План с policy-селекторами должен блокировать применение до подключения правил OPNsense');
assertSameValue(['lan'], $selectionPlan['selectors']['capture_interfaces'], 'Интерфейсы захвата runtime preview');
assertSameValue(
    ['192.0.2.10-192.0.2.20', '2001:db8::10'],
    $selectionPlan['selectors']['clients'],
    'Исходный список клиентов'
);
assertSameValue(
    ['192.0.2.10/31', '192.0.2.12/30', '192.0.2.16/30', '192.0.2.20/32', '2001:db8::10'],
    $selectionPlan['selectors']['source_ip_cidr'],
    'Компиляция списка клиентов в source_ip_cidr'
);
assertSameValue(['example.org'], $selectionPlan['selectors']['domain'], 'Компиляция точных доменов');
assertSameValue(['.sub.example.org'], $selectionPlan['selectors']['domain_suffix'], 'Компиляция wildcard-доменов');
assertSameValue('direct_bind', $selectionPlan['selectors']['policy_outbound_mode'], 'Режим policy outbound preview');
assertSameValue('192.0.2.70', $selectionPlan['selectors']['policy_bind_address'], 'Bind address policy outbound preview');
assertSameValue(['lan'], $selectionPlan['policy_plan']['capture_interfaces'], 'Интерфейсы захвата policy-плана');
assertSameValue('198.20.0.0/16', $selectionPlan['policy_plan']['fakeip_ipv4_range'], 'Пользовательский диапазон FakeIP IPv4');
assertSameValue(['A'], $selectionPlan['policy_plan']['dns_query_types'], 'Типы DNS-запросов FakeIP preview');
assertSameValue(true, $selectionPlan['policy_plan']['requires_opnsense_dns_redirect'], 'Требование DNS redirect OPNsense');
assertSameValue(true, $selectionPlan['policy_plan']['requires_opnsense_fakeip_route'], 'Требование FakeIP route OPNsense');
assertSameValue(true, $selectionPlan['policy_plan']['requires_policy_outbound'], 'Требование policy outbound');
assertSameValue(true, $selectionPlan['policy_plan']['ready'], 'Policy-план должен быть готов после настройки source-bound outbound');
assertSameValue('192.0.2.70', $selectionPlan['policy_plan']['policy_outbound']['bind_address'], 'Bind address декларативного policy outbound');
assertSameValue('127.0.0.1', $selectionPlan['policy_plan']['dns_redirect']['target_address'], 'Целевой адрес DNS redirect');
assertSameValue(55353, $selectionPlan['policy_plan']['dns_redirect']['target_port'], 'Целевой порт DNS redirect');
assertSameValue(3, count($selectionPlan['policy_plan']['operations']), 'Количество декларативных операций policy-плана');
assertSameValue('lan', $selectionPlan['policy_plan']['operations'][0]['interface'] ?? null, 'Интерфейс первой DNS redirect операции');

$fakeipServer = $selectionPlan['config']['dns']['servers'][1] ?? null;
if (!is_array($fakeipServer)) {
    failTest('При наличии доменов должен формироваться FakeIP DNS server.');
}
assertSameValue('fakeip', $fakeipServer['type'] ?? null, 'Тип FakeIP DNS server');
assertSameValue('fakeip-dns', $fakeipServer['tag'] ?? null, 'Тег FakeIP DNS server');
assertSameValue('198.20.0.0/16', $fakeipServer['inet4_range'] ?? null, 'Пользовательский диапазон FakeIP server');

$dnsRule = $selectionPlan['config']['dns']['rules'][0] ?? null;
if (!is_array($dnsRule)) {
    failTest('При наличии клиентов и доменов должно формироваться DNS/FakeIP правило preview.');
}
assertSameValue(['A'], $dnsRule['query_type'] ?? null, 'DNS query_type правила FakeIP');
assertSameValue(['example.org'], $dnsRule['domain'] ?? null, 'Точные домены DNS/FakeIP правила');
assertSameValue(['.sub.example.org'], $dnsRule['domain_suffix'] ?? null, 'Wildcard-домены DNS/FakeIP правила');
assertSameValue(
    ['192.0.2.10/31', '192.0.2.12/30', '192.0.2.16/30', '192.0.2.20/32', '2001:db8::10'],
    $dnsRule['source_ip_cidr'] ?? null,
    'Клиенты DNS/FakeIP правила'
);
assertSameValue('route', $dnsRule['action'] ?? null, 'Действие DNS/FakeIP правила');
assertSameValue('fakeip-dns', $dnsRule['server'] ?? null, 'DNS server правила FakeIP');

$policyOutbound = $selectionPlan['config']['outbounds'][1] ?? null;
if (!is_array($policyOutbound)) {
    failTest('Настроенный policy plan должен формировать отдельный outbound.');
}
assertSameValue('direct', $policyOutbound['type'] ?? null, 'Тип policy outbound');
assertSameValue('policy-out', $policyOutbound['tag'] ?? null, 'Тег policy outbound');
assertSameValue('192.0.2.70', $policyOutbound['inet4_bind_address'] ?? null, 'Source bind policy outbound');

$policyRouteRule = $selectionPlan['config']['route']['rules'][1] ?? null;
if (!is_array($policyRouteRule)) {
    failTest('Настроенный policy plan должен формировать маршрут FakeIP в policy outbound.');
}
assertSameValue(['198.20.0.0/16'], $policyRouteRule['ip_cidr'] ?? null, 'FakeIP CIDR route rule');
assertSameValue('route', $policyRouteRule['action'] ?? null, 'Действие route rule policy outbound');
assertSameValue('policy-out', $policyRouteRule['outbound'] ?? null, 'Целевой outbound route rule');

if (count($selectionPlan['warnings']) !== 2) {
    failTest('Policy preview с настроенным outbound должен содержать два предупреждения о ещё не подключённых компонентах OPNsense/IPv6.');
}

$missingOutboundPlan = RuntimeConfigBuilder::build([
    'capture' => ['mode' => 'selected', 'interfaces' => 'lan', 'clients' => '192.0.2.10'],
    'dns' => ['redirectDomains' => 'example.org'],
    'policy' => ['outboundMode' => 'direct_bind', 'bindAddress' => ''],
    'tun' => [],
]);
assertSameValue(false, $missingOutboundPlan['apply_ready'], 'Policy preview без bind address должен блокировать применение');
assertSameValue(false, $missingOutboundPlan['policy_plan']['policy_outbound']['ready'], 'Policy plan без bind address должен оставаться неготовым');
assertSameValue(1, count($missingOutboundPlan['config']['outbounds']), 'Без bind address не должен формироваться небезопасный policy outbound');

$missingClientsPlan = RuntimeConfigBuilder::build([
    'capture' => ['mode' => 'selected', 'interfaces' => 'lan', 'clients' => ''],
    'dns' => ['redirectDomains' => 'example.org'],
    'policy' => ['bindAddress' => '192.0.2.70'],
    'tun' => [],
]);
assertSameValue(false, $missingClientsPlan['apply_ready'], 'Selected mode без клиентов должен блокировать применение');
assertSameValue(false, $missingClientsPlan['policy_plan']['dns_redirect']['ready'], 'Selected mode без клиентов должен блокировать декларативный DNS redirect');
if (isset($missingClientsPlan['config']['dns']['rules'])) {
    failTest('Selected mode без клиентов не должен формировать небезопасное DNS/FakeIP правило без source filter.');
}

$missingInterfacesPlan = RuntimeConfigBuilder::build([
    'capture' => ['mode' => 'selected', 'interfaces' => '', 'clients' => '192.0.2.10'],
    'dns' => ['redirectDomains' => 'example.org'],
    'policy' => ['bindAddress' => '192.0.2.70'],
    'tun' => [],
]);
assertSameValue(false, $missingInterfacesPlan['apply_ready'], 'Policy preview без интерфейсов должен блокировать применение');
assertSameValue(false, $missingInterfacesPlan['policy_plan']['dns_redirect']['ready'], 'Policy preview без интерфейсов должен блокировать DNS redirect OPNsense');
if (!isset($missingInterfacesPlan['config']['dns']['rules'][0])) {
    failTest('Отсутствие интерфейса OPNsense не должно удалять безопасный DNS/FakeIP preview sing-box.');
}

$allLanPlan = RuntimeConfigBuilder::build([
    'capture' => ['mode' => 'all_lan', 'interfaces' => ['lan', 'opt1']],
    'dns' => ['redirectDomains' => 'example.org'],
    'policy' => ['bindAddress' => '192.0.2.70'],
    'tun' => [],
]);
assertSameValue(false, $allLanPlan['apply_ready'], 'Режим all_lan должен блокировать применение до генерации правил OPNsense');
assertSameValue(true, $allLanPlan['policy_plan']['confirmation_required'], 'Режим all_lan должен требовать явного подтверждения');
assertSameValue(['lan', 'opt1'], $allLanPlan['policy_plan']['capture_interfaces'], 'Интерфейсы all_lan policy-плана');
assertSameValue(5, count($allLanPlan['policy_plan']['operations']), 'All LAN на двух интерфейсах должен сформировать четыре DNS redirect и FakeIP route');
$allLanDnsRule = $allLanPlan['config']['dns']['rules'][0] ?? null;
if (!is_array($allLanDnsRule)) {
    failTest('Режим all_lan с доменами должен формировать DNS/FakeIP preview без source filter.');
}
if (array_key_exists('source_ip_cidr', $allLanDnsRule)) {
    failTest('Режим all_lan не должен добавлять source_ip_cidr в DNS/FakeIP правило.');
}

$wanRejected = false;
try {
    RuntimeConfigBuilder::build([
        'capture' => ['mode' => 'selected', 'interfaces' => 'wan'],
        'dns' => [],
        'tun' => [],
    ]);
} catch (RuntimeException $error) {
    $wanRejected = true;
}
if (!$wanRejected) {
    failTest('Runtime builder должен отклонять WAN как интерфейс автоматического захвата.');
}

$invalidRangeRejected = false;
try {
    RuntimeConfigBuilder::build([
        'capture' => ['mode' => 'selected'],
        'dns' => ['fakeIpRange' => '198.18.0.1/15'],
        'tun' => [],
    ]);
} catch (RuntimeException $error) {
    $invalidRangeRejected = true;
}
if (!$invalidRangeRejected) {
    failTest('Runtime builder должен отклонять FakeIP-сеть с host-битами.');
}

$invalidBindRejected = false;
try {
    RuntimeConfigBuilder::build([
        'capture' => ['mode' => 'selected'],
        'dns' => [],
        'policy' => ['bindAddress' => '2001:db8::10'],
        'tun' => [],
    ]);
} catch (RuntimeException $error) {
    $invalidBindRejected = true;
}
if (!$invalidBindRejected) {
    failTest('Runtime builder должен отклонять IPv6 в IPv4 bind address policy outbound.');
}

$wrappedPlan = RuntimeConfigBuilder::build([
    'settings' => [
        'capture' => ['mode' => 'selected', 'interfaces' => 'lan,opt1'],
        'dns' => ['listenPort' => '5353'],
        'policy' => ['outboundMode' => 'direct_bind'],
        'tun' => [],
    ],
]);
assertSameValue(5353, $wrappedPlan['config']['inbounds'][1]['listen_port'], 'Поддержка корневого узла settings');
assertSameValue(['lan', 'opt1'], $wrappedPlan['selectors']['capture_interfaces'], 'Разбор списка интерфейсов из строки MVC');

echo "Предварительный рендер runtime-конфигурации с source-bound policy outbound проверен\n";
