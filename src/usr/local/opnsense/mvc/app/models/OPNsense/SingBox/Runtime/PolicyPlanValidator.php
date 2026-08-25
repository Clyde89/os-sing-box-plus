<?php

namespace OPNsense\SingBox\Runtime;

final class PolicyPlanValidator
{
    private const EXPECTED_SCHEMA_VERSION = 1;
    private const EXPECTED_OWNER = 'os-sing-box-plus';

    public static function validate(array $plan): array
    {
        $errors = [];

        if (($plan['schema_version'] ?? null) !== self::EXPECTED_SCHEMA_VERSION) {
            $errors[] = 'Неподдерживаемая версия схемы policy-плана.';
        }
        if (($plan['managed_by'] ?? null) !== self::EXPECTED_OWNER) {
            $errors[] = 'Policy-план не принадлежит os-sing-box-plus.';
        }

        $captureMode = $plan['capture_mode'] ?? null;
        if (!in_array($captureMode, ['selected', 'all_lan'], true)) {
            $errors[] = 'Policy-план содержит неподдерживаемый режим захвата.';
        }

        $interfaces = is_array($plan['capture_interfaces'] ?? null) ? $plan['capture_interfaces'] : [];
        foreach ($interfaces as $interface) {
            if (!is_string($interface) || preg_match('/^[A-Za-z0-9_.-]{1,32}$/', $interface) !== 1) {
                $errors[] = 'Policy-план содержит некорректный интерфейс захвата.';
                continue;
            }
            if (strtolower($interface) === 'wan') {
                $errors[] = 'Policy-план не может автоматически изменять WAN.';
            }
        }

        $operations = $plan['operations'] ?? null;
        if (!is_array($operations)) {
            $errors[] = 'Policy-план не содержит корректного списка операций.';
            return array_values(array_unique($errors));
        }

        $operationIds = [];
        foreach ($operations as $operation) {
            if (!is_array($operation)) {
                $errors[] = 'Policy-план содержит операцию неподдерживаемого формата.';
                continue;
            }

            $id = $operation['id'] ?? null;
            if (!is_string($id) || preg_match('/^[a-z0-9_.-]{1,96}$/', $id) !== 1) {
                $errors[] = 'Policy-план содержит некорректный идентификатор операции.';
            } elseif (isset($operationIds[$id])) {
                $errors[] = 'Policy-план содержит повторяющийся идентификатор операции.';
            } else {
                $operationIds[$id] = true;
            }

            $type = $operation['type'] ?? null;
            if ($type === 'dns_redirect') {
                self::validateDnsRedirect($operation, $captureMode, $errors);
            } elseif ($type === 'route') {
                self::validateRoute($operation, $errors);
            } else {
                $errors[] = 'Policy-план содержит неподдерживаемый тип операции.';
            }
        }

        return array_values(array_unique($errors));
    }

    public static function assertValid(array $plan): void
    {
        $errors = self::validate($plan);
        if ($errors !== []) {
            throw new \RuntimeException('Некорректный policy-план: ' . implode(' ', $errors));
        }
    }

    public static function checksum(array $plan): string
    {
        self::assertValid($plan);
        $normalized = self::sortRecursive($plan);
        $json = json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new \RuntimeException('Не удалось сериализовать policy-план для вычисления SHA-256.');
        }
        return hash('sha256', $json);
    }

    private static function validateDnsRedirect(array $operation, $captureMode, array &$errors): void
    {
        $interface = $operation['interface'] ?? null;
        if (!is_string($interface) || preg_match('/^[A-Za-z0-9_.-]{1,32}$/', $interface) !== 1) {
            $errors[] = 'DNS redirect содержит некорректный интерфейс.';
        } elseif (strtolower($interface) === 'wan') {
            $errors[] = 'DNS redirect не может автоматически применяться к WAN.';
        }

        if (!in_array($operation['protocol'] ?? null, ['udp', 'tcp'], true)) {
            $errors[] = 'DNS redirect содержит неподдерживаемый протокол.';
        }
        if (($operation['destination_port'] ?? null) !== 53) {
            $errors[] = 'DNS redirect может перехватывать только порт 53.';
        }

        $targetPort = $operation['target_port'] ?? null;
        if (!is_int($targetPort) || $targetPort < 1 || $targetPort > 65535) {
            $errors[] = 'DNS redirect содержит некорректный целевой порт.';
        }

        $targetAddress = $operation['target_address'] ?? null;
        if (!is_string($targetAddress) || filter_var($targetAddress, FILTER_VALIDATE_IP) === false) {
            $errors[] = 'DNS redirect содержит некорректный целевой IP-адрес.';
        }

        $sources = $operation['source_ip_cidr'] ?? null;
        if (!is_array($sources)) {
            $errors[] = 'DNS redirect содержит некорректный source selector.';
        } elseif ($captureMode === 'selected' && $sources === []) {
            $errors[] = 'DNS redirect selected mode не может применяться без source selector.';
        }
    }

    private static function validateRoute(array $operation, array &$errors): void
    {
        $network = $operation['network'] ?? null;
        if (!is_string($network) || !self::isIpv4Network($network)) {
            $errors[] = 'Route operation содержит некорректную IPv4-сеть.';
        }

        $interface = $operation['interface'] ?? null;
        if (!is_string($interface) || preg_match('/^[A-Za-z0-9_.-]{1,32}$/', $interface) !== 1) {
            $errors[] = 'Route operation содержит некорректный интерфейс.';
        } elseif (strtolower($interface) === 'wan') {
            $errors[] = 'Route operation не может автоматически направляться в WAN.';
        }
    }

    private static function isIpv4Network(string $value): bool
    {
        if (substr_count($value, '/') !== 1) {
            return false;
        }
        [$address, $prefixValue] = explode('/', $value, 2);
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false || !ctype_digit($prefixValue)) {
            return false;
        }
        $prefix = (int)$prefixValue;
        return $prefix >= 0 && $prefix <= 32;
    }

    private static function sortRecursive(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::sortRecursive($item);
            }
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        return $value;
    }
}
