<?php

require_once __DIR__ . '/../src/usr/local/opnsense/mvc/app/models/OPNsense/SingBox/Runtime/PolicyPlanValidator.php';
require_once __DIR__ . '/../src/usr/local/opnsense/mvc/app/models/OPNsense/SingBox/Runtime/PolicyPlanBuilder.php';
require_once __DIR__ . '/../src/usr/local/opnsense/mvc/app/models/OPNsense/SingBox/Runtime/FirewallRuleBuilder.php';
require_once __DIR__ . '/../src/usr/local/etc/inc/plugins.inc.d/sing_box.inc';

use OPNsense\SingBox\Runtime\FirewallRuleBuilder;
use OPNsense\SingBox\Runtime\PolicyPlanBuilder;

function failFirewallRegistration(string $message): void
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function assertRegistrationSame($expected, $actual, string $label): void
{
    if ($expected !== $actual) {
        failFirewallRegistration($label . ': получено неожиданное значение: ' . var_export($actual, true));
    }
}

class FirewallRecorder
{
    public array $destinationNat = [];
    public array $filter = [];
    private string $gatewayMode;

    public function __construct(string $gatewayMode)
    {
        $this->gatewayMode = $gatewayMode;
    }

    public function getGateway(string $gateway): array
    {
        if ($this->gatewayMode === 'error') {
            throw new RuntimeException('Имитирована ошибка проверки gateway.');
        }
        if ($this->gatewayMode === 'missing') {
            return [];
        }
        return ['logic' => $gateway];
    }

    public function registerDestinationNatRule(int $priority, array $rule): void
    {
        $this->destinationNat[] = ['priority' => $priority, 'rule' => $rule];
    }

    public function registerFilterRule(int $priority, array $rule): void
    {
        $this->filter[] = ['priority' => $priority, 'rule' => $rule];
    }
}

final class FirewallRecorderWithoutGatewayApi
{
    public array $destinationNat = [];
    public array $filter = [];

    public function registerDestinationNatRule(int $priority, array $rule): void
    {
        $this->destinationNat[] = ['priority' => $priority, 'rule' => $rule];
    }

    public function registerFilterRule(int $priority, array $rule): void
    {
        $this->filter[] = ['priority' => $priority, 'rule' => $rule];
    }
}

function assertFailClosedOnly($firewall, string $label): void
{
    assertRegistrationSame(2, count($firewall->destinationNat), $label . ': количество DNS redirect');
    assertRegistrationSame(1, count($firewall->filter), $label . ': количество filter правил');

    $registered = $firewall->filter[0] ?? [];
    $rule = $registered['rule'] ?? [];
    assertRegistrationSame(3, $registered['priority'] ?? null, $label . ': приоритет fail-closed');
    assertRegistrationSame('block', $rule['type'] ?? null, $label . ': действие fail-closed');
    assertRegistrationSame('192.0.2.70', $rule['from'] ?? null, $label . ': исходящий адрес fail-closed');
    assertRegistrationSame(false, array_key_exists('gateway', $rule), $label . ': независимость fail-closed от gateway');
}

$plan = PolicyPlanBuilder::build(
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
    'VPN_GW'
);
$rules = FirewallRuleBuilder::build($plan);

$available = new FirewallRecorder('available');
sing_box_register_policy_rules($available, $rules);
assertRegistrationSame(2, count($available->destinationNat), 'Доступный gateway: количество DNS redirect');
assertRegistrationSame(2, count($available->filter), 'Доступный gateway: количество filter правил');
assertRegistrationSame(2, $available->filter[0]['priority'] ?? null, 'Доступный gateway: приоритет policy route');
assertRegistrationSame('pass', $available->filter[0]['rule']['type'] ?? null, 'Доступный gateway: policy route');
assertRegistrationSame('VPN_GW', $available->filter[0]['rule']['gateway'] ?? null, 'Доступный gateway: выбранный gateway');
assertRegistrationSame(3, $available->filter[1]['priority'] ?? null, 'Доступный gateway: приоритет fail-closed');
assertRegistrationSame('block', $available->filter[1]['rule']['type'] ?? null, 'Доступный gateway: fail-closed после policy route');

$missing = new FirewallRecorder('missing');
sing_box_register_policy_rules($missing, $rules);
assertFailClosedOnly($missing, 'Отсутствующий gateway');

$error = new FirewallRecorder('error');
sing_box_register_policy_rules($error, $rules);
assertFailClosedOnly($error, 'Ошибка проверки gateway');

$withoutApi = new FirewallRecorderWithoutGatewayApi();
sing_box_register_policy_rules($withoutApi, $rules);
assertFailClosedOnly($withoutApi, 'Недоступный API gateway');

echo "Регистрация policy route и независимого fail-closed при недоступном gateway проверена\n";
