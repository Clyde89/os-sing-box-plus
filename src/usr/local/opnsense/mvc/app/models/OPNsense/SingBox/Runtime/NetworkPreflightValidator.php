<?php

namespace OPNsense\SingBox\Runtime;

final class NetworkPreflightValidator
{
    public static function validateStatic(array $runtimePlan): array
    {
        $errors = [];
        $plan = is_array($runtimePlan['policy_plan'] ?? null)
            ? $runtimePlan['policy_plan']
            : $runtimePlan;
        $selectors = is_array($runtimePlan['selectors'] ?? null) ? $runtimePlan['selectors'] : [];

        $fakeIp = self::parseIpv4Cidr((string)($plan['fakeip_ipv4_range'] ?? ''));
        $tun = self::parseIpv4Cidr((string)($plan['tun_address'] ?? ''));
        if ($fakeIp === null) {
            $errors[] = 'Не удалось проверить IPv4-сеть FakeIP.';
        }
        if ($tun === null) {
            $errors[] = 'Не удалось проверить IPv4-сеть TUN.';
        }

        if ($fakeIp !== null && $tun !== null && self::networksOverlap($fakeIp, $tun)) {
            $errors[] = sprintf(
                'Сеть FakeIP %s пересекается с сетью TUN %s.',
                self::networkLabel($fakeIp),
                self::networkLabel($tun)
            );
        }

        if ($fakeIp !== null) {
            foreach (['0.0.0.0/8', '127.0.0.0/8', '169.254.0.0/16', '224.0.0.0/3'] as $reserved) {
                $reservedNetwork = self::parseIpv4Cidr($reserved);
                if ($reservedNetwork !== null && self::networksOverlap($fakeIp, $reservedNetwork)) {
                    $errors[] = 'Сеть FakeIP пересекается со служебным или нерутизируемым диапазоном IPv4.';
                    break;
                }
            }
        }

        $dnsListener = (string)($plan['dns_listener']['address'] ?? '');
        $policyOutbound = is_array($plan['policy_outbound'] ?? null) ? $plan['policy_outbound'] : [];
        $bindAddress = (string)($policyOutbound['bind_address'] ?? '');
        $policyDnsAddress = (string)($selectors['policy_dns_address'] ?? '');

        self::validateAddressAgainstManagedNetworks('DNS listener', $dnsListener, $fakeIp, $tun, $errors);
        self::validateAddressAgainstManagedNetworks('Policy bind', $bindAddress, $fakeIp, $tun, $errors);
        self::validateAddressAgainstManagedNetworks('Policy DNS', $policyDnsAddress, $fakeIp, $tun, $errors);

        if ($bindAddress !== '' && self::isUnusableUnicastAddress($bindAddress)) {
            $errors[] = 'Исходящий IPv4-адрес policy outbound относится к служебному или нерутизируемому диапазону.';
        }
        if ($policyDnsAddress !== '' && self::isUnusableUnicastAddress($policyDnsAddress)) {
            $errors[] = 'IPv4-адрес policy DNS относится к служебному или нерутизируемому диапазону.';
        }
        if ($dnsListener !== '' && self::isInvalidListenerAddress($dnsListener)) {
            $errors[] = 'DNS listener не может использовать неопределённый, multicast или broadcast IPv4-адрес.';
        }
        if ($bindAddress !== '' && $policyDnsAddress !== '' && $bindAddress === $policyDnsAddress) {
            $errors[] = 'Policy bind и policy DNS не могут использовать один IPv4-адрес.';
        }

        return array_values(array_unique($errors));
    }

