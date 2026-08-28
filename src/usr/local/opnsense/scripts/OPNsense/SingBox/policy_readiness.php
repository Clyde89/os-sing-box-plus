#!/usr/local/bin/php
<?php

require_once('/usr/local/opnsense/mvc/app/models/OPNsense/SingBox/Runtime/PolicyPlanValidator.php');

use OPNsense\SingBox\Runtime\PolicyPlanValidator;

const SOCKSTAT_BINARY = '/usr/bin/sockstat';

final class PolicyReadinessException extends RuntimeException
{
}

function failReadiness(string $message, int $code): void
{
    throw new PolicyReadinessException($message, $code);
}

function parseArguments(array $arguments): array
{
    $planPath = '';
    $pid = '';

    for ($index = 1; $index < count($arguments); $index++) {
        $argument = $arguments[$index];
        if ($argument === '--plan' && isset($arguments[$index + 1])) {
            $planPath = (string)$arguments[++$index];
        } elseif ($argument === '--pid' && isset($arguments[$index + 1])) {
            $pid = (string)$arguments[++$index];
        } else {
            failReadiness('Получены неподдерживаемые аргументы проверки readiness.', 64);
        }
    }

    if ($planPath === '' || $pid === '' || preg_match('/^[1-9][0-9]*$/', $pid) !== 1) {
        failReadiness('Для проверки readiness требуются policy-план и корректный PID sing-box.', 64);
    }

    return [$planPath, $pid];
}

function loadPolicyPlan(string $path): array
{
    if (!is_readable($path)) {
        failReadiness('Policy-план недоступен для проверки readiness.', 65);
    }

    $content = file_get_contents($path);
    if (!is_string($content)) {
        failReadiness('Не удалось прочитать policy-план для проверки readiness.', 65);
    }

    $plan = json_decode($content, true);
    if (!is_array($plan)) {
        failReadiness('Policy-план содержит некорректный JSON.', 65);
    }

    try {
        PolicyPlanValidator::assertValid($plan);
    } catch (Throwable $error) {
        failReadiness('Policy-план не прошёл проверку перед firewall-активацией.', 65);
    }

    if (($plan['required'] ?? false) === true
        && (($plan['ready'] ?? false) !== true || ($plan['confirmation_required'] ?? false) === true)
    ) {
        failReadiness('Policy-план не готов к firewall-активации.', 65);
    }

    return $plan;
}

function expectedEndpoints(string $address, int $port): array
{
    $endpoints = [$address . ':' . $port];
    if ($address === '0.0.0.0') {
        $endpoints[] = '*:' . $port;
    }
    return $endpoints;
}

function assertDnsListenerReady(array $plan, string $pid): void
{
    if (($plan['required'] ?? false) !== true) {
        return;
    }

    if (!is_executable(SOCKSTAT_BINARY)) {
        failReadiness('Команда sockstat недоступна для проверки DNS listener.', 69);
    }

    $listener = $plan['dns_listener'];
    $address = (string)$listener['address'];
    $port = (int)$listener['port'];
    $command = escapeshellarg(SOCKSTAT_BINARY)
        . ' -4 -l -n -P tcp,udp -p ' . escapeshellarg((string)$port) . ' 2>&1';
    $output = [];
    $status = 0;
    exec($command, $output, $status);
    if ($status !== 0) {
        failReadiness('Не удалось получить состояние DNS listener через sockstat.', 69);
    }

    $expectedEndpoints = expectedEndpoints($address, $port);
    $protocols = ['tcp' => false, 'udp' => false];
    foreach ($output as $line) {
        $columns = preg_split('/\s+/', trim($line));
        if (!is_array($columns) || count($columns) < 6 || ($columns[2] ?? '') !== $pid) {
            continue;
        }

        $protocol = strtolower((string)($columns[4] ?? ''));
        $localEndpoint = (string)($columns[5] ?? '');
        if (!in_array($localEndpoint, $expectedEndpoints, true)) {
            continue;
        }

        if (str_starts_with($protocol, 'tcp')) {
            $protocols['tcp'] = true;
        } elseif (str_starts_with($protocol, 'udp')) {
            $protocols['udp'] = true;
        }
    }

    $missing = [];
    foreach ($protocols as $protocol => $ready) {
        if (!$ready) {
            $missing[] = $protocol;
        }
    }
    if ($missing !== []) {
        failReadiness(
            'DNS listener текущего процесса sing-box не готов для протоколов: ' . implode(', ', $missing) . '.',
            75
        );
    }
}

try {
    [$planPath, $pid] = parseArguments($argv);
    $plan = loadPolicyPlan($planPath);
    assertDnsListenerReady($plan, $pid);
    echo "OK DNS listener sing-box готов к firewall-активации.\n";
    exit(0);
} catch (PolicyReadinessException $error) {
    fwrite(STDERR, 'ERROR ' . $error->getMessage() . PHP_EOL);
    exit($error->getCode() > 0 ? $error->getCode() : 70);
} catch (Throwable $error) {
    fwrite(STDERR, "ERROR Неожиданная ошибка проверки readiness DNS listener.\n");
    exit(70);
}
