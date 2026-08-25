<?php

namespace OPNsense\SingBox\Api;

use OPNsense\Base\ApiMutableModelControllerBase;
use OPNsense\Core\Backend;
use OPNsense\SingBox\Runtime\RuntimeConfigBuilder;

class SettingsController extends ApiMutableModelControllerBase
{
    protected static $internalModelName = 'settings';
    protected static $internalModelClass = '\OPNsense\SingBox\Settings';

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

            return [
                'result' => 'ok',
                'apply_ready' => $plan['apply_ready'],
                'warnings' => $plan['warnings'],
                'selectors' => $plan['selectors'],
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
