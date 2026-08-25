<?php

/*
 * Copyright (C) 2024 https://pfchina.org/
 * All rights reserved.
 */

function sing_box_is_enabled()
{
    $path = '/etc/rc.conf.d/sing_box';
    if (!is_readable($path)) {
        return false;
    }

    $content = file_get_contents($path);
    return is_string($content)
        && preg_match('/^\s*sing_box_enable\s*=\s*"YES"\s*$/m', $content) === 1;
}

function sing_box_policy_is_managed()
{
    return is_file('/var/db/os-sing-box/managed-policy')
        && is_readable('/var/db/os-sing-box/policy-plan.json');
}

function sing_box_policy_is_active()
{
    $planPath = '/var/db/os-sing-box/policy-plan.json';
    $activePath = '/var/run/sing-box-policy-active';

    if (!sing_box_policy_is_managed() || !is_readable($activePath)) {
        return false;
    }

    $activeChecksum = trim((string)file_get_contents($activePath));
    $currentChecksum = hash_file('sha256', $planPath);

    return is_string($currentChecksum)
        && $activeChecksum !== ''
        && hash_equals($currentChecksum, $activeChecksum);
}

function sing_box_load_policy_plan()
{
    $path = '/var/db/os-sing-box/policy-plan.json';
    if (!is_readable($path)) {
        return null;
    }

    $content = file_get_contents($path);
    if (!is_string($content)) {
        return null;
    }

    $plan = json_decode($content, true);
    if (!is_array($plan)) {
        return null;
    }

    try {
        \OPNsense\SingBox\Runtime\PolicyPlanValidator::assertValid($plan);
    } catch (\Throwable $error) {
        syslog(LOG_ERR, 'sing-box: сохранённый policy-план не прошёл валидацию');
        return null;
    }

    return $plan;
}

function sing_box_gateway_available($fw, $gateway)
{
    if (!is_string($gateway) || $gateway === '' || !method_exists($fw, 'getGateway')) {
        return false;
    }

    try {
        $mapping = $fw->getGateway($gateway);
    } catch (\Throwable $error) {
        return false;
    }

    return is_array($mapping) && !empty($mapping['logic']);
}

function sing_box_register_policy_rules($fw, $rules)
{
    foreach ($rules['destination_nat'] ?? [] as $rule) {
        $priority = (int)($rule['#priority'] ?? 2);
        unset($rule['#priority'], $rule['#operation']);
        $fw->registerDestinationNatRule($priority, $rule);
    }

    foreach ($rules['filter'] ?? [] as $rule) {
        $priority = (int)($rule['#priority'] ?? 3);
        unset($rule['#priority'], $rule['#operation']);

        if (!empty($rule['gateway']) && !sing_box_gateway_available($fw, $rule['gateway'])) {
            syslog(
                LOG_WARNING,
                sprintf(
                    'sing-box: gateway %s недоступен; policy route пропущен, fail-closed правило сохранено',
                    $rule['gateway']
                )
            );
            continue;
        }

        $fw->registerFilterRule($priority, $rule);
    }
}

function sing_box_firewall($fw)
{
    if (!sing_box_policy_is_active()) {
        return;
    }

    $plan = sing_box_load_policy_plan();
    if ($plan === null) {
        return;
    }

    try {
        $rules = \OPNsense\SingBox\Runtime\FirewallRuleBuilder::build($plan);
    } catch (\Throwable $error) {
        syslog(LOG_ERR, 'sing-box: не удалось сформировать правила firewall из policy-плана');
        return;
    }

    sing_box_register_policy_rules($fw, $rules);
}

function sing_box_services()
{
    return [
        [
            'description' => gettext('sing-box'),
            'configd' => [
                'restart' => ['sing-box restart'],
                'start' => ['sing-box start'],
                'stop' => ['sing-box stop'],
            ],
            'name' => 'sing-box',
            'pidfile' => '/var/run/sing-box.pid',
            'enabled' => sing_box_is_enabled(),
            'rcfile' => '/usr/local/etc/rc.d/sing-box',
        ],
    ];
}

function sing_box_configure()
{
    return [
        'newwanip' => ['sing_box_newwanip:3'],
    ];
}

function sing_box_newwanip($verbose = false, $interface_map = null, $family = null)
{
    if ($family !== 'inet' || !sing_box_is_enabled()) {
        return;
    }

    if (!is_array($interface_map) || count($interface_map) === 0) {
        return;
    }

    $interfaces = implode(',', $interface_map);
    mwexecfb(
        '/usr/local/sbin/sing-box-readiness --event %s --interfaces %s',
        ['newwanip', $interfaces]
    );
}