    public static function validateEnvironment(array $runtimePlan, array $environment): array
    {
        $errors = self::validateStatic($runtimePlan);
        $plan = is_array($runtimePlan['policy_plan'] ?? null)
            ? $runtimePlan['policy_plan']
            : $runtimePlan;
        $policyRequired = ($plan['required'] ?? false) === true;
        $interfaces = is_array($environment['interfaces'] ?? null) ? $environment['interfaces'] : [];
        $localAddresses = self::normalizeAddresses($environment['local_ipv4_addresses'] ?? []);
        $localNetworks = is_array($environment['local_ipv4_networks'] ?? null)
            ? $environment['local_ipv4_networks']
            : [];
        $gateways = is_array($environment['gateways'] ?? null) ? $environment['gateways'] : [];

        foreach ($plan['capture_interfaces'] ?? [] as $interface) {
            $record = is_array($interfaces[$interface] ?? null) ? $interfaces[$interface] : null;
            if ($record === null) {
                $errors[] = sprintf('Интерфейс захвата %s отсутствует в текущей конфигурации OPNsense.', $interface);
                continue;
            }
            if (($record['enabled'] ?? false) !== true) {
                $errors[] = sprintf('Интерфейс захвата %s отключён.', $interface);
            }
            if (($record['present'] ?? false) !== true || ($record['up'] ?? false) !== true) {
                $errors[] = sprintf('Сетевое устройство интерфейса захвата %s недоступно или не поднято.', $interface);
            }
        }

        $dnsListener = (string)($plan['dns_listener']['address'] ?? '');
        if (!self::isLoopbackAddress($dnsListener) && !isset($localAddresses[$dnsListener])) {
            $errors[] = sprintf('IPv4-адрес DNS listener %s не назначен OPNsense.', $dnsListener);
        }

        $policyOutbound = is_array($plan['policy_outbound'] ?? null) ? $plan['policy_outbound'] : [];
        $bindAddress = (string)($policyOutbound['bind_address'] ?? '');
        if ($policyRequired && $bindAddress !== '' && !isset($localAddresses[$bindAddress])) {
            $errors[] = sprintf('Исходящий IPv4-адрес policy outbound %s не назначен OPNsense.', $bindAddress);
        }

        $gatewayName = (string)($policyOutbound['gateway'] ?? '');
        if ($policyRequired && $gatewayName !== '') {
            $gateway = is_array($gateways[$gatewayName] ?? null) ? $gateways[$gatewayName] : null;
            if ($gateway === null) {
                $errors[] = sprintf('Gateway %s отсутствует в текущей конфигурации OPNsense.', $gatewayName);
            } elseif (($gateway['ipprotocol'] ?? '') !== 'inet') {
                $errors[] = sprintf('Gateway %s не относится к IPv4.', $gatewayName);
            } elseif (
                self::truthy($gateway['disabled'] ?? false)
                || self::truthy($gateway['defunct'] ?? false)
                || self::truthy($gateway['force_down'] ?? false)
                || trim((string)($gateway['if'] ?? '')) === ''
            ) {
                $errors[] = sprintf('Gateway %s отключён или не имеет доступного сетевого интерфейса.', $gatewayName);
            }
        }

        $fakeIp = self::parseIpv4Cidr((string)($plan['fakeip_ipv4_range'] ?? ''));
        $tun = self::parseIpv4Cidr((string)($plan['tun_address'] ?? ''));
        $tunInterface = (string)($plan['tun_interface'] ?? '');
        foreach ($localNetworks as $record) {
            if (is_string($record)) {
                $record = ['cidr' => $record, 'device' => ''];
            }
            if (!is_array($record)) {
                continue;
            }
            $local = self::parseIpv4Cidr((string)($record['cidr'] ?? ''));
            if ($local === null) {
                continue;
            }
            $device = (string)($record['device'] ?? '');
            if ($fakeIp !== null && self::networksOverlap($fakeIp, $local)) {
                $errors[] = sprintf(
                    'Сеть FakeIP %s пересекается с локальной сетью OPNsense %s%s.',
                    self::networkLabel($fakeIp),
                    self::networkLabel($local),
                    $device !== '' ? ' на ' . $device : ''
                );
            }
            if ($tun !== null && $device !== $tunInterface && self::networksOverlap($tun, $local)) {
                $errors[] = sprintf(
                    'Сеть TUN %s пересекается с локальной сетью OPNsense %s%s.',
                    self::networkLabel($tun),
                    self::networkLabel($local),
                    $device !== '' ? ' на ' . $device : ''
                );
            }
        }

        return array_values(array_unique($errors));
    }

