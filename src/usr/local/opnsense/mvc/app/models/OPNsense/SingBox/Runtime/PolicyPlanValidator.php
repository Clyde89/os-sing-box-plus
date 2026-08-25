<?php

namespace OPNsense\SingBox\Runtime;

final class PolicyPlanValidator
{
    private const EXPECTED_SCHEMA_VERSION = 2;
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

        $required = $plan['required'] ?? null;
        if (!is_bool($required)) {
            $errors[] = 'Policy-план содержит некорректный признак необходимости правил.';
        }

        $ready = $plan['ready'] ?? null;
        if (!is_bool($ready)) {
            $errors[] = 'Policy-план содержит некорректный признак готовности.';
        }

        $confirmationRequired = $plan['confirmation_required'] ?? null;
        $expectedConfirmation = $required === true && $captureMode === 'all_lan';
        if (!is_bool($confirmationRequired) || $confirmationRequired !== $expectedConfirmation) {
            $errors[] = 'Policy-план содержит некорректный признак подтверждения all_lan.';
        }

        $policyOutbound = $plan['policy_outbound'] ?? null;
        $policyOutboundReady = null;
        if (!is_array($policyOutbound)) {
            $errors[] = 'Policy-план не содержит корректное описание policy outbound.';
        } else {
            $policyOutboundReady = $policyOutbound['ready'] ?? null;
            if (!is_bool($policyOutboundReady)) {
                $errors[] = 'Policy outbound содержит некорректный признак готовности.';
            }
            if (($policyOutbound['required'] ?? null) !== $required) {
                $errors[] = 'Признак необходимости policy outbound не согласован с policy-планом.';
            }
            if ($required === true && ($policyOutbound['fail_closed'] ?? null) !== true) {
                $errors[] = 'Обязательный policy outbound должен использовать fail-closed.';
            }
        }

        $interfaces = is_array($plan['capture_interfaces'] ?? null) ? $plan['capture_interfaces'] : [];
        foreach ($interfaces as $interface) {
            if (!self::isSafeInterface($interface)) {
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
        $policyRoutes = [];
        $policyBlocks = [];
        foreach ($operations as $operationIndex => $operation) {
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
            } elseif ($type === 'policy_route') {
                self::validatePolicyRoute($operation, $errors);
                $policyRoutes[] = ['index' => $operationIndex, 'operation' => $operation];
            } elseif ($type === 'policy_block') {
                self::validatePolicyBlock($operation, $errors);
                $policyBlocks[] = ['index' => $operationIndex, 'operation' => $operation];
            } else {
                $errors[] = 'Policy-план содержит неподдерживаемый тип операции.';
            }
        }

        self::validateFailClosedPair(
            $required,
            $policyOutbound,
            $policyOutboundReady,
            $policyRoutes,
            $policyBlocks,
            $errors
        );

        if ($required === false && $operations !== []) {
            $errors[] = 'Policy-план без правил не должен содержать операции firewall.';
        }

        if ($required === true && $ready === true && $policyOutboundReady !== true) {
            $errors[] = 'Готовый policy-план не может содержать неготовый policy outbound.';
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
        if (!self::isSafeInterface($interface)) {
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
        if (!is_string($targetAddress) || filter_var($targetAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            $errors[] = 'DNS redirect содержит некорректный целевой IPv4-адрес.';
        }

        $sources = $operation['source_ip_cidr'] ?? null;
        if (!is_array($sources)) {
            $errors[] = 'DNS redirect содержит некорректный source selector.';
        } elseif ($captureMode === 'selected' && $sources === []) {
            $errors[] = 'DNS redirect selected mode не может применяться без source selector.';
        } elseif ($captureMode === 'all_lan' && $sources !== []) {
            $errors[] = 'DNS redirect all_lan не должен содержать source selector.';
        } else {
            foreach ($sources as $source) {
                if (!is_string($source) || !self::isIpv4AddressOrNetwork($source)) {
                    $errors[] = 'DNS redirect содержит source selector вне поддерживаемого IPv4 policy-контура.';
                    break;
                }
            }
        }
    }

    private static function validatePolicyRoute(array $operation, array &$errors): void
    {
        $sourceAddress = $operation['source_address'] ?? null;
        if (!is_string($sourceAddress) || filter_var($sourceAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            $errors[] = 'Policy route содержит некорректный исходящий IPv4-адрес.';
        }

        $gateway = $operation['gateway'] ?? null;
        if (!is_string($gateway) || preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $gateway) !== 1) {
            $errors[] = 'Policy route содержит некорректное имя gateway.';
        }
    }

    private static function validatePolicyBlock(array $operation, array &$errors): void
    {
        $sourceAddress = $operation['source_address'] ?? null;
        if (!is_string($sourceAddress) || filter_var($sourceAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            $errors[] = 'Fail-closed правило содержит некорректный исходящий IPv4-адрес.';
        }
    }

    private static function validateFailClosedPair(
        $required,
        $policyOutbound,
        $policyOutboundReady,
        array $policyRoutes,
        array $policyBlocks,
        array &$errors
    ): void {
        if (count($policyRoutes) > 1) {
            $errors[] = 'Policy-план содержит несколько операций policy route.';
        }
        if (count($policyBlocks) > 1) {
            $errors[] = 'Policy-план содержит несколько операций fail-closed.';
        }

        $hasRoute = count($policyRoutes) === 1;
        $hasBlock = count($policyBlocks) === 1;
        if ($hasRoute !== $hasBlock) {
            $errors[] = 'Policy route и fail-closed должны присутствовать только парой.';
            return;
        }

        if ($required === true && $policyOutboundReady === true && (!$hasRoute || !$hasBlock)) {
            $errors[] = 'Готовый policy outbound должен содержать policy route и fail-closed.';
            return;
        }

        if (!$hasRoute || !$hasBlock) {
            return;
        }

        if ($policyOutboundReady !== true) {
            $errors[] = 'Операции policy route и fail-closed не должны формироваться для неготового policy outbound.';
        }

        $route = $policyRoutes[0];
        $block = $policyBlocks[0];
        $routeSource = $route['operation']['source_address'] ?? null;
        $blockSource = $block['operation']['source_address'] ?? null;
        if ($routeSource !== $blockSource) {
            $errors[] = 'Policy route и fail-closed должны использовать один исходящий IPv4-адрес.';
        }
        if ($route['index'] >= $block['index']) {
            $errors[] = 'Fail-closed правило должно следовать после policy route.';
        }

        if (!is_array($policyOutbound)) {
            return;
        }
        if (($policyOutbound['bind_address'] ?? null) !== $routeSource) {
            $errors[] = 'Исходящий адрес policy route не согласован с policy outbound.';
        }
        if (($policyOutbound['gateway'] ?? null) !== ($route['operation']['gateway'] ?? null)) {
            $errors[] = 'Gateway операции policy route не согласован с policy outbound.';
        }
    }

    private static function isSafeInterface($value): bool
    {
        return is_string($value) && preg_match('/^[A-Za-z0-9_.-]{1,32}$/', $value) === 1;
    }

    private static function isIpv4AddressOrNetwork(string $value): bool
    {
        if (filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return true;
        }
        return self::isIpv4Network($value);
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
