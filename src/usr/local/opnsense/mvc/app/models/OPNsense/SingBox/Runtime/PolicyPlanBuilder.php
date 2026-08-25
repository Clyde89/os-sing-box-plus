<?php

namespace OPNsense\SingBox\Runtime;

final class PolicyPlanBuilder
{
    private const SCHEMA_VERSION = 1;
    private const MANAGED_BY = 'os-sing-box-plus';

    public static function build(
        string $captureMode,
        array $compiledClients,
        array $compiledDomains,
        string $dnsListenAddress,
        int $dnsListenPort,
        string $fakeIpRange,
        string $tunInterface,
        string $tunAddress
    ): array {
        if (!in_array($captureMode, ['selected', 'all_lan'], true)) {
            throw new \InvalidArgumentException('Неподдерживаемый режим захвата policy-плана.');
        }

        $domains = is_array($compiledDomains['domain'] ?? null) ? $compiledDomains['domain'] : [];
        $suffixes = is_array($compiledDomains['domain_suffix'] ?? null) ? $compiledDomains['domain_suffix'] : [];
        $policyRequired = $domains !== [] || $suffixes !== [];
        $selectedClientsReady = $captureMode === 'all_lan' || $compiledClients !== [];

        $dnsRedirect = [
            'required' => $policyRequired,
            'ready' => !$policyRequired || $selectedClientsReady,
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
        ];

        $policyOutbound = [
            'required' => $policyRequired,
            'ready' => !$policyRequired,
            'mode' => $policyRequired ? 'unconfigured' : 'not_required',
        ];

        $operations = [];
        if ($policyRequired && $dnsRedirect['ready']) {
            foreach ($dnsRedirect['protocols'] as $protocol) {
                $operations[] = [
                    'id' => 'dns-redirect-' . $protocol,
                    'type' => 'dns_redirect',
                    'protocol' => $protocol,
                    'source_ip_cidr' => $dnsRedirect['source_ip_cidr'],
                    'destination_port' => $dnsRedirect['destination_port'],
                    'target_address' => $dnsRedirect['target_address'],
                    'target_port' => $dnsRedirect['target_port'],
                    'scope' => $captureMode,
                ];
            }
        }

        if ($policyRequired && $fakeIpRoute['ready']) {
            $operations[] = [
                'id' => 'fakeip-route-ipv4',
                'type' => 'route',
                'network' => $fakeIpRange,
                'interface' => $tunInterface,
            ];
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'managed_by' => self::MANAGED_BY,
            'required' => $policyRequired,
            'ready' => $dnsRedirect['ready'] && $fakeIpRoute['ready'] && $policyOutbound['ready'],
            'confirmation_required' => $policyRequired && $captureMode === 'all_lan',
            'capture_mode' => $captureMode,
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
            'requires_opnsense_fakeip_route' => $policyRequired,
            'requires_policy_outbound' => $policyRequired,
            'operations' => $operations,
        ];
    }
}
