<?php

require_once __DIR__ . '/../src/usr/local/opnsense/mvc/app/models/OPNsense/SingBox/Runtime/SelectorCompiler.php';

use OPNsense\SingBox\Runtime\SelectorCompiler;

function failCompilerTest(string $message): void
{
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}

function assertCompilerSame($expected, $actual, string $label): void
{
    if ($expected !== $actual) {
        failCompilerTest($label . ': получено неожиданное значение: ' . var_export($actual, true));
    }
}

$clients = SelectorCompiler::compileClients([
    '192.0.2.10',
    '2001:0db8::10',
    '192.0.2.129/24',
    '2001:db8::1234/64',
    '192.0.2.10-192.0.2.20',
    '2001:db8::10-2001:db8::20',
]);

assertCompilerSame(
    [
        '192.0.2.10',
        '2001:db8::10',
        '192.0.2.0/24',
        '2001:db8::/64',
        '192.0.2.10/31',
        '192.0.2.12/30',
        '192.0.2.16/30',
        '192.0.2.20/32',
        '2001:db8::10/124',
        '2001:db8::20/128',
    ],
    $clients,
    'Компиляция IPv4/IPv6 селекторов клиентов'
);

assertCompilerSame(
    ['0.0.0.0/0'],
    SelectorCompiler::compileClients(['0.0.0.0-255.255.255.255']),
    'Компиляция полного диапазона IPv4'
);

assertCompilerSame(
    ['::/0'],
    SelectorCompiler::compileClients(['::-ffff:ffff:ffff:ffff:ffff:ffff:ffff:ffff']),
    'Компиляция полного диапазона IPv6'
);

$domains = SelectorCompiler::compileDomains([
    'Example.org.',
    '*.Sub.Example.org.',
    'example.org',
    '*.sub.example.org',
]);

assertCompilerSame(
    [
        'domain' => ['example.org'],
        'domain_suffix' => ['.sub.example.org'],
    ],
    $domains,
    'Компиляция точных доменов и wildcard-шаблонов'
);

$invalid = false;
try {
    SelectorCompiler::compileClients(['192.0.2.20-192.0.2.10']);
} catch (InvalidArgumentException $error) {
    $invalid = true;
}
if (!$invalid) {
    failCompilerTest('Обратный диапазон должен отклоняться компилятором.');
}

echo "Компиляция селекторов клиентов и доменов проверена\n";
