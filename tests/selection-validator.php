<?php

require_once __DIR__ . '/../src/usr/local/opnsense/mvc/app/models/OPNsense/SingBox/Validation/SelectionValidator.php';

use OPNsense\SingBox\Validation\SelectionValidator;

function assertValid(array $messages, string $label): void
{
    if ($messages !== []) {
        fwrite(STDERR, $label . ': ожидался успешный результат, получено: ' . implode(' | ', $messages) . PHP_EOL);
        exit(1);
    }
}

function assertInvalid(array $messages, string $label): void
{
    if ($messages === []) {
        fwrite(STDERR, $label . ': ожидалась ошибка валидации.' . PHP_EOL);
        exit(1);
    }
}

function assertMessageContains(array $messages, string $needle, string $label): void
{
    foreach ($messages as $message) {
        if (str_contains($message, $needle)) {
            return;
        }
    }

    fwrite(STDERR, $label . ': ожидаемый фрагмент сообщения не найден.' . PHP_EOL);
    exit(1);
}

assertValid(
    SelectionValidator::validateDomains("example.org\n*.sub.example.org\nexample.net.\nxn--e1afmkfd.xn--p1ai\n"),
    'Корректные домены'
);
assertInvalid(SelectionValidator::validateDomains("https://example.org\n"), 'URL вместо домена');
assertInvalid(SelectionValidator::validateDomains("*example.org\n"), 'Некорректный wildcard');
assertInvalid(SelectionValidator::validateDomains("Example.org\nexample.org\n"), 'Повтор домена');
assertInvalid(SelectionValidator::validateDomains("example..org\n"), 'Некорректная структура домена');

assertValid(
    SelectionValidator::validateClients(
        "192.0.2.10\n2001:db8::10\n192.0.2.0/24\n2001:db8::/64\n192.0.2.10-192.0.2.20\n2001:db8::10-2001:db8::20\n"
    ),
    'Корректные клиенты'
);
assertValid(
    SelectionValidator::validateClients("192.0.2.10 - 192.0.2.20\n"),
    'Диапазон с пробелами'
);
assertInvalid(SelectionValidator::validateClients("192.0.2.10/99\n"), 'Некорректный IPv4 CIDR');
assertInvalid(SelectionValidator::validateClients("2001:db8::1/129\n"), 'Некорректный IPv6 CIDR');
assertInvalid(SelectionValidator::validateClients("192.0.2.20-192.0.2.10\n"), 'Обратный диапазон');
assertInvalid(SelectionValidator::validateClients("192.0.2.10-2001:db8::10\n"), 'Смешанное семейство диапазона');
assertInvalid(SelectionValidator::validateClients("192.0.2.10\n192.0.2.10\n"), 'Повтор клиента');
assertInvalid(SelectionValidator::validateClients("client.example.org\n"), 'Домен вместо клиента');

assertValid(SelectionValidator::validateCaptureInterfaces(['lan', 'opt1', 'vlan10']), 'Корректные интерфейсы захвата массивом');
assertValid(SelectionValidator::validateCaptureInterfaces('lan,opt1'), 'Корректные интерфейсы захвата строкой');
assertInvalid(SelectionValidator::validateCaptureInterfaces(['wan']), 'Запрет WAN для автоматического захвата');
assertMessageContains(
    SelectionValidator::validateCaptureInterfaces(['wan']),
    'WAN',
    'Пояснение запрета WAN'
);
assertInvalid(SelectionValidator::validateCaptureInterfaces(['lan', 'LAN']), 'Повтор интерфейса без учёта регистра');
assertInvalid(SelectionValidator::validateCaptureInterfaces(['lan/1']), 'Некорректное имя интерфейса захвата');

assertValid(SelectionValidator::validateIpv4Network('198.18.0.0/15'), 'Стандартный диапазон FakeIP');
assertValid(SelectionValidator::validateIpv4Network('10.0.0.0/8'), 'Пользовательская IPv4-сеть FakeIP');
assertValid(SelectionValidator::validateIpv4Network('0.0.0.0/0'), 'IPv4-сеть с нулевым префиксом');
assertInvalid(SelectionValidator::validateIpv4Network('198.18.0.1/15'), 'Host-биты в диапазоне FakeIP');
assertMessageContains(
    SelectionValidator::validateIpv4Network('198.18.0.1/15'),
    '198.18.0.0/15',
    'Подсказка корректного адреса сети FakeIP'
);
assertInvalid(SelectionValidator::validateIpv4Network('2001:db8::/64'), 'IPv6 вместо FakeIP IPv4');
assertInvalid(SelectionValidator::validateIpv4Network('198.18.0.0/33'), 'Некорректный префикс FakeIP IPv4');
assertInvalid(SelectionValidator::validateIpv4Network('198.18.0.0'), 'FakeIP без CIDR-префикса');

$tooManyItems = [];
for ($index = 0; $index < 4097; $index++) {
    $tooManyItems[] = sprintf('2001:db8:%x::1', $index);
}
assertInvalid(SelectionValidator::validateClients(implode("\n", $tooManyItems)), 'Превышение количества элементов');

echo "Валидация доменов, клиентов, интерфейсов и диапазона FakeIP проверена\n";
