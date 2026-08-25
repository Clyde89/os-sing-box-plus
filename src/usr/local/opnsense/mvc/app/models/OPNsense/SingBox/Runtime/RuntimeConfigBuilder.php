<?php

namespace OPNsense\SingBox\Runtime;

final class RuntimeConfigBuilder
{
    public static function build(array $nodes): array
    {
        if (isset($nodes['settings']) && is_array($nodes['settings'])) {
            $nodes = $nodes['settings'];
        }

        $capture = is_array($nodes['capture'] ?? null) ? $nodes['capture'] : [];
        $dns = is_array($nodes['dns'] ?? null) ? $nodes['dns'] : [];
        $tun = is_array($nodes['tun'] ?? null) ? $nodes['tun'] : [];

        $captureMode = self::stringValue($capture['mode'] ?? 'selected');
        $clients = self::splitLines(self::stringValue($capture['clients'] ?? ''));
        $redirectDomains = self::splitLines(self::stringValue($dns['redirectDomains'] ?? ''));
        $dnsListenAddress = self::stringValue($dns['listenAddress'] ?? '127.0.0.1');
        $dnsListenPort = self::intValue($dns['listenPort'] ?? 55353, 55353);
        $tunInterface = self::stringValue($tun['interfaceName'] ?? 'tun_singbox');
        $tunAddress = self::stringValue($tun['address'] ?? '172.19.0.1/30');
        $tunStack = self::stringValue($tun['stack'] ?? 'system');

        $config = [
            'log' => [
                'disabled' => false,
                'level' => 'info',
                'timestamp' => true,
            ],
            'dns' => [
                'servers' => [
                    [
                        'type' => 'local',
                        'tag' => 'local-dns',
                    ],
                ],
                'final' => 'local-dns',
            ],
            'inbounds' => [
                [
                    'type' => 'tun',
                    'tag' => 'tun-in',
                    'interface_name' => $tunInterface,
                    'address' => [$tunAddress],
                    'auto_route' => false,
                    'strict_route' => false,
                    'stack' => $tunStack,
                ],
                [
                    'type' => 'direct',
                    'tag' => 'dns-in',
                    'listen' => $dnsListenAddress,
                    'listen_port' => $dnsListenPort,
                ],
            ],
            'outbounds' => [
                [
                    'type' => 'direct',
                    'tag' => 'direct',
                ],
            ],
            'route' => [
                'rules' => [
                    [
                        'inbound' => 'dns-in',
                        'action' => 'hijack-dns',
                    ],
                ],
                'final' => 'direct',
            ],
        ];

        $warnings = [];
        if ($captureMode === 'all_lan') {
            $warnings[] = 'Режим перенаправления всего локального трафика ещё не подключён к генерации правил захвата.';
        }
        if ($clients !== []) {
            $warnings[] = 'Список клиентов сохранён в MVC-модели, но ещё не подключён к генерации правил захвата.';
        }
        if ($redirectDomains !== []) {
            $warnings[] = 'Список доменов сохранён в MVC-модели, но ещё не подключён к policy routing и FakeIP.';
        }

        return [
            'config' => $config,
            'selectors' => [
                'capture_mode' => $captureMode,
                'clients' => $clients,
                'redirect_domains' => $redirectDomains,
            ],
            'warnings' => $warnings,
            'apply_ready' => $warnings === [],
        ];
    }

    public static function encodeConfig(array $plan): string
    {
        $json = json_encode(
            $plan['config'] ?? [],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        if ($json === false) {
            throw new \RuntimeException('Не удалось сформировать JSON runtime-конфигурации sing-box.');
        }

        return $json . PHP_EOL;
    }

    private static function splitLines(string $value): array
    {
        $result = [];
        foreach (preg_split('/\R/u', $value) ?: [] as $item) {
            $item = trim($item);
            if ($item !== '') {
                $result[] = $item;
            }
        }
        return $result;
    }

    private static function stringValue($value): string
    {
        if (is_scalar($value) || (is_object($value) && method_exists($value, '__toString'))) {
            return trim((string)$value);
        }
        return '';
    }

    private static function intValue($value, int $default): int
    {
        $candidate = self::stringValue($value);
        if ($candidate === '' || !ctype_digit($candidate)) {
            return $default;
        }
        return (int)$candidate;
    }
}
