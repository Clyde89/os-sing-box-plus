<?php

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
        failTest($label . ': получено неожиданное значение.');
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

$encoded = RuntimeConfigBuilder::encodeConfig($basePlan);
$decoded = json_decode($encoded, true);
if (!is_array($decoded)) {
    failTest('Сформированная runtime-конфигурация должна быть корректным JSON.');
}

$selectionPlan = RuntimeConfigBuilder::build([
    'capture' => [
        'mode' => 'selected',
        'clients' => "192.0.2.10\n192.0.2.0/24\n",
    ],
    'dns' => [
        'listenAddress' => '127.0.0.1',
        'listenPort' => 55353,
        'redirectDomains' => "example.org\n*.sub.example.org\n",
    ],
    'tun' => [
        'interfaceName' => 'tun_test',
        'address' => '172.20.0.1/30',
        'stack' => 'system',
    ],
]);

assertSameValue(false, $selectionPlan['apply_ready'], 'План с ещё не подключёнными селекторами должен блокировать применение');
assertSameValue(
    ['192.0.2.10', '192.0.2.0/24'],
    $selectionPlan['selectors']['clients'],
    'Нормализация списка клиентов'
);
assertSameValue(
    ['example.org', '*.sub.example.org'],
    $selectionPlan['selectors']['redirect_domains'],
    'Нормализация списка доменов'
);
if (count($selectionPlan['warnings']) !== 2) {
    failTest('План с клиентами и доменами должен содержать два предупреждения о неподключённых селекторах.');
}

$allLanPlan = RuntimeConfigBuilder::build([
    'capture' => ['mode' => 'all_lan'],
    'dns' => [],
    'tun' => [],
]);
assertSameValue(false, $allLanPlan['apply_ready'], 'Режим all_lan должен блокировать применение до генерации правил захвата');

$wrappedPlan = RuntimeConfigBuilder::build([
    'settings' => [
        'capture' => ['mode' => 'selected'],
        'dns' => ['listenPort' => '5353'],
        'tun' => [],
    ],
]);
assertSameValue(5353, $wrappedPlan['config']['inbounds'][1]['listen_port'], 'Поддержка корневого узла settings');

echo "Предварительный рендер runtime-конфигурации проверен\n";
