<?php

namespace OPNsense\SingBox\Runtime;

final class SelectorCompiler
{
    public static function compileClients(array $items): array
    {
        $result = [];
        $seen = [];

        foreach ($items as $item) {
            $item = trim((string)$item);
            if ($item === '') {
                continue;
            }

            if (filter_var($item, FILTER_VALIDATE_IP) !== false) {
                self::appendUnique($result, $seen, self::canonicalIp($item));
                continue;
            }

            if (str_contains($item, '/')) {
                self::appendUnique($result, $seen, self::normalizeCidr($item));
                continue;
            }

            if (str_contains($item, '-')) {
                foreach (self::rangeToCidrs($item) as $cidr) {
                    self::appendUnique($result, $seen, $cidr);
                }
                continue;
            }

            throw new \InvalidArgumentException('Обнаружен неподдерживаемый селектор клиента.');
        }

        return $result;
    }

    public static function compileDomains(array $items): array
    {
        $domains = [];
        $suffixes = [];
        $seenDomains = [];
        $seenSuffixes = [];

        foreach ($items as $item) {
            $item = strtolower(rtrim(trim((string)$item), '.'));
            if ($item === '') {
                continue;
            }

            if (str_starts_with($item, '*.')) {
                $suffix = '.' . substr($item, 2);
                self::appendUnique($suffixes, $seenSuffixes, $suffix);
                continue;
            }

            self::appendUnique($domains, $seenDomains, $item);
        }

        return [
            'domain' => $domains,
            'domain_suffix' => $suffixes,
        ];
    }

    private static function appendUnique(array &$result, array &$seen, string $value): void
    {
        if (isset($seen[$value])) {
            return;
        }

        $seen[$value] = true;
        $result[] = $value;
    }

    private static function canonicalIp(string $address): string
    {
        $packed = @inet_pton(trim($address));
        if ($packed === false) {
            throw new \InvalidArgumentException('Не удалось нормализовать IP-адрес клиента.');
        }

        $normalized = @inet_ntop($packed);
        if ($normalized === false) {
            throw new \InvalidArgumentException('Не удалось преобразовать IP-адрес клиента.');
        }

        return strtolower($normalized);
    }

    private static function normalizeCidr(string $value): string
    {
        if (substr_count($value, '/') !== 1) {
            throw new \InvalidArgumentException('Некорректный CIDR селектора клиента.');
        }

        [$address, $prefixValue] = array_map('trim', explode('/', $value, 2));
        if ($address === '' || $prefixValue === '' || !ctype_digit($prefixValue)) {
            throw new \InvalidArgumentException('Некорректный CIDR селектора клиента.');
        }

        $packed = @inet_pton($address);
        if ($packed === false) {
            throw new \InvalidArgumentException('Некорректный IP-адрес в CIDR селектора клиента.');
        }

        $bits = strlen($packed) * 8;
        $prefix = (int)$prefixValue;
        if ($prefix < 0 || $prefix > $bits) {
            throw new \InvalidArgumentException('Некорректная длина префикса CIDR селектора клиента.');
        }

        $network = self::maskPacked($packed, $prefix);
        $normalized = @inet_ntop($network);
        if ($normalized === false) {
            throw new \InvalidArgumentException('Не удалось нормализовать CIDR селектора клиента.');
        }

        return strtolower($normalized) . '/' . $prefix;
    }

    private static function rangeToCidrs(string $value): array
    {
        if (substr_count($value, '-') !== 1) {
            throw new \InvalidArgumentException('Некорректный диапазон селектора клиента.');
        }

        [$startAddress, $endAddress] = array_map('trim', explode('-', $value, 2));
        $start = @inet_pton($startAddress);
        $end = @inet_pton($endAddress);

        if ($start === false || $end === false || strlen($start) !== strlen($end)) {
            throw new \InvalidArgumentException('Некорректный диапазон IP-адресов селектора клиента.');
        }

        if (strcmp($start, $end) > 0) {
            throw new \InvalidArgumentException('Начальный адрес диапазона больше конечного.');
        }

        $bits = strlen($start) * 8;
        $result = [];
        $current = $start;

        while (strcmp($current, $end) <= 0) {
            $prefix = $bits - self::trailingZeroBits($current);

            while ($prefix < $bits && strcmp(self::blockEnd($current, $prefix), $end) > 0) {
                $prefix++;
            }

            $address = @inet_ntop($current);
            if ($address === false) {
                throw new \InvalidArgumentException('Не удалось преобразовать диапазон селектора клиента.');
            }
            $result[] = strtolower($address) . '/' . $prefix;

            $next = self::incrementPacked(self::blockEnd($current, $prefix));
            if ($next === null) {
                break;
            }
            $current = $next;
        }

        return $result;
    }

    private static function maskPacked(string $packed, int $prefix): string
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

    private static function blockEnd(string $packed, int $prefix): string
    {
        $bytes = array_values(unpack('C*', $packed));
        $remaining = $prefix;

        foreach ($bytes as $index => $byte) {
            if ($remaining >= 8) {
                $remaining -= 8;
                continue;
            }

            if ($remaining <= 0) {
                $bytes[$index] = 0xFF;
                continue;
            }

            $hostBits = 8 - $remaining;
            $hostMask = (1 << $hostBits) - 1;
            $bytes[$index] = $byte | $hostMask;
            $remaining = 0;
        }

        return pack('C*', ...$bytes);
    }

    private static function trailingZeroBits(string $packed): int
    {
        $bytes = array_values(unpack('C*', $packed));
        $count = 0;

        for ($index = count($bytes) - 1; $index >= 0; $index--) {
            $byte = $bytes[$index];
            if ($byte === 0) {
                $count += 8;
                continue;
            }

            while (($byte & 1) === 0) {
                $count++;
                $byte >>= 1;
            }
            break;
        }

        return $count;
    }

    private static function incrementPacked(string $packed): ?string
    {
        $bytes = array_values(unpack('C*', $packed));

        for ($index = count($bytes) - 1; $index >= 0; $index--) {
            if ($bytes[$index] < 0xFF) {
                $bytes[$index]++;
                return pack('C*', ...$bytes);
            }
            $bytes[$index] = 0;
        }

        return null;
    }
}
