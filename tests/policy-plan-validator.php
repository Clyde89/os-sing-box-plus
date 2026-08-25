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
    '192.0.2.70',
    'VPN_GW'
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

$invalidRouteSource = $plan;
$invalidRouteSource['operations'][2]['source_address'] = '2001:db8::70';
assertPolicyInvalid($invalidRouteSource, 'IPv6 в исходящем адресе policy route');

$invalidGateway = $plan;
$invalidGateway['operations'][2]['gateway'] = 'VPN GW';
assertPolicyInvalid($invalidGateway, 'Некорректное имя gateway policy route');

$invalidBlockSource = $plan;
$invalidBlockSource['operations'][3]['source_address'] = '2001:db8::70';
assertPolicyInvalid($invalidBlockSource, 'IPv6 в исходящем адресе fail-closed');

$missingBlock = $plan;
array_pop($missingBlock['operations']);
assertPolicyInvalid($missingBlock, 'Policy route без fail-closed');

$missingRoute = $plan;
array_splice($missingRoute['operations'], 2, 1);
assertPolicyInvalid($missingRoute, 'Fail-closed без policy route');

$mismatchedSources = $plan;
$mismatchedSources['operations'][3]['source_address'] = '192.0.2.71';
assertPolicyInvalid($mismatchedSources, 'Разные исходящие адреса policy route и fail-closed');

$reversedPair = $plan;
[$reversedPair['operations'][2], $reversedPair['operations'][3]] = [
    $reversedPair['operations'][3],
    $reversedPair['operations'][2],
];
assertPolicyInvalid($reversedPair, 'Fail-closed перед policy route');

$disabledFailClosed = $plan;
$disabledFailClosed['policy_outbound']['fail_closed'] = false;
assertPolicyInvalid($disabledFailClosed, 'Отключённый fail-closed');

$mismatchedBindAddress = $plan;
$mismatchedBindAddress['policy_outbound']['bind_address'] = '192.0.2.71';
assertPolicyInvalid($mismatchedBindAddress, 'Bind address не согласован с policy route');

$mismatchedGateway = $plan;
$mismatchedGateway['policy_outbound']['gateway'] = 'OTHER_GW';
assertPolicyInvalid($mismatchedGateway, 'Gateway не согласован с policy route');

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
    '192.0.2.70',
    'VPN_GW'
);
assertPolicyValid($allLanPlan, 'Корректный all_lan policy-план без source selector');

$allLanWithoutConfirmation = $allLanPlan;
$allLanWithoutConfirmation['confirmation_required'] = false;
assertPolicyInvalid($allLanWithoutConfirmation, 'All_lan без обязательного подтверждения');

$allLanWithSource = $allLanPlan;
$allLanWithSource['operations'][0]['source_ip_cidr'] = ['192.0.2.10/32'];
assertPolicyInvalid($allLanWithSource, 'All_lan DNS redirect с source selector');

if (!preg_match('/^[a-f0-9]{64}$/', $checksumA)) {
    failPolicyValidation('Policy checksum должен быть SHA-256 в hex-формате.');
}

echo "Fail-closed валидация и SHA-256 policy-плана проверены\n";
