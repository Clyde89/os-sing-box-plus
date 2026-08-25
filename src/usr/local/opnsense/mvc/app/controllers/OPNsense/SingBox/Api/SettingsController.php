<?php

namespace OPNsense\SingBox\Api;

use OPNsense\Base\ApiMutableModelControllerBase;

class SettingsController extends ApiMutableModelControllerBase
{
    protected static $internalModelName = 'settings';
    protected static $internalModelClass = '\OPNsense\SingBox\Settings';
}
