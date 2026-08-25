#!/usr/local/bin/php
<?php

require_once('config.inc');
require_once('/usr/local/opnsense/mvc/app/models/OPNsense/SingBox/Settings.php');
require_once('/usr/local/opnsense/mvc/app/models/OPNsense/SingBox/Runtime/RuntimeConfigBuilder.php');

use OPNsense\SingBox\Runtime\RuntimeConfigBuilder;
use OPNsense\SingBox\Settings;

const TARGET_CONFIG = '/usr/local/etc/sing-box/config.json';
const SING_BOX_BINARY = '/usr/local/bin/sing-box';
const SETUP_REQUIRED_FILE = '/var/db/os-sing-box/setup-required';

final class RuntimeApplyException extends RuntimeException
{
}

function raiseRuntime(string $message, int $code): void
{
    throw new RuntimeApplyException($message, $code);
}

function validateRuntimeConfig(string $path): void
{
    if (!is_executable(SING_BOX_BINARY)) {
        raiseRuntime('Исполняемый файл sing-box отсутствует или недоступен.', 69);
    }

    $command = escapeshellarg(SING_BOX_BINARY) . ' check -c ' . escapeshellarg($path) . ' 2>&1';
    $output = [];
    $status = 0;
    exec($command, $output, $status);

    if ($status !== 0) {
        $details = trim(implode("\n", $output));
        raiseRuntime(
            'Сформированная runtime-конфигурация не прошла проверку sing-box.' .
            ($details !== '' ? ' ' . $details : ''),
            65
        );
    }
}

function restorePreviousConfig(string $backupFile, bool $hadPreviousConfig): void
{
    if ($hadPreviousConfig && is_file($backupFile)) {
        @copy($backupFile, TARGET_CONFIG);
        @chmod(TARGET_CONFIG, 0600);
        return;
    }

    @unlink(TARGET_CONFIG);
}

$tempFile = '';

try {
    if ($argc !== 2 || $argv[1] !== 'apply') {
        raiseRuntime('Поддерживается только действие apply.', 64);
    }

    $model = new Settings();
    $plan = RuntimeConfigBuilder::build($model->getNodes());

    if (($plan['apply_ready'] ?? false) !== true) {
        $details = implode(' ', $plan['warnings'] ?? []);
        raiseRuntime(
            'Runtime-конфигурация не готова к применению.' . ($details !== '' ? ' ' . $details : ''),
            65
        );
    }

    $config = RuntimeConfigBuilder::encodeConfig($plan);
    $directory = dirname(TARGET_CONFIG);
    $backupFile = TARGET_CONFIG . '.bak';
    $hadPreviousConfig = is_file(TARGET_CONFIG);

    if (!is_dir($directory) || !is_writable($directory)) {
        raiseRuntime('Каталог runtime-конфигурации недоступен для записи.', 73);
    }

    if (!@chmod($directory, 0700)) {
        raiseRuntime('Не удалось подтвердить безопасные права каталога runtime-конфигурации.', 73);
    }

    $tempFile = tempnam($directory, '.singbox_runtime_');
    if ($tempFile === false) {
        $tempFile = '';
        raiseRuntime('Не удалось создать временный файл runtime-конфигурации.', 73);
    }

    if (file_put_contents($tempFile, $config, LOCK_EX) === false) {
        raiseRuntime('Не удалось записать временную runtime-конфигурацию.', 74);
    }

    if (!@chmod($tempFile, 0600)) {
        raiseRuntime('Не удалось установить безопасные права временной runtime-конфигурации.', 74);
    }

    validateRuntimeConfig($tempFile);

    if ($hadPreviousConfig) {
        if (!@copy(TARGET_CONFIG, $backupFile)) {
            raiseRuntime('Не удалось создать резервную копию текущей runtime-конфигурации.', 74);
        }
        if (!@chmod($backupFile, 0600)) {
            @unlink($backupFile);
            raiseRuntime('Не удалось установить безопасные права резервной копии runtime-конфигурации.', 74);
        }
    }

    if (!@rename($tempFile, TARGET_CONFIG)) {
        raiseRuntime('Не удалось атомарно заменить runtime-конфигурацию.', 74);
    }
    $tempFile = '';

    if (!@chmod(TARGET_CONFIG, 0600)) {
        restorePreviousConfig($backupFile, $hadPreviousConfig);
        raiseRuntime('Не удалось подтвердить безопасные права runtime-конфигурации; предыдущее состояние восстановлено.', 74);
    }

    if (is_file(SETUP_REQUIRED_FILE) && !@unlink(SETUP_REQUIRED_FILE)) {
        restorePreviousConfig($backupFile, $hadPreviousConfig);
        raiseRuntime('Не удалось завершить первоначальную настройку; предыдущее состояние восстановлено.', 74);
    }

    echo 'OK Runtime-конфигурация sing-box применена. SHA256=' . hash('sha256', $config) . PHP_EOL;
    exit(0);
} catch (RuntimeApplyException $error) {
    if ($tempFile !== '') {
        @unlink($tempFile);
    }
    fwrite(STDERR, 'ERROR ' . $error->getMessage() . PHP_EOL);
    $code = $error->getCode();
    exit($code > 0 ? $code : 70);
} catch (Throwable $error) {
    if ($tempFile !== '') {
        @unlink($tempFile);
    }
    fwrite(STDERR, 'ERROR Неожиданная ошибка применения runtime-конфигурации.' . PHP_EOL);
    exit(70);
}
