<?php

namespace OPNsense\SingBox;

class SettingsController extends \OPNsense\Base\IndexController
{
    public function indexAction()
    {
        $this->view->pick('OPNsense/SingBox/settings');
        $this->view->settingsForm = $this->getForm('settings');
    }
}
