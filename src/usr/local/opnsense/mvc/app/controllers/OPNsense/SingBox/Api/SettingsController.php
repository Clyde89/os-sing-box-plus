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

        return [
            'state' => 'unmanaged_existing',
            'apply_allowed' => false,
            'warning' => 'Обнаружена существующая пользовательская runtime-конфигурация. Предварительный просмотр доступен, но применение структурированных настроек заблокировано до явного перехода в управляемый режим.',
        ];
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
            $warnings = $plan['warnings'];

            if ($ownership['warning'] !== null) {
                $warnings[] = $ownership['warning'];
            }

            return [
                'result' => 'ok',
                'apply_ready' => $plan['apply_ready'] && $ownership['apply_allowed'],
                'generation_ready' => $plan['apply_ready'],
                'management_state' => $ownership['state'],
                'warnings' => $warnings,
                'selectors' => $plan['selectors'],
                'policy_plan' => $plan['policy_plan'] ?? [],
                'policy_sha256' => $plan['policy_sha256'] ?? null,
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
}
