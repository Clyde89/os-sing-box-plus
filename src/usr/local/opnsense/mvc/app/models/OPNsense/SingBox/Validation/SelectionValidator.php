<?php

namespace OPNsense\SingBox\Validation;

final class SelectionValidator
{
    private const MAX_ITEMS = 4096;
    private const MAX_INPUT_BYTES = 1048576;

    public static function validateDomains(string $value): array
    {
        return self::validateList($value, [self::class, 'validateDomain']);
    }

    public static function validateClients(string $value): array
    {
        return self::validateList($value, [self::class, 'validateClient']);
    }

    private static function validateList(string $value, callable $validator): array
    {
        if (strlen($value) > self::MAX_INPUT_BYTES) {
            return ['Размер списка превышает 1 МиБ.'];
        }

        $messages = [];
        $seen = [];
        $items = 0;

        foreach (preg_split('/\R/u', $value) ?: [] as $index => $rawItem) {
            $item = trim($rawItem);
            if ($item === '') {
                continue;
            }

            $items++;
            if ($items > self::MAX_ITEMS) {
                $messages[] = 'Количество элементов превышает допустимый предел 4096.';
                break;
            }

            $normalized = strtolower(preg_replace('/\s+/', '', $item) ?? $item);
            if (isset($seen[$normalized])) {
                $messages[] = sprintf('Строка %d: обнаружено повторяющееся значение.', $index + 1);
                continue;
            }
            $seen[$normalized] = true;

            $message = $validator($item);
            if ($message !== null) {
                $messages[] = sprintf('Строка %d: %s', $index + 1, $message);
            }
        }

        return $messages;
    }

    private static function validateDomain(string $item): ?string
    {
        if (strlen($item) > 255) {
            return 'доменное имя превышает допустимую длину.';
        }

        $candidate = $item;
        if (str_starts_with($candidate, '*.')) {
            $candidate = substr($candidate, 2);
        }

        $candidate = rtrim($candidate, '.');
        if ($candidate === '' || str_contains($candidate, '*')) {
            return 'укажите доменное имя или шаблон вида *.example.org.';
        }

        if (filter_var($candidate, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            return 'укажите корректное доменное имя; для IDN требуется запись в формате punycode.';
        }

        return null;
    }

    private static function validateClient(string $item): ?string
    {
        if (filter_var($item, FILTER_VALIDATE_IP) !== false) {
            return null;
        }

        if (str_contains($item, '/')) {
            return self::validateCidr($item);
        }

        if (str_contains($item, '-')) {
            return self::validateRange($item);
        }

        return 'укажите IP-адрес, сеть CIDR или диапазон адресов.';
    }

    private static function validateCidr(string $item): ?string
    {
        if (substr_count($item, '/') !== 1) {
            return 'CIDR должен содержать один префикс сети.';
        }

        [$address, $prefix] = array_map('trim', explode('/', $item, 2));
        if ($address === '' || $prefix === '' || !ctype_digit($prefix)) {
            return 'укажите корректную сеть CIDR.';
        }

        $maxPrefix = null;
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $maxPrefix = 32;
        } elseif (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $maxPrefix = 128;
        }

        if ($maxPrefix === null || (int)$prefix > $maxPrefix) {
            return 'укажите корректную сеть CIDR.';
        }

        return null;
    }

    private static function validateRange(string $item): ?string
    {
        if (substr_count($item, '-') !== 1) {
            return 'диапазон должен содержать два IP-адреса, разделённых одним дефисом.';
        }

        [$start, $end] = array_map('trim', explode('-', $item, 2));
        $startPacked = @inet_pton($start);
        $endPacked = @inet_pton($end);

        if ($startPacked === false || $endPacked === false) {
            return 'укажите корректные начальный и конечный IP-адреса диапазона.';
        }

        if (strlen($startPacked) !== strlen($endPacked)) {
            return 'начальный и конечный адреса диапазона должны относиться к одному семейству IP.';
        }

        if (strcmp($startPacked, $endPacked) > 0) {
            return 'начальный адрес диапазона не должен быть больше конечного.';
        }

        return null;
    }
}
