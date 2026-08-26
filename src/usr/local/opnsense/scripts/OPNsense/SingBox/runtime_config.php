#!/usr/local/bin/php
<?php

require_once('config.inc');
require_once('/usr/local/opnsense/mvc/app/models/OPNsense/SingBox/Settings.php');
require_once('/usr/local/opnsense/mvc/app/models/OPNsense/SingBox/Validation/SelectionValidator.php');
require_once('/usr/local/opnsense/mvc/app/models/OPNsense/SingBox/Runtime/SelectorCompiler.php');
require_once('/usr/local/opnsense/mvc/app/models/OPNsense/SingBox/Runtime/PolicyPlanValidator.php');
require_once('/usr/local/opnsense/mvc/app/models/OPNsense/SingBox/Runtime/PolicyPlanBuilder.php');
require_once('/usr/local/opnsense/mvc/app/models/OPNsense/SingBox/Runtime/RuntimeConfigBuilder.php');

use OPNsense\SingBox\Runtime\PolicyPlanValidator;
use OPNsense\SingBox\Runtime\RuntimeConfigBuilder;
use OPNsense\SingBox\Settings;

const TARGET_CONFIG = '/usr/local/etc/sing-box/config.json';
const SING_BOX_BINARY = '/usr/local/bin/sing-box';
const SERVICE_BINARY = '/usr/sbin/service';
const PID_FILE = '/var/run/sing-box.pid';
const POLICY_ACTIVE_FILE = '/var/run/sing-box-policy-active';
const STATE_DIRECTORY = '/var/db/os-sing-box';
const APPLY_LOCK_FILE = STATE_DIRECTORY . '/apply.lock';
const SETUP_REQUIRED_FILE = STATE_DIRECTORY . '/setup-required';
const MANAGED_CONFIG_FILE = STATE_DIRECTORY . '/managed-config';
const ADOPTION_APPROVAL_FILE = STATE_DIRECTORY . '/adoption-approved';
const UNMANAGED_ORIGINAL_FILE = STATE_DIRECTORY . '/unmanaged-config.original.json';
const MANAGED_POLICY_FILE = STATE_DIRECTORY . '/managed-policy';
const POLICY_PLAN_FILE = STATE_DIRECTORY . '/policy-plan.json';
const POLICY_RELOAD_PENDING_FILE = STATE_DIRECTORY . '/filter-reload.pending';
const TUN_INTERFACE_FILE = STATE_DIRECTORY . '/tun-interface';

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

function ensureStateDirectory(): void
{
    if (!is_dir(STATE_DIRECTORY) && !@mkdir(STATE_DIRECTORY, 0700, true)) {
        raiseRuntime('Не удалось создать каталог состояния управляемой конфигурации.', 73);
    }
    if (!@chmod(STATE_DIRECTORY, 0700)) {
        raiseRuntime('Не удалось подтвердить безопасные права каталога состояния.', 73);
    }
}

function acquireApplyLock()
{
    ensureStateDirectory();
    $handle = @fopen(APPLY_LOCK_FILE, 'c');
    if ($handle === false) {
        raiseRuntime('Не удалось открыть блокировку применения runtime-конфигурации.', 73);
    }
    if (!@chmod(APPLY_LOCK_FILE, 0600)) {
        @fclose($handle);
        raiseRuntime('Не удалось установить безопасные права блокировки применения.', 73);
    }
    if (!@flock($handle, LOCK_EX | LOCK_NB)) {
        @fclose($handle);
        raiseRuntime('Другая операция применения runtime-конфигурации уже выполняется.', 75);
    }
    return $handle;
}

function releaseApplyLock($handle): void
{
    if (is_resource($handle)) {
        @flock($handle, LOCK_UN);
        @fclose($handle);
    }
}

function writeStateFile(string $path, string $content): void
{
    ensureStateDirectory();
    $temporary = tempnam(STATE_DIRECTORY, '.singbox_state_');
    if ($temporary === false) {
        raiseRuntime('Не удалось создать временный файл состояния.', 73);
    }

    try {
        if (file_put_contents($temporary, $content, LOCK_EX) === false) {
            raiseRuntime('Не удалось записать файл состояния.', 74);
        }
        if (!@chmod($temporary, 0600)) {
            raiseRuntime('Не удалось установить безопасные права файла состояния.', 74);
        }
        if (!@rename($temporary, $path)) {
            raiseRuntime('Не удалось атомарно заменить файл состояния.', 74);
        }
        $temporary = '';
    } finally {
        if ($temporary !== '') {
            @unlink($temporary);
        }
    }
}

