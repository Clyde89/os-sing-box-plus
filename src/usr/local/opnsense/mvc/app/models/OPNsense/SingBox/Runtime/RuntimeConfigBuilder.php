<?php

namespace OPNsense\SingBox\Runtime;

use OPNsense\SingBox\Validation\SelectionValidator;

final class RuntimeConfigBuilder
{
    private const DEFAULT_FAKEIP_IPV4_RANGE = '198.18.0.0/15';
    private const POLICY_DNS_TAG = 'policy-dns';
    private const POLICY_DNS_BOOTSTRAP_TAG = 'policy-dns-bootstrap';

    public static function build(array $nodes): array
    {
        if (isset($nodes['settings']) && is_array($nodes['settings'])) {
            $nodes = $nodes['settings'];
        }

        $capture = is_array($nodes['capture'] ?? null) ? $nodes['capture'] : [];
        $dns = is_array($nodes['dns'] ?? null) ? $nodes['dns'] : [];
        $policy = is_array($nodes['policy'] ?? null) ? $nodes['policy'] : [];
        $tun = is_array($nodes['tun'] ?? null) ? $nodes['tun'] : [];

        $captureMode = self::stringValue($capture['mode'] ?? 'selected');
        $captureInterfaces = self::listValue($capture['interfaces'] ?? []);
        $clients = self::splitLines(self::stringValue($capture['clients'] ?? ''));
        $redirectDomains = self::splitLines(self::stringValue($dns['redirectDomains'] ?? ''));
        $dnsListenAddress = self::stringValue($dns['listenAddress'] ?? '127.0.0.1');
        $dnsListenPort = self::intValue($dns['listenPort'] ?? 55353, 55353);
        $fakeIpRange = self::stringValue($dns['fakeIpRange'] ?? self::DEFAULT_FAKEIP_IPV4_RANGE);
        $policyDnsType = self::stringValue($dns['policyUpstreamType'] ?? 'https');
        $policyDnsAddress = self::stringValue($dns['policyUpstreamAddress'] ?? '');
        $policyDnsPort = self::intValue($dns['policyUpstreamPort'] ?? 443, 443);
        $policyDnsTlsServerName = self::stringValue($dns['policyUpstreamTlsServerName'] ?? '');
        $policyDnsPath = self::stringValue($dns['policyUpstreamPath'] ?? '/dns-query');
        $policyOutboundMode = self::stringValue($policy['outboundMode'] ?? 'direct_bind');
        $policyBindAddress = self::stringValue($policy['bindAddress'] ?? '');
        $policyGateway = self::stringValue($policy['gateway'] ?? '');
        $tunInterface = self::stringValue($tun['interfaceName'] ?? 'tun_singbox');
        $tunAddress = self::stringValue($tun['address'] ?? '172.19.0.1/30');
        $tunStack = self::stringValue($tun['stack'] ?? 'system');

        if ($fakeIpRange === '') {
            $fakeIpRange = self::DEFAULT_FAKEIP_IPV4_RANGE;
        }
        if ($policyOutboundMode === '') {
            $policyOutboundMode = 'direct_bind';
        }

        self::validateStructuredInput(
            $captureMode,
            $captureInterfaces,
            $clients,
            $redirectDomains,
            $fakeIpRange,
            $policyDnsType,
            $policyDnsAddress,
            $policyDnsPort,
            $policyDnsTlsServerName,
            $policyDnsPath,
            $policyOutboundMode,
            $policyBindAddress,
            $policyGateway
        );

        $compiledClients = SelectorCompiler::compileClients($clients);
        $compiledIpv4Clients = [];
        $compiledIpv6Clients = [];
        foreach ($compiledClients as $client) {
            if (str_contains($client, ':')) {
                $compiledIpv6Clients[] = $client;
            } else {
                $compiledIpv4Clients[] = $client;
            }
        }

        $compiledDomains = SelectorCompiler::compileDomains($redirectDomains);
        $policyPlan = PolicyPlanBuilder::build(
            $captureMode,
            $captureInterfaces,
            $compiledIpv4Clients,
            $compiledDomains,
            $dnsListenAddress,
            $dnsListenPort,
            $fakeIpRange,
            $tunInterface,
            $tunAddress,
            $policyOutboundMode,
            $policyBindAddress,
            $policyGateway
        );
        PolicyPlanValidator::assertValid($policyPlan);
        $policyRequired = $policyPlan['required'] === true;
        $dnsBootstrapReady = !$policyRequired || (
            $policyDnsAddress !== '' && $policyBindAddress !== '' && $policyGateway !== ''
        );
        $dnsBootstrap = [
            'required' => $policyRequired,
            'ready' => $dnsBootstrapReady,
            'transport' => $policyRequired ? $policyDnsType : 'not_required',
            'server_address' => $policyRequired ? $policyDnsAddress : null,
            'server_port' => $policyRequired ? $policyDnsPort : null,
            'tls_server_name' => $policyRequired && $policyDnsTlsServerName !== ''
                ? $policyDnsTlsServerName
                : null,
            'path' => $policyRequired ? $policyDnsPath : null,
            'dns_server_tag' => $policyRequired ? self::POLICY_DNS_TAG : null,
            'bootstrap_outbound_tag' => $policyRequired ? self::POLICY_DNS_BOOTSTRAP_TAG : null,
            'bind_address' => $policyRequired && $policyBindAddress !== '' ? $policyBindAddress : null,
            'uses_domain_resolver' => false,
        ];

        $dnsServers = [
            [
                'type' => 'local',
                'tag' => 'local-dns',
            ],
        ];
        $dnsRules = [];
        $warnings = [];

        if ($policyRequired) {
            $dnsServers[] = [
                'type' => 'fakeip',
                'tag' => 'fakeip-dns',
                'inet4_range' => $fakeIpRange,
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

            $dnsRuleReady = $captureMode === 'all_lan' || $compiledIpv4Clients !== [];
            if ($captureMode === 'selected') {
                if (!$dnsRuleReady) {
                    $warnings[] = 'Для IPv4 policy routing в режиме выбранных клиентов необходимо указать хотя бы один IPv4-адрес, CIDR или диапазон.';
                } else {
                    $dnsRule['source_ip_cidr'] = $compiledIpv4Clients;
                }
            }

            if ($compiledIpv6Clients !== []) {
                $warnings[] = 'IPv6-клиенты сохранены в модели, но текущий FakeIP policy-контур поддерживает только IPv4/A-запросы.';
            }
            if ($captureInterfaces === []) {
                $warnings[] = 'Для policy routing необходимо выбрать хотя бы один интерфейс локальной сети.';
            }
            if ($policyBindAddress === '') {
                $warnings[] = 'Для policy outbound необходимо указать отдельный исходящий IPv4-адрес.';
            }
            if ($policyGateway === '') {
                $warnings[] = 'Для policy routing необходимо выбрать IPv4 gateway OPNsense.';
            }
            if ($policyDnsAddress === '') {
                $warnings[] = 'Для policy-bound DNS необходимо указать IPv4-адрес upstream DNS over HTTPS.';
            }

            if ($dnsRuleReady) {
                $dnsRule['action'] = 'route';
                $dnsRule['server'] = 'fakeip-dns';
                $dnsRules[] = $dnsRule;
            }

            if ($dnsBootstrapReady) {
                $policyDnsServer = [
                    'type' => $policyDnsType,
                    'tag' => self::POLICY_DNS_TAG,
                    'server' => $policyDnsAddress,
                    'server_port' => $policyDnsPort,
                    'path' => $policyDnsPath,
                    'detour' => self::POLICY_DNS_BOOTSTRAP_TAG,
                ];
                if ($policyDnsTlsServerName !== '') {
                    $policyDnsServer['tls'] = [
                        'enabled' => true,
                        'server_name' => $policyDnsTlsServerName,
                    ];
                }
                $dnsServers[] = $policyDnsServer;
            }
        } elseif ($clients !== [] || $captureInterfaces !== []) {
            $warnings[] = 'Параметры захвата заданы, но список доменов пуст; policy routing не формируется.';
        }

        if ($captureMode === 'all_lan' && $policyRequired) {
            $warnings[] = 'Режим всего локального трафика требует отдельного подтверждения перед активацией автоматических правил.';
        }

        $dnsConfig = [
            'servers' => $dnsServers,
            'final' => 'local-dns',
        ];
        if ($dnsRules !== []) {
            $dnsConfig['rules'] = $dnsRules;
        }

        $outbounds = [
            [
                'type' => 'direct',
                'tag' => 'direct',
            ],
        ];

        $routeRules = [
            [
                'inbound' => 'dns-in',
                'action' => 'hijack-dns',
            ],
        ];

        if ($policyRequired && $policyBindAddress !== '') {
            $policyOutbound = [
                'type' => 'direct',
                'tag' => 'policy-out',
                'inet4_bind_address' => $policyBindAddress,
            ];
            if ($dnsBootstrapReady) {
                $policyOutbound['domain_resolver'] = self::POLICY_DNS_TAG;
            }
            $outbounds[] = $policyOutbound;

            if ($dnsBootstrapReady) {
                $outbounds[] = [
                    'type' => 'direct',
                    'tag' => self::POLICY_DNS_BOOTSTRAP_TAG,
                    'inet4_bind_address' => $policyBindAddress,
                ];
            }
            $routeRules[] = [
                'ip_cidr' => [$fakeIpRange],
                'action' => 'route',
                'outbound' => 'policy-out',
            ];
        }

        $tunInbound = [
            'type' => 'tun',
            'tag' => 'tun-in',
            'interface_name' => $tunInterface,
            'address' => [$tunAddress],
            'auto_route' => $policyRequired,
            'strict_route' => false,
            'stack' => $tunStack,
        ];
        if ($policyRequired) {
            $tunInbound['route_address'] = [$fakeIpRange];
        }

        $config = [
            'log' => [
                'disabled' => false,
                'level' => 'info',
                'timestamp' => true,
            ],
            'dns' => $dnsConfig,
            'inbounds' => [
                $tunInbound,
                [
                    'type' => 'direct',
                    'tag' => 'dns-in',
                    'listen' => $dnsListenAddress,
                    'listen_port' => $dnsListenPort,
                ],
            ],
            'outbounds' => $outbounds,
            'route' => [
                'rules' => $routeRules,
                'final' => 'direct',
            ],
        ];

        return [
            'config' => $config,
            'selectors' => [
                'capture_mode' => $captureMode,
                'capture_interfaces' => $captureInterfaces,
                'clients' => $clients,
                'redirect_domains' => $redirectDomains,
                'source_ip_cidr' => $compiledClients,
                'source_ipv4_cidr' => $compiledIpv4Clients,
                'source_ipv6_cidr' => $compiledIpv6Clients,
                'domain' => $compiledDomains['domain'],
                'domain_suffix' => $compiledDomains['domain_suffix'],
                'policy_outbound_mode' => $policyOutboundMode,
                'policy_bind_address' => $policyBindAddress,
                'policy_gateway' => $policyGateway,
                'policy_dns_type' => $policyDnsType,
                'policy_dns_address' => $policyDnsAddress,
            ],
            'policy_plan' => $policyPlan,
            'policy_sha256' => PolicyPlanValidator::checksum($policyPlan),
            'dns_bootstrap' => $dnsBootstrap,
            'warnings' => $warnings,
            'apply_ready' => $warnings === [] && $policyPlan['ready'] === true,
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

    private static function validateStructuredInput(
        string $captureMode,
        array $captureInterfaces,
        array $clients,
        array $redirectDomains,
        string $fakeIpRange,
        string $policyDnsType,
        string $policyDnsAddress,
        int $policyDnsPort,
        string $policyDnsTlsServerName,
        string $policyDnsPath,
        string $policyOutboundMode,
        string $policyBindAddress,
        string $policyGateway
    ): void {
        if (!in_array($captureMode, ['selected', 'all_lan'], true)) {
            throw new \RuntimeException('MVC-модель содержит неподдерживаемый режим перенаправления.');
        }
        if ($policyOutboundMode !== 'direct_bind') {
            throw new \RuntimeException('MVC-модель содержит неподдерживаемый режим policy outbound.');
        }
        if ($policyDnsType !== 'https') {
            throw new \RuntimeException('MVC-модель содержит неподдерживаемый транспорт policy DNS.');
        }
        if ($policyDnsPort < 1 || $policyDnsPort > 65535) {
            throw new \RuntimeException('MVC-модель содержит некорректный порт policy DNS.');
        }
        if ($policyDnsPath === '' || strlen($policyDnsPath) > 256
            || preg_match('#^/[A-Za-z0-9._~%/-]*$#', $policyDnsPath) !== 1
        ) {
            throw new \RuntimeException('MVC-модель содержит некорректный путь policy DNS over HTTPS.');
        }
        if ($policyDnsTlsServerName !== ''
            && filter_var(rtrim($policyDnsTlsServerName, '.'), FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
        ) {
            throw new \RuntimeException('MVC-модель содержит некорректное TLS-имя policy DNS.');
        }
        if ($policyGateway !== '' && preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $policyGateway) !== 1) {
            throw new \RuntimeException('MVC-модель содержит некорректное имя gateway policy routing.');
        }

        $messages = array_merge(
            SelectionValidator::validateCaptureInterfaces($captureInterfaces),
            SelectionValidator::validateClients(implode("\n", $clients)),
            SelectionValidator::validateDomains(implode("\n", $redirectDomains)),
            SelectionValidator::validateIpv4Network($fakeIpRange),
            SelectionValidator::validateIpv4Address($policyDnsAddress, true),
            SelectionValidator::validateIpv4Address($policyBindAddress, true)
        );

        if ($messages !== []) {
            throw new \RuntimeException('MVC-модель содержит некорректные структурированные настройки: ' . implode(' ', $messages));
        }
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

    private static function listValue($value): array
    {
        if (is_array($value)) {
            $items = $value;
        } elseif (is_scalar($value) || (is_object($value) && method_exists($value, '__toString'))) {
            $candidate = trim((string)$value);
            $items = $candidate === '' ? [] : (preg_split('/[\s,]+/', $candidate) ?: []);
        } else {
            return [];
        }

        $result = [];
        $seen = [];
        foreach ($items as $item) {
            $item = trim((string)$item);
            if ($item === '') {
                continue;
            }
            $key = strtolower($item);
            if (!isset($seen[$key])) {
                $seen[$key] = true;
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
