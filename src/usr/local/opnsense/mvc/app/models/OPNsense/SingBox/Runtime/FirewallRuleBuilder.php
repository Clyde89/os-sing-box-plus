<?php

namespace OPNsense\SingBox\Runtime;

final class FirewallRuleBuilder
{
    public static function build(array $plan): array
    {
        PolicyPlanValidator::assertValid($plan);

        if (($plan['required'] ?? false) !== true) {
            return [
                'destination_nat' => [],
                'filter' => [],
            ];
        }
        if (($plan['ready'] ?? false) !== true) {
            throw new \RuntimeException('Неготовый policy-план не может быть преобразован в правила OPNsense.');
        }
        if (($plan['confirmation_required'] ?? false) === true) {
            throw new \RuntimeException('Policy-план all_lan требует отдельного подтверждения перед генерацией правил OPNsense.');
        }

        $destinationNat = [];
        $filter = [];

        foreach ($plan['operations'] as $operation) {
            switch ($operation['type']) {
                case 'dns_redirect':
                    foreach (self::dnsRedirectRules($operation) as $rule) {
                        $destinationNat[] = $rule;
                    }
                    break;
                case 'policy_route':
                    $filter[] = self::policyRouteRule($operation);
                    break;
                case 'policy_block':
                    $filter[] = self::policyBlockRule($operation);
                    break;
            }
        }

        return [
            'destination_nat' => $destinationNat,
            'filter' => $filter,
        ];
    }

    private static function dnsRedirectRules(array $operation): array
    {
        $sources = $operation['source_ip_cidr'];
        if ($sources === []) {
            $sources = ['any'];
        }

        $rules = [];
        foreach ($sources as $index => $source) {
            $rules[] = [
                '#priority' => 2,
                '#operation' => $operation['id'],
                'interface' => $operation['interface'],
                'nordr' => false,
                'pass' => true,
                'ipprotocol' => 'inet',
                'protocol' => $operation['protocol'],
                'from' => $source,
                'to' => 'any',
                'to_port' => (string)$operation['destination_port'],
                'target' => $operation['target_address'],
                'localport' => (string)$operation['target_port'],
                'natreflection' => 'disable',
                'descr' => 'sing-box: перенаправление DNS [' . $operation['id'] . ':' . ($index + 1) . ']',
                '#ref' => 'ui/singbox/settings',
            ];
        }

        return $rules;
    }

    private static function policyRouteRule(array $operation): array
    {
        return [
            '#priority' => 2,
            '#operation' => $operation['id'],
            'type' => 'pass',
            'direction' => 'out',
            'quick' => true,
            'ipprotocol' => 'inet',
            'from' => $operation['source_address'],
            'to' => 'any',
            'gateway' => $operation['gateway'],
            'statetype' => 'keep state',
            'disablereplyto' => true,
            'skip_rules_gw_down' => true,
            'descr' => 'sing-box: policy route через выбранный gateway',
            '#ref' => 'ui/singbox/settings',
        ];
    }

    private static function policyBlockRule(array $operation): array
    {
        return [
            '#priority' => 3,
            '#operation' => $operation['id'],
            'type' => 'block',
            'direction' => 'out',
            'quick' => true,
            'ipprotocol' => 'inet',
            'from' => $operation['source_address'],
            'to' => 'any',
            'descr' => 'sing-box: fail-closed для policy source',
            '#ref' => 'ui/singbox/settings',
        ];
    }
}