function snapshotStateFile(string $path): array
{
    if (!is_file($path)) {
        return ['exists' => false, 'content' => ''];
    }

    $content = file_get_contents($path);
    if (!is_string($content)) {
        raiseRuntime('Не удалось прочитать существующий файл состояния перед применением.', 74);
    }

    return ['exists' => true, 'content' => $content];
}

function restoreStateFile(string $path, array $snapshot): void
{
    if (($snapshot['exists'] ?? false) === true) {
        writeStateFile($path, (string)($snapshot['content'] ?? ''));
    } elseif (is_file($path) && !@unlink($path)) {
        throw new \RuntimeException('Не удалось удалить новый файл managed-состояния при откате.');
    }
}

function capturePolicyState(): array
{
    return [
        SETUP_REQUIRED_FILE => snapshotStateFile(SETUP_REQUIRED_FILE),
        MANAGED_CONFIG_FILE => snapshotStateFile(MANAGED_CONFIG_FILE),
        ADOPTION_APPROVAL_FILE => snapshotStateFile(ADOPTION_APPROVAL_FILE),
        POLICY_PLAN_FILE => snapshotStateFile(POLICY_PLAN_FILE),
        MANAGED_POLICY_FILE => snapshotStateFile(MANAGED_POLICY_FILE),
        POLICY_RELOAD_PENDING_FILE => snapshotStateFile(POLICY_RELOAD_PENDING_FILE),
        TUN_INTERFACE_FILE => snapshotStateFile(TUN_INTERFACE_FILE),
        POLICY_ACTIVE_FILE => snapshotStateFile(POLICY_ACTIVE_FILE),
    ];
}

function currentConfigSha256(): string
{
    if (!is_readable(TARGET_CONFIG)) {
        raiseRuntime('Существующая runtime-конфигурация недоступна для проверки перехода.', 65);
    }

    $checksum = hash_file('sha256', TARGET_CONFIG);
    if (!is_string($checksum) || preg_match('/^[a-f0-9]{64}$/', $checksum) !== 1) {
        raiseRuntime('Не удалось вычислить SHA-256 существующей runtime-конфигурации.', 74);
    }
    return $checksum;
}

function adoptionApprovalMatchesCurrentConfig(): bool
{
    if (!is_file(ADOPTION_APPROVAL_FILE) || !is_readable(ADOPTION_APPROVAL_FILE) || !is_file(TARGET_CONFIG)) {
        return false;
    }

    $approvedChecksum = trim((string)file_get_contents(ADOPTION_APPROVAL_FILE));
    if (preg_match('/^[a-f0-9]{64}$/', $approvedChecksum) !== 1) {
        return false;
    }

    return hash_equals($approvedChecksum, currentConfigSha256());
}

function approveManagedAdoption(): string
{
    if (is_file(SETUP_REQUIRED_FILE)) {
        raiseRuntime('Первоначальная настройка уже допускает безопасное применение без перехода.', 65);
    }
    if (is_file(MANAGED_CONFIG_FILE)) {
        raiseRuntime('Runtime-конфигурация уже управляется структурированными настройками.', 65);
    }
    if (!is_file(TARGET_CONFIG)) {
        raiseRuntime('Существующая runtime-конфигурация для перехода не найдена.', 65);
    }

    $content = file_get_contents(TARGET_CONFIG);
    if (!is_string($content)) {
        raiseRuntime('Не удалось прочитать существующую runtime-конфигурацию для резервного копирования.', 74);
    }

    $checksum = hash('sha256', $content);
    if (!hash_equals($checksum, currentConfigSha256())) {
        raiseRuntime('Runtime-конфигурация изменилась во время подтверждения перехода.', 75);
    }

    if (!is_file(UNMANAGED_ORIGINAL_FILE)) {
        writeStateFile(UNMANAGED_ORIGINAL_FILE, $content);
    } elseif (!is_readable(UNMANAGED_ORIGINAL_FILE)) {
        raiseRuntime('Исходная резервная копия unmanaged-конфигурации недоступна.', 74);
    }
    if (!@chmod(UNMANAGED_ORIGINAL_FILE, 0400)) {
        raiseRuntime('Не удалось защитить исходную резервную копию unmanaged-конфигурации.', 74);
    }

    if (!hash_equals($checksum, currentConfigSha256())) {
        raiseRuntime('Runtime-конфигурация изменилась до записи разрешения перехода.', 75);
    }
    writeStateFile(ADOPTION_APPROVAL_FILE, $checksum . PHP_EOL);
    return $checksum;
}

