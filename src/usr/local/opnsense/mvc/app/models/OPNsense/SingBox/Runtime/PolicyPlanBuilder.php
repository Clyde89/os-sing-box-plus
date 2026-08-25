<?php

namespace OPNsense\SingBox\Runtime;

require_once __DIR__ . '/PolicyPlanValidator.php';

final class PolicyPlanBuilder
{
    private const SCHEMA_VERSION = 2;
    private const MANAGED_BY = 'os-sing-box-plus';

    public static function build(
        string $captureMode,
        array $captureInterfaces,
        array $compiledClients,
        array $compiledDomains,
        string $dnsListenAddress,
        int $dnsListenPort,
        string $fakeIpRange,
        string $tunInterface,
        string $tunAddress,
        string $policyOutboundMode = 'direct_bind',
        string $policyBindAddress = '',
        string $policyGateway = ''
    ): array {
        if (!in_array($captureMode, ['selected', 'all_lan'], true)) {
            throw new \InvalidArgumentException('Неподдерживаемый режим захвата policy-плана.');
        }
        if ($policyOutboundMode !== 'direct_bind') {
            throw new \InvalidArgumentException('Неподдерживаемый режим policy outbound.');
        }

        $domains = is_array($compiledDomains['domain'] ?? null) ? $compiledDomains['domain'] : [];
        $suffixes = is_array($compiledDomains['domain_suffix'] ?? null) ? $compiledDomains['domain_suffix'] : [];
        $policyRequired = $domains !== [] || $suffixes !== [];
        $interfacesReady = !$policyRequired || $captureInterfaces !== [];
        $selectedClientsReady = $captureMode === 'all_lan' || $compiledClients !== [];

        $dnsRedirect = [
            'required' => $policyRequired,
            'ready' => !$policyRequired || ($interfacesReady && $selectedClientsReady),
            'interfaces' => $captureInterfaces,
            'protocols' => ['udp', 'tcp'],
            'destination_port' => 53,
            'source_ip_cidr' => $captureMode === 'selected' ? $compiledClients : [],
            'target_address' => $dnsListenAddress,
            'target_port' => $dnsListenPort,
            'scope' => $captureMode,
        ];

        $fakeIpRoute = [
            'required' => $policyRequired,
            'ready' => !$policyRequired || ($fakeIpRange !== '' && $tunInterface !== ''),
            'network' => $fakeIpRange,
            'interface' => $tunInterface,
            'mode' => $policyRequired ? 'sing_box_auto_route' : 'not_required',
        ];

        $policyOutboundReady = !$policyRequired || ($policyBindAddress !== '' && $policyGateway !== '');
        $policyOutbound = [
            'required' => $policyRequired,
            'ready' => $policyOutboundReady,
            'mode' => $policyRequired ? $policyOutboundMode : 'not_required',
            'tag' => $policyRequired ? 'policy-out' : null,
            'bind_address' => $policyRequired ? $policyBindAddress : null,
            'gateway' => $policyRequired ? $policyGateway : null,
            'fail_closed' => $policyRequired,
        ];

        $operations = [];
        if ($policyRequired && $dnsRedirect['ready']) {
            foreach ($captureInterfaces as $interface) {
                foreach ($dnsRedirect['protocols'] as $protocol) {
                    $operations[] = [
                        'id' => 'dns-redirect-' . $interface . '-' . $protocol,
                        'type' => 'dns_redirect',
                        'interface' => $interface,
                        'protocol' => $protocol,
                        'source_ip_cidr' => $dnsRedirect['source_ip_cidr'],
                        'destination_port' => $dnsRedirect['destination_port'],
                        'target_address' => $dnsRedirect['target_address'],
                        'target_port' => $dnsRedirect['target_port'],
                        'scope' => $captureMode,
                    ];
                }
            }
        }

        if ($policyRequired && $policyOutbound['ready']) {
            $operations[] = [
                'id' => 'policy-outbound-route',
                'type' => 'policy_route',
                'source_address' => $policyBindAddress,
                'gateway' => $policyGateway,
            ];
            $operations[] = [
                'id' => 'policy-outbound-block',
                'type' => 'policy_block',
                'source_address' => $policyBindAddress,
            ];
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'managed_by' => self::MANAGED_BY,
            'required' => $policyRequired,
            'ready' => $dnsRedirect['ready'] && $fakeIpRoute['ready'] && $policyOutbound['ready'],
            'confirmation_required' => $policyRequired && $captureMode === 'all_lan',
            'capture_mode' => $captureMode,
            'capture_interfaces' => $captureInterfaces,
            'source_ip_cidr' => $compiledClients,
            'domain' => $domains,
            'domain_suffix' => $suffixes,
            'dns_listener' => [
                'address' => $dnsListenAddress,
                'port' => $dnsListenPort,
            ],
            'dns_redirect' => $dnsRedirect,
            'fakeip_route' => $fakeIpRoute,
            'policy_outbound' => $policyOutbound,
            'tun_interface' => $tunInterface,
            'tun_address' => $tunAddress,
            'fakeip_ipv4_range' => $fakeIpRange,
            'dns_query_types' => ['A'],
            'requires_opnsense_dns_redirect' => $policyRequired,
            'requires_opnsense_fakeip_route' => false,
            'requires_singbox_fakeip_route' => $policyRequired,
            'requires_opnsense_policy_route' => $policyRequired,
            'requires_policy_outbound' => $policyRequired,
            'operations' => $operations,
        ];
    }
}
