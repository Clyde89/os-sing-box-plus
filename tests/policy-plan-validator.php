<?php

require_once __DIR__ . '/../src/usr/local/opnsense/mvc/app/models/OPNsense/SingBox/Runtime/PolicyPlanBuilder.php';

use OPNsense\SingBox\Runtime\PolicyPlanBuilder;
use OPNsense\SingBox\Runtime\PolicyPlanValidator;

function failPolicyValidation(string $message): void
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function assertPolicyValid(array $plan, string $label): void
{
    $errors = PolicyPlanValidator::validate($plan);
    if ($errors !== []) {
        failPolicyValidation($label . ': ожидался корректный план, получено: ' . implode(' | ', $errors));
    }
}

function assertPolicyInvalid(array $plan, string $label): void
{
    if (PolicyPlanValidator::validate($plan) === []) {
        failPolicyValidation($label . ': ожидалась ошибка валидации policy-плана.');
    }
}

$plan = PolicyPlanBuilder::build(
    'selected',
    ['lan'],
    ['192.0.2.10/32'],
    ['domain' => ['example.org'], 'domain_suffix' => []],
    '192.0.2.1',
    55353,
    '198.18.0.0/15',
    'tun_singbox',
    '172.19.0.1/30',
    'direct_bind',
    '192.0.2.70'
);

assertPolicyValid($plan, 'Корректный selected policy-план');

$checksumA = PolicyPlanValidator::checksum($plan);
$reordered = $plan;
$reordered = array_reverse($reordered, true);
$checksumB = PolicyPlanValidator::checksum($reordered);
if ($checksumA !== $checksumB) {
    failPolicyValidation('SHA-256 policy-плана должна быть стабильной при другом порядке ключей объекта.');
}

$wrongOwner = $plan;
$wrongOwner['managed_by'] = 'other';
assertPolicyInvalid($wrongOwner, 'Чужой владелец policy-плана');

$wanPlan = $plan;
$wanPlan['capture_interfaces'] = ['wan'];
assertPolicyInvalid($wanPlan, 'WAN в интерфейсах захвата');

$wanOperation = $plan;
$wanOperation['operations'][0]['interface'] = 'wan';
assertPolicyInvalid($wanOperation, 'WAN в DNS redirect операции');

$wrongPort = $plan;
$wrongPort['operations'][0]['destination_port'] = 853;
assertPolicyInvalid($wrongPort, 'Перехват порта, отличного от DNS/53');

$missingSource = $plan;
$missingSource['operations'][0]['source_ip_cidr'] = [];
assertPolicyInvalid($missingSource, 'Selected DNS redirect без source selector');

$unknownOperation = $plan;
$unknownOperation['operations'][0]['type'] = 'unknown';
assertPolicyInvalid($unknownOperation, 'Неизвестный тип операции');

$duplicateId = $plan;
$duplicateId['operations'][1]['id'] = $duplicateId['operations'][0]['id'];
assertPolicyInvalid($duplicateId, 'Повторяющийся ID операции');

$invalidRoute = $plan;
$invalidRoute['operations'][2]['network'] = '2001:db8::/64';
assertPolicyInvalid($invalidRoute, 'IPv6 в текущем IPv4 FakeIP route');

$allLanPlan = PolicyPlanBuilder::build(
    'all_lan',
    ['lan'],
    [],
    ['domain' => ['example.org'], 'domain_suffix' => []],
    '192.0.2.1',
    55353,
    '198.18.0.0/15',
    'tun_singbox',
    '172.19.0.1/30',
    'direct_bind',
    '192.0.2.70'
);
assertPolicyValid($allLanPlan, 'Корректный all_lan policy-план без source selector');

if (!preg_match('/^[a-f0-9]{64}$/', $checksumA)) {
    failPolicyValidation('Policy checksum должен быть SHA-256 в hex-формате.');
}

echo "Fail-closed валидация и SHA-256 policy-плана проверены\n";
