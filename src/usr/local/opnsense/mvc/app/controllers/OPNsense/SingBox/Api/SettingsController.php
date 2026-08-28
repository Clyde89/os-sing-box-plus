<?php

namespace OPNsense\SingBox\Api;

use OPNsense\Base\ApiMutableModelControllerBase;
use OPNsense\Core\Backend;
use OPNsense\SingBox\Runtime\RuntimeConfigBuilder;

class SettingsController extends ApiMutableModelControllerBase
{
    protected static $internalModelName = 'settings';
    protected static $internalModelClass = '\OPNsense\SingBox\Settings';

    private const TARGET_CONFIG = '/usr/local/etc/sing-box/config.json';
    private const SETUP_REQUIRED_FILE = '/var/db/os-sing-box/setup-required';
    private const MANAGED_CONFIG_FILE = '/var/db/os-sing-box/managed-config';
    private const ADOPTION_APPROVAL_FILE = '/var/db/os-sing-box/adoption-approved';

    private function adoptionApprovalMatches(string $configPath): bool
    {
        if (!is_readable($configPath) || !is_readable(self::ADOPTION_APPROVAL_FILE)) {
            return false;
        }

        $approvedChecksum = trim((string)file_get_contents(self::ADOPTION_APPROVAL_FILE));
        $currentChecksum = hash_file('sha256', $configPath);
        return preg_match('/^[a-f0-9]{64}$/', $approvedChecksum) === 1
            && is_string($currentChecksum)
            && hash_equals($approvedChecksum, $currentChecksum);
    }

    private function runtimeOwnership(): array
    {
        $hasConfig = is_file(self::TARGET_CONFIG);
        $initialSetup = is_file(self::SETUP_REQUIRED_FILE);
        $managed = is_file(self::MANAGED_CONFIG_FILE);

        if ($initialSetup) {
            return ['state' => 'initial_setup', 'apply_allowed' => true, 'warning' => null];
        }
        if ($managed) {
            return ['state' => 'managed', 'apply_allowed' => true, 'warning' => null];
        }
        if (!$hasConfig) {
            return ['state' => 'empty', 'apply_allowed' => true, 'warning' => null];
        }
        if ($this->adoptionApprovalMatches(self::TARGET_CONFIG)) {
            return [
                'state' => 'adoption_ready',
                'apply_allowed' => true,
                'warning' => 'Переход подтверждён для текущей SHA-256. Следующее успешное применение включит управляемый режим.',
            ];
        }

        return [
            'state' => 'unmanaged_existing',
            'apply_allowed' => false,
            'warning' => 'Обнаружена существующая пользовательская runtime-конфигурация. Предварительный просмотр доступен, но применение структурированных настроек заблокировано до явного перехода в управляемый режим.',
        ];
    }

    private function runtimeNetworkPreflight(): array
    {
        try {
            $response = trim((new Backend())->configdRun('sing-box preflight'));
            if (!str_starts_with($response, 'OK ')) {
                return [
                    'ready' => false,
                    'errors' => [
                        $response !== ''
                            ? $response
                            : 'Backend не подтвердил сетевой preflight.',
                    ],
                ];
            }

            $decoded = json_decode(substr($response, 3), true);
            if (!is_array($decoded) || !is_bool($decoded['ready'] ?? null) || !is_array($decoded['errors'] ?? null)) {
                return [
                    'ready' => false,
                    'errors' => ['Backend вернул некорректный результат сетевого preflight.'],
                ];
            }

            return [
                'ready' => $decoded['ready'],
                'errors' => array_values(array_filter($decoded['errors'], 'is_string')),
            ];
        } catch (\Throwable $error) {
            return [
                'ready' => false,
                'errors' => ['Не удалось выполнить сетевой preflight через configd.'],
            ];
        }
    }

    public function previewAction()
    {
        if (!$this->request->isGet()) {
            return [
                'result' => 'failed',
                'message' => 'Предварительный просмотр доступен только через GET-запрос.',
            ];
        }

        try {
            $plan = RuntimeConfigBuilder::build($this->getModel()->getNodes());
            $config = RuntimeConfigBuilder::encodeConfig($plan);
            $ownership = $this->runtimeOwnership();
            $networkPreflight = $this->runtimeNetworkPreflight();
            $warnings = $plan['warnings'];

            if ($ownership['warning'] !== null) {
                $warnings[] = $ownership['warning'];
            }
            foreach ($networkPreflight['errors'] as $preflightError) {
                $warnings[] = $preflightError;
            }

            return [
                'result' => 'ok',
                'apply_ready' => $plan['apply_ready']
                    && $ownership['apply_allowed']
                    && $networkPreflight['ready'],
                'generation_ready' => $plan['apply_ready'],
                'network_preflight' => $networkPreflight,
                'management_state' => $ownership['state'],
                'warnings' => $warnings,
                'selectors' => $plan['selectors'],
                'policy_plan' => $plan['policy_plan'] ?? [],
                'policy_sha256' => $plan['policy_sha256'] ?? null,
                'dns_bootstrap' => $plan['dns_bootstrap'] ?? [],
                'config' => $config,
                'sha256' => hash('sha256', $config),
            ];
        } catch (\Throwable $error) {
            return [
                'result' => 'failed',
                'message' => 'Не удалось сформировать предварительную runtime-конфигурацию.',
            ];
        }
    }

    public function applyAction()
    {
        if (!$this->request->isPost()) {
            return [
                'result' => 'failed',
                'message' => 'Применение runtime-конфигурации доступно только через POST-запрос.',
            ];
        }

        try {
            $response = trim((new Backend())->configdRun('sing-box apply'));
            if (str_starts_with($response, 'OK ')) {
                return [
                    'result' => 'ok',
                    'message' => substr($response, 3),
                ];
            }

            return [
                'result' => 'failed',
                'message' => $response !== '' ? $response : 'Backend не подтвердил применение runtime-конфигурации.',
            ];
        } catch (\Throwable $error) {
            return [
                'result' => 'failed',
                'message' => 'Не удалось применить runtime-конфигурацию через configd.',
            ];
        }
    }

    public function adoptAction()
    {
        if (!$this->request->isPost()) {
            return [
                'result' => 'failed',
                'message' => 'Подтверждение перехода доступно только через POST-запрос.',
            ];
        }

        try {
            $response = trim((new Backend())->configdRun('sing-box approve-adoption'));
            if (str_starts_with($response, 'OK ')) {
                return [
                    'result' => 'ok',
                    'message' => substr($response, 3),
                ];
            }

            return [
                'result' => 'failed',
                'message' => $response !== '' ? $response : 'Backend не подтвердил безопасный переход.',
            ];
        } catch (\Throwable $error) {
            return [
                'result' => 'failed',
                'message' => 'Не удалось подтвердить переход через configd.',
            ];
        }
    }
}