    public static function assertEnvironmentValid(array $runtimePlan, array $environment): void
    {
        $errors = self::validateEnvironment($runtimePlan, $environment);
        if ($errors !== []) {
            throw new \RuntimeException('Сетевой preflight не пройден: ' . implode(' ', $errors));
        }
    }

    private static function validateAddressAgainstManagedNetworks(
        string $label,
        string $address,
        ?array $fakeIp,
        ?array $tun,
        array &$errors
    ): void {
        if ($address === '' || filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return;
        }
        if ($fakeIp !== null && self::addressInNetwork($address, $fakeIp)) {
            $errors[] = sprintf('%s %s попадает в управляемую сеть FakeIP %s.', $label, $address, self::networkLabel($fakeIp));
        }
        if ($tun !== null && self::addressInNetwork($address, $tun)) {
            $errors[] = sprintf('%s %s попадает в сеть TUN %s.', $label, $address, self::networkLabel($tun));
        }
    }

    private static function normalizeAddresses($addresses): array
    {
        if (!is_array($addresses)) {
            return [];
        }
        $result = [];
        foreach ($addresses as $address) {
            $address = trim((string)$address);
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                $result[$address] = true;
            }
        }
        return $result;
    }

    private static function parseIpv4Cidr(string $value): ?array
    {
        if (substr_count($value, '/') !== 1) {
            return null;
        }
        [$address, $prefixValue] = explode('/', $value, 2);
        if (
            filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
            || $prefixValue === ''
            || !ctype_digit($prefixValue)
        ) {
            return null;
        }
        $prefix = (int)$prefixValue;
        if ($prefix < 0 || $prefix > 32) {
            return null;
        }
        $packed = @inet_pton($address);
        if ($packed === false) {
            return null;
        }
        return [
            'address' => $packed,
            'network' => self::maskIpv4($packed, $prefix),
            'prefix' => $prefix,
        ];
    }

    private static function networksOverlap(array $left, array $right): bool
    {
        $prefix = min($left['prefix'], $right['prefix']);
        return self::maskIpv4($left['network'], $prefix) === self::maskIpv4($right['network'], $prefix);
    }

    private static function addressInNetwork(string $address, array $network): bool
    {
        $packed = @inet_pton($address);
        return $packed !== false
            && self::maskIpv4($packed, $network['prefix']) === $network['network'];
    }

    private static function networkLabel(array $network): string
    {
        $address = @inet_ntop($network['network']);
        return ($address !== false ? $address : 'неизвестно') . '/' . $network['prefix'];
    }

    private static function isLoopbackAddress(string $address): bool
    {
        $loopback = self::parseIpv4Cidr('127.0.0.0/8');
        return $loopback !== null && self::addressInNetwork($address, $loopback);
    }

    private static function isInvalidListenerAddress(string $address): bool
    {
        foreach (['0.0.0.0/8', '224.0.0.0/3'] as $reserved) {
            $network = self::parseIpv4Cidr($reserved);
            if ($network !== null && self::addressInNetwork($address, $network)) {
                return true;
            }
        }
        return false;
    }

    private static function isUnusableUnicastAddress(string $address): bool
    {
        foreach (['0.0.0.0/8', '127.0.0.0/8', '169.254.0.0/16', '224.0.0.0/3'] as $reserved) {
            $network = self::parseIpv4Cidr($reserved);
            if ($network !== null && self::addressInNetwork($address, $network)) {
                return true;
            }
        }
        return false;
    }

    private static function truthy($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        return in_array(strtolower(trim((string)$value)), ['1', 'yes', 'true', 'on'], true);
    }

    private static function maskIpv4(string $packed, int $prefix): string
    {
        $bytes = array_values(unpack('C*', $packed));
        $remaining = $prefix;
        foreach ($bytes as $index => $byte) {
            if ($remaining >= 8) {
                $remaining -= 8;
                continue;
            }
            if ($remaining <= 0) {
                $bytes[$index] = 0;
                continue;
            }
            $mask = (0xFF << (8 - $remaining)) & 0xFF;
            $bytes[$index] = $byte & $mask;
            $remaining = 0;
        }
        return pack('C*', ...$bytes);
    }
}
