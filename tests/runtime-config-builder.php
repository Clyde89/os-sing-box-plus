<?php

require_once __DIR__ . '/../src/usr/local/opnsense/mvc/app/models/OPNsense/SingBox/Runtime/SelectorCompiler.php';
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
        'clients' => '',
    ],
    'dns' => [
        'listenAddress' => '127.0.0.1',
        'listenPort' => '55353',
        'redirectDomains' => '',
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
assertSameValue([], $basePlan['selectors']['source_ip_cidr'], 'Базовый план не должен содержать скомпилированные адреса клиентов');

$encoded = RuntimeConfigBuilder::encodeConfig($basePlan);
$decoded = json_decode($encoded, true);
if (!is_array($decoded)) {
    failTest('Сформированная runtime-конфигурация должна быть корректным JSON.');
}

$selectionPlan = RuntimeConfigBuilder::build([
    'capture' => [
        'mode' => 'selected',
        'clients' => "192.0.2.10-192.0.2.20\n2001:db8::10\n",
    ],
    'dns' => [
        'listenAddress' => '127.0.0.1',
        'listenPort' => 55353,
        'redirectDomains' => "Example.org.\n*.Sub.Example.org.\n",
    ],
    'tun' => [
        'interfaceName' => 'tun_test',
        'address' => '172.20.0.1/30',
        'stack' => 'system',
    ],
]);

assertSameValue(false, $selectionPlan['apply_ready'], 'План с policy-селекторами должен блокировать применение до подключения policy routing');
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
assertSameValue('198.18.0.0/15', $selectionPlan['policy_plan']['fakeip_ipv4_range'], 'Диапазон FakeIP IPv4');
assertSameValue(['A'], $selectionPlan['policy_plan']['dns_query_types'], 'Типы DNS-запросов FakeIP preview');
assertSameValue(true, $selectionPlan['policy_plan']['requires_opnsense_dns_redirect'], 'Требование DNS redirect OPNsense');
assertSameValue(true, $selectionPlan['policy_plan']['requires_opnsense_fakeip_route'], 'Требование FakeIP route OPNsense');
assertSameValue(true, $selectionPlan['policy_plan']['requires_policy_outbound'], 'Требование policy outbound');

$fakeipServer = $selectionPlan['config']['dns']['servers'][1] ?? null;
if (!is_array($fakeipServer)) {
    failTest('При наличии доменов должен формироваться FakeIP DNS server.');
}
assertSameValue('fakeip', $fakeipServer['type'] ?? null, 'Тип FakeIP DNS server');
assertSameValue('fakeip-dns', $fakeipServer['tag'] ?? null, 'Тег FakeIP DNS server');
assertSameValue('198.18.0.0/15', $fakeipServer['inet4_range'] ?? null, 'Диапазон FakeIP server');

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

if (count($selectionPlan['warnings']) !== 3) {
    failTest('Policy preview с доменами должен содержать три предупреждения о ещё не подключённых runtime-компонентах.');
}

$missingClientsPlan = RuntimeConfigBuilder::build([
    'capture' => ['mode' => 'selected', 'clients' => ''],
    'dns' => ['redirectDomains' => 'example.org'],
    'tun' => [],
]);
assertSameValue(false, $missingClientsPlan['apply_ready'], 'Selected mode без клиентов должен блокировать применение');
if (isset($missingClientsPlan['config']['dns']['rules'])) {
    failTest('Selected mode без клиентов не должен формировать небезопасное DNS/FakeIP правило без source filter.');
}

$allLanPlan = RuntimeConfigBuilder::build([
    'capture' => ['mode' => 'all_lan'],
    'dns' => ['redirectDomains' => 'example.org'],
    'tun' => [],
]);
assertSameValue(false, $allLanPlan['apply_ready'], 'Режим all_lan должен блокировать применение до генерации правил OPNsense');
$allLanDnsRule = $allLanPlan['config']['dns']['rules'][0] ?? null;
if (!is_array($allLanDnsRule)) {
    failTest('Режим all_lan с доменами должен формировать DNS/FakeIP preview без source filter.');
}
if (array_key_exists('source_ip_cidr', $allLanDnsRule)) {
    failTest('Режим all_lan не должен добавлять source_ip_cidr в DNS/FakeIP правило.');
}

$wrappedPlan = RuntimeConfigBuilder::build([
    'settings' => [
        'capture' => ['mode' => 'selected'],
        'dns' => ['listenPort' => '5353'],
        'tun' => [],
    ],
]);
assertSameValue(5353, $wrappedPlan['config']['inbounds'][1]['listen_port'], 'Поддержка корневого узла settings');

echo "Предварительный рендер runtime-конфигурации проверен\n";
