<?php

namespace OPNsense\SingBox\Runtime;

final class RuntimeConfigBuilder
{
    private const FAKEIP_IPV4_RANGE = '198.18.0.0/15';

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

        $compiledClients = SelectorCompiler::compileClients($clients);
        $compiledDomains = SelectorCompiler::compileDomains($redirectDomains);

        $dnsServers = [
            [
                'type' => 'local',
                'tag' => 'local-dns',
            ],
        ];
        $dnsRules = [];
        $warnings = [];

        if (!in_array($captureMode, ['selected', 'all_lan'], true)) {
            $warnings[] = 'Выбран неподдерживаемый режим захвата трафика.';
        }

        if ($redirectDomains !== []) {
            $dnsServers[] = [
                'type' => 'fakeip',
                'tag' => 'fakeip-dns',
                'inet4_range' => self::FAKEIP_IPV4_RANGE,
            ];

            $dnsRule = [
                'query_type' => ['A'],
            ];
            if ($compiledDomains['domain'] !== []) {
                $dnsRule['domain'] = $compiledDomains['domain'];
            }
            if ($compiledDomains['domain_suffix'] !== []) {
                $dnsRule['domain_suffix'] = $compiledDomains['domain_suffix'];
            }

            $dnsRuleReady = true;
            if ($captureMode === 'selected') {
                if ($compiledClients === []) {
                    $warnings[] = 'Для режима выбранных клиентов необходимо указать хотя бы один IP-адрес, CIDR или диапазон.';
                    $dnsRuleReady = false;
                } else {
                    $dnsRule['source_ip_cidr'] = $compiledClients;
                }
            } elseif ($captureMode !== 'all_lan') {
                $dnsRuleReady = false;
            }

            if ($dnsRuleReady) {
                $dnsRule['action'] = 'route';
                $dnsRule['server'] = 'fakeip-dns';
                $dnsRules[] = $dnsRule;
            }

            $warnings[] = 'Селекторы доменов уже компилируются в DNS/FakeIP preview, но policy outbound ещё не подключён.';
            $warnings[] = 'Правила перенаправления DNS и FakeIP-трафика на стороне OPNsense ещё не применяются автоматически.';
            $warnings[] = 'Текущий FakeIP preview обрабатывает только A-запросы; IPv6 policy routing будет добавлен отдельно.';
        } elseif ($clients !== []) {
            $warnings[] = 'Список клиентов задан, но список доменов пуст; policy-маршрутизация пока не формируется.';
        }

        if ($captureMode === 'all_lan') {
            $warnings[] = 'Режим всего локального трафика требует отдельного подтверждения и ещё не подключён к правилам OPNsense.';
        }

        $dnsConfig = [
            'servers' => $dnsServers,
            'final' => 'local-dns',
        ];
        if ($dnsRules !== []) {
            $dnsConfig['rules'] = $dnsRules;
        }

        $config = [
            'log' => [
                'disabled' => false,
                'level' => 'info',
                'timestamp' => true,
            ],
            'dns' => $dnsConfig,
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

        return [
            'config' => $config,
            'selectors' => [
                'capture_mode' => $captureMode,
                'clients' => $clients,
                'redirect_domains' => $redirectDomains,
                'source_ip_cidr' => $compiledClients,
                'domain' => $compiledDomains['domain'],
                'domain_suffix' => $compiledDomains['domain_suffix'],
            ],
            'policy_plan' => [
                'capture_mode' => $captureMode,
                'source_ip_cidr' => $compiledClients,
                'domain' => $compiledDomains['domain'],
                'domain_suffix' => $compiledDomains['domain_suffix'],
                'fakeip_ipv4_range' => self::FAKEIP_IPV4_RANGE,
                'dns_query_types' => ['A'],
                'requires_opnsense_dns_redirect' => $redirectDomains !== [],
                'requires_opnsense_fakeip_route' => $redirectDomains !== [],
                'requires_policy_outbound' => $redirectDomains !== [],
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
