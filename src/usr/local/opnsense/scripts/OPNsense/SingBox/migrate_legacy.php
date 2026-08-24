#!/usr/local/bin/php
<?php

require_once('config.inc');

const LEGACY_TUN_DEVICE = 'tun_singbox';
const LEGACY_RULE_UUID = '762b3ec8-79c2-48b4-9793-c653bb3d2265';

function fail($message, $code)
{
    fwrite(STDERR, $message . PHP_EOL);
    exit($code);
}

if ($argc !== 2) {
    fail('Не указан снимок конфигурации OPNsense для миграции.', 64);
}

$snapshotFile = $argv[1];
if (!is_readable($snapshotFile)) {
    exit(0);
}

$snapshot = load_config_from_file($snapshotFile);
if (!is_array($snapshot)) {
    fail('Не удалось прочитать снимок конфигурации OPNsense.', 65);
}

$legacyInterfaceKey = null;
$legacyInterface = null;
foreach (($snapshot['interfaces'] ?? []) as $interfaceKey => $interface) {
    if (is_array($interface) && ($interface['if'] ?? '') === LEGACY_TUN_DEVICE) {
        $legacyInterfaceKey = $interfaceKey;
        $legacyInterface = $interface;
        break;
    }
}

$legacyRule = null;
foreach (($snapshot['filter']['rule'] ?? []) as $rule) {
    if (!is_array($rule)) {
        continue;
    }

    if (($rule['@attributes']['uuid'] ?? '') === LEGACY_RULE_UUID) {
        $legacyRule = $rule;
        break;
    }
}

if ($legacyInterface === null && $legacyRule === null) {
    exit(0);
}

$interfaces = &config_read_array('interfaces');
$currentInterfaceKey = null;
foreach ($interfaces as $interfaceKey => $interface) {
    if (is_array($interface) && ($interface['if'] ?? '') === LEGACY_TUN_DEVICE) {
        $currentInterfaceKey = $interfaceKey;
        break;
    }
}

$changed = false;

if ($legacyInterface !== null && $currentInterfaceKey === null) {
    if (isset($interfaces[$legacyInterfaceKey]) && !empty($interfaces[$legacyInterfaceKey])) {
        fail(sprintf('Интерфейс %s уже занят; восстановление legacy-интерфейса sing-box остановлено.', $legacyInterfaceKey), 66);
    }

    $interfaces[$legacyInterfaceKey] = $legacyInterface;
    $currentInterfaceKey = $legacyInterfaceKey;
    $changed = true;
    echo sprintf('Восстановлен интерфейс %s для %s.', $legacyInterfaceKey, LEGACY_TUN_DEVICE) . PHP_EOL;
}

$rules = &config_read_array('filter', 'rule');
$currentRuleExists = false;
foreach ($rules as $rule) {
    if (is_array($rule) && ($rule['@attributes']['uuid'] ?? '') === LEGACY_RULE_UUID) {
        $currentRuleExists = true;
        break;
    }
}

if ($legacyRule !== null && !$currentRuleExists) {
    if ($legacyInterfaceKey !== null && $currentInterfaceKey !== null && $legacyInterfaceKey !== $currentInterfaceKey) {
        if (($legacyRule['interface'] ?? '') === $legacyInterfaceKey) {
            $legacyRule['interface'] = $currentInterfaceKey;
        }
        if (($legacyRule['source']['network'] ?? '') === $legacyInterfaceKey) {
            $legacyRule['source']['network'] = $currentInterfaceKey;
        }
    }

    $rules[] = $legacyRule;
    $changed = true;
    echo 'Восстановлено legacy-правило firewall для sing-box.' . PHP_EOL;
}

if (!$changed) {
    exit(0);
}

$result = write_config('Восстановлены объекты sing-box после обновления плагина');
if ($result === false || $result === -1) {
    fail('OPNsense не сохранил восстановленные объекты sing-box.', 67);
}

exit(0);