function restorePolicyState(array $snapshot): void
{
    foreach ($snapshot as $path => $fileSnapshot) {
        restoreStateFile($path, $fileSnapshot);
    }
}

function applyPolicyState(array $runtimePlan): string
{
    $policyPlan = $runtimePlan['policy_plan'] ?? null;
    if (!is_array($policyPlan)) {
        raiseRuntime('Runtime-план не содержит декларативный policy-план.', 65);
    }

    PolicyPlanValidator::assertValid($policyPlan);
    $tunInterface = (string)($policyPlan['tun_interface'] ?? '');
    if ($tunInterface === '' || preg_match('/^[A-Za-z0-9_.-]{1,15}$/', $tunInterface) !== 1) {
        raiseRuntime('Policy-план содержит некорректное имя TUN-интерфейса.', 65);
    }

    writeStateFile(TUN_INTERFACE_FILE, $tunInterface . PHP_EOL);

    if (is_file(POLICY_ACTIVE_FILE) && !@unlink(POLICY_ACTIVE_FILE)) {
        raiseRuntime('Не удалось сбросить прежний маркер активного policy-плана.', 74);
    }

    if (($policyPlan['required'] ?? false) === true) {
        if (($policyPlan['ready'] ?? false) !== true || ($policyPlan['confirmation_required'] ?? false) === true) {
            raiseRuntime('Policy-план не готов к безопасной активации.', 65);
        }

        $json = json_encode(
            $policyPlan,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        if ($json === false) {
            raiseRuntime('Не удалось сериализовать policy-план для OPNsense.', 70);
        }
        $json .= PHP_EOL;

        writeStateFile(POLICY_PLAN_FILE, $json);
        writeStateFile(MANAGED_POLICY_FILE, "managed\n");
        writeStateFile(POLICY_RELOAD_PENDING_FILE, "pending\n");
        return hash('sha256', $json);
    }

    if (is_file(POLICY_PLAN_FILE) && !@unlink(POLICY_PLAN_FILE)) {
        raiseRuntime('Не удалось удалить прежний policy-план.', 74);
    }
    if (is_file(MANAGED_POLICY_FILE) && !@unlink(MANAGED_POLICY_FILE)) {
        raiseRuntime('Не удалось отключить управляемое policy-состояние.', 74);
    }
    writeStateFile(POLICY_RELOAD_PENDING_FILE, "pending\n");
    return hash('sha256', '');
}

function restorePreviousConfig(string $backupFile, bool $hadPreviousConfig): void
{
    if ($hadPreviousConfig && is_file($backupFile)) {
        if (!@copy($backupFile, TARGET_CONFIG) || !@chmod(TARGET_CONFIG, 0600)) {
            throw new \RuntimeException('Не удалось восстановить предыдущую runtime-конфигурацию.');
        }
        return;
    }

    if (is_file(TARGET_CONFIG) && !@unlink(TARGET_CONFIG)) {
        throw new \RuntimeException('Не удалось удалить новую runtime-конфигурацию при откате.');
    }
}

function runServiceCommand(string $action): array
{
    if (!is_executable(SERVICE_BINARY)) {
        raiseRuntime('Системная команда service отсутствует или недоступна.', 69);
    }

    $command = escapeshellarg(SERVICE_BINARY) . ' sing-box ' . escapeshellarg($action) . ' 2>&1';
    $output = [];
    $status = 0;
    exec($command, $output, $status);

    return [$status, trim(implode("\n", $output))];
}

function pidFileProcessIsAlive(): bool
{
    if (!is_readable(PID_FILE)) {
        return false;
    }

    $pid = trim((string)file_get_contents(PID_FILE));
    if ($pid === '' || preg_match('/^[0-9]+$/', $pid) !== 1) {
        return false;
    }

    $output = [];
    $status = 0;
    exec('/bin/kill -0 ' . escapeshellarg($pid) . ' 2>/dev/null', $output, $status);
    return $status === 0;
}

function serviceWasRunning(): bool
{
    [$status] = runServiceCommand('status');
    if ($status === 0) {
        return true;
    }
    if (pidFileProcessIsAlive()) {
        return true;
    }
    if ($status === 1) {
        return false;
    }

    raiseRuntime('Не удалось определить исходное состояние службы sing-box.', 69);
}

function restartService(): void
{
    [$status, $details] = runServiceCommand('restart');
    if ($status !== 0) {
        raiseRuntime(
            'Не удалось перезапустить sing-box после применения runtime-конфигурации.' .
            ($details !== '' ? ' ' . $details : ''),
            69
        );
    }
}

function assertPolicyActivation(array $runtimePlan): void
{
    $policyPlan = $runtimePlan['policy_plan'] ?? null;
    if (!is_array($policyPlan)) {
        raiseRuntime('Runtime-план не содержит policy-план для проверки активации.', 65);
    }

    if (($policyPlan['required'] ?? false) !== true) {
        if (is_file(POLICY_ACTIVE_FILE)) {
            raiseRuntime('После перезапуска сохранился неожиданный активный policy-план.', 69);
        }
        return;
    }

    if (!is_readable(POLICY_PLAN_FILE) || !is_readable(POLICY_ACTIVE_FILE)) {
        raiseRuntime('Управляемый policy-план не был активирован после перезапуска sing-box.', 69);
    }

    $expected = hash_file('sha256', POLICY_PLAN_FILE);
    $actual = trim((string)file_get_contents(POLICY_ACTIVE_FILE));
    if (!is_string($expected) || $expected === '' || $actual === '' || !hash_equals($expected, $actual)) {
        raiseRuntime('Контрольная сумма активного policy-плана не совпала с применённым состоянием.', 69);
    }
}

function rollbackAppliedRuntime(
    string $backupFile,
    bool $hadPreviousConfig,
    array $policySnapshot,
    bool $restartPreviousService
): array {
    $errors = [];

    try {
        restorePreviousConfig($backupFile, $hadPreviousConfig);
    } catch (\Throwable $error) {
        $errors[] = $error->getMessage();
    }

    try {
        restorePolicyState($policySnapshot);
    } catch (\Throwable $error) {
        $errors[] = 'Не удалось восстановить предыдущее managed-состояние.';
    }

    if ($restartPreviousService && $errors === []) {
        try {
            restartService();
        } catch (\Throwable $error) {
            $errors[] = 'Не удалось повторно запустить sing-box с восстановленной конфигурацией.';
        }
    } elseif ($restartPreviousService) {
        $errors[] = 'Восстановительный перезапуск пропущен из-за неполного отката файлов.';
    }

    return $errors;
}

function ensureManagedState(): bool
{
    if (is_file(MANAGED_CONFIG_FILE)) {
        return false;
    }

    writeStateFile(MANAGED_CONFIG_FILE, "managed\n");
    return true;
}

$tempFile = '';
$policySnapshot = [];
$transactionStarted = false;
$lockHandle = null;
$hadPreviousConfig = false;
$serviceRunningBeforeApply = false;
$backupFile = TARGET_CONFIG . '.bak';

try {
    if ($argc !== 2 || !in_array($argv[1], ['apply', 'approve-adoption'], true)) {
        raiseRuntime('Поддерживаются только действия apply и approve-adoption.', 64);
    }

    $lockHandle = acquireApplyLock();
    if ($argv[1] === 'approve-adoption') {
        $approvedChecksum = approveManagedAdoption();
        releaseApplyLock($lockHandle);
        $lockHandle = null;
        echo 'OK Переход в управляемый режим подтверждён; исходная runtime-конфигурация сохранена.'
            . ' SHA256=' . $approvedChecksum . PHP_EOL;
        exit(0);
    }

    $hadPreviousConfig = is_file(TARGET_CONFIG);
    $initialSetup = is_file(SETUP_REQUIRED_FILE);
    $managedConfig = is_file(MANAGED_CONFIG_FILE);
    $adoptionApproved = adoptionApprovalMatchesCurrentConfig();

    if ($hadPreviousConfig && !$initialSetup && !$managedConfig && !$adoptionApproved) {
        raiseRuntime(
            'Обнаружена существующая пользовательская конфигурация, не управляемая MVC. Применение заблокировано до подтверждения перехода для её текущей SHA-256.',
            65
        );
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
    $serviceRunningBeforeApply = serviceWasRunning();

    if ($adoptionApproved && !adoptionApprovalMatchesCurrentConfig()) {
        raiseRuntime('Runtime-конфигурация изменилась после подтверждения перехода.', 75);
    }

    if ($hadPreviousConfig) {
        if (!@copy(TARGET_CONFIG, $backupFile)) {
            raiseRuntime('Не удалось создать резервную копию текущей runtime-конфигурации.', 74);
        }
        if (!@chmod($backupFile, 0600)) {
            @unlink($backupFile);
            raiseRuntime('Не удалось установить безопасные права резервной копии runtime-конфигурации.', 74);
        }
    }

    $policySnapshot = capturePolicyState();
    $transactionStarted = true;

    if (!@rename($tempFile, TARGET_CONFIG)) {
        raiseRuntime('Не удалось атомарно заменить runtime-конфигурацию.', 74);
    }
    $tempFile = '';

    if (!@chmod(TARGET_CONFIG, 0600)) {
        raiseRuntime('Не удалось подтвердить безопасные права runtime-конфигурации.', 74);
    }

    $policySha256 = applyPolicyState($plan);
    ensureManagedState();

    if (is_file(ADOPTION_APPROVAL_FILE) && !@unlink(ADOPTION_APPROVAL_FILE)) {
        raiseRuntime('Не удалось завершить переход в управляемый режим.', 74);
    }

    if ($initialSetup && !@unlink(SETUP_REQUIRED_FILE)) {
        raiseRuntime('Не удалось завершить первоначальную настройку.', 74);
    }

    if ($serviceRunningBeforeApply) {
        restartService();
        assertPolicyActivation($plan);
    }

    $transactionStarted = false;
    releaseApplyLock($lockHandle);
    $lockHandle = null;
    $activationMessage = $serviceRunningBeforeApply
        ? ' Служба sing-box перезапущена, policy-состояние подтверждено.'
        : ' Служба sing-box оставалась остановленной; активация отложена до следующего запуска.';
    echo 'OK Runtime-конфигурация sing-box применена.' . $activationMessage
        . ' SHA256=' . hash('sha256', $config)
        . ' POLICY_SHA256=' . $policySha256
        . PHP_EOL;
    exit(0);
} catch (RuntimeApplyException $error) {
    if ($tempFile !== '') {
        @unlink($tempFile);
    }
    $rollbackErrors = [];
    if ($transactionStarted) {
        $rollbackErrors = rollbackAppliedRuntime(
            $backupFile,
            $hadPreviousConfig,
            $policySnapshot,
            $serviceRunningBeforeApply
        );
        $transactionStarted = false;
    }
    releaseApplyLock($lockHandle);
    $rollbackMessage = $rollbackErrors !== []
        ? ' Откат завершился неполностью: ' . implode(' ', $rollbackErrors)
        : '';
    fwrite(STDERR, 'ERROR ' . $error->getMessage() . $rollbackMessage . PHP_EOL);
    $code = $error->getCode();
    exit($code > 0 ? $code : 70);
} catch (Throwable $error) {
    if ($tempFile !== '') {
        @unlink($tempFile);
    }
    $rollbackErrors = [];
    if ($transactionStarted) {
        $rollbackErrors = rollbackAppliedRuntime(
            $backupFile,
            $hadPreviousConfig,
            $policySnapshot,
            $serviceRunningBeforeApply
        );
        $transactionStarted = false;
    }
    releaseApplyLock($lockHandle);
    $rollbackMessage = $rollbackErrors !== []
        ? ' Откат завершился неполностью: ' . implode(' ', $rollbackErrors)
        : '';
    fwrite(STDERR, 'ERROR Неожиданная ошибка применения runtime-конфигурации.' . $rollbackMessage . PHP_EOL);
    exit(70);
}
