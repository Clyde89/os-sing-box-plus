<?php

namespace OPNsense\SingBox\Api;

use OPNsense\Base\ApiMutableModelControllerBase;
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
}
