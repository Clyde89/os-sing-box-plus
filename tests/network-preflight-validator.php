<?php

require_once __DIR__ . '/../src/usr/local/opnsense/mvc/app/models/OPNsense/SingBox/Runtime/NetworkPreflightValidator.php';

use OPNsense\SingBox\Runtime\NetworkPreflightValidator;

function failNetworkPreflight(string $message): void
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function assertPreflightValid(array $errors, string $label): void
{
    if ($errors !== []) {
        failNetworkPreflight($label . ': ' . implode(' | ', $errors));
    }
}

function assertPreflightInvalid(array $errors, string $needle, string $label): void
{
    foreach ($errors as $error) {
        if (str_contains($error, $needle)) {
            return;
        }
    }
    failNetworkPreflight($label . ': ожидаемая ошибка не обнаружена.');
}

$plan = [
    'policy_plan' => [
        'required' => true,
        'capture_interfaces' => ['lan'],
        'dns_listener' => ['address' => '127.0.0.1', 'port' => 55353],
        'policy_outbound' => [
            'bind_address' => '192.0.2.70',
            'gateway' => 'VPN_GW',
        ],
        'tun_interface' => 'tun_singbox',
        'tun_address' => '172.19.0.1/30',
        'fakeip_ipv4_range' => '198.18.0.0/15',
    ],
    'selectors' => [
        'policy_dns_address' => '203.0.113.53',
    ],
];

$environment = [
    'interfaces' => [
        'lan' => [
            'device' => 'igc0',
            'enabled' => true,
            'present' => true,
            'up' => true,
        ],
    ],
    'local_ipv4_addresses' => ['127.0.0.1', '192.0.2.1', '192.0.2.70'],
    'local_ipv4_networks' => [
        ['device' => 'lo0', 'cidr' => '127.0.0.1/8'],
        ['device' => 'igc0', 'cidr' => '192.0.2.1/24'],
        ['device' => 'tun_singbox', 'cidr' => '172.19.0.1/30'],
    ],
    'gateways' => [
        'VPN_GW' => [
            'ipprotocol' => 'inet',
            'if' => 'igc1',
            'disabled' => false,
            'defunct' => false,
            'force_down' => false,
        ],
    ],
];

assertPreflightValid(NetworkPreflightValidator::validateStatic($plan), 'Статический preflight корректного плана');
assertPreflightValid(
    NetworkPreflightValidator::validateEnvironment($plan, $environment),
    'Сетевой preflight корректного окружения'
);

$overlapPlan = $plan;
$overlapPlan['policy_plan']['tun_address'] = '198.18.0.1/30';
assertPreflightInvalid(
    NetworkPreflightValidator::validateStatic($overlapPlan),
    'пересекается с сетью TUN',
    'Пересечение FakeIP и TUN'
);

$bindInTunPlan = $plan;
$bindInTunPlan['policy_plan']['policy_outbound']['bind_address'] = '172.19.0.2';
assertPreflightInvalid(
    NetworkPreflightValidator::validateStatic($bindInTunPlan),
    'попадает в сеть TUN',
    'Policy bind внутри TUN'
);

$missingInterfaceEnvironment = $environment;
unset($missingInterfaceEnvironment['interfaces']['lan']);
assertPreflightInvalid(
    NetworkPreflightValidator::validateEnvironment($plan, $missingInterfaceEnvironment),
    'отсутствует в текущей конфигурации',
    'Удалённый интерфейс захвата'
);

$downInterfaceEnvironment = $environment;
$downInterfaceEnvironment['interfaces']['lan']['up'] = false;
assertPreflightInvalid(
    NetworkPreflightValidator::validateEnvironment($plan, $downInterfaceEnvironment),
    'недоступно или не поднято',
    'Остановленный интерфейс захвата'
);

$missingBindEnvironment = $environment;
$missingBindEnvironment['local_ipv4_addresses'] = ['127.0.0.1', '192.0.2.1'];
assertPreflightInvalid(
    NetworkPreflightValidator::validateEnvironment($plan, $missingBindEnvironment),
    'не назначен OPNsense',
    'Неназначенный policy bind'
);

$missingGatewayEnvironment = $environment;
unset($missingGatewayEnvironment['gateways']['VPN_GW']);
assertPreflightInvalid(
    NetworkPreflightValidator::validateEnvironment($plan, $missingGatewayEnvironment),
    'отсутствует в текущей конфигурации',
    'Удалённый gateway'
);

$ipv6GatewayEnvironment = $environment;
$ipv6GatewayEnvironment['gateways']['VPN_GW']['ipprotocol'] = 'inet6';
assertPreflightInvalid(
    NetworkPreflightValidator::validateEnvironment($plan, $ipv6GatewayEnvironment),
    'не относится к IPv4',
    'IPv6 gateway в IPv4 policy-контуре'
);

$networkConflictEnvironment = $environment;
$networkConflictEnvironment['local_ipv4_networks'][] = ['device' => 'vlan20', 'cidr' => '198.18.1.1/24'];
assertPreflightInvalid(
    NetworkPreflightValidator::validateEnvironment($plan, $networkConflictEnvironment),
    'FakeIP',
    'Пересечение FakeIP с локальной сетью'
);

echo "Сетевой preflight адресов, интерфейсов, gateway и конфликтов IPv4 проверен\n";
