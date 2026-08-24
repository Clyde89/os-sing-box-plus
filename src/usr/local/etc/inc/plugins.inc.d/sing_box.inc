<?php

/*
 * Copyright (C) 2024 https://pfchina.org/
 * All rights reserved.
 */

function sing_box_is_enabled()
{
    $path = '/etc/rc.conf.d/sing_box';
    if (!is_readable($path)) {
        return false;
    }

    $content = file_get_contents($path);
    return is_string($content)
        && preg_match('/^\s*sing_box_enable\s*=\s*"YES"\s*$/m', $content) === 1;
}

function sing_box_services()
{
    return [
        [
            'description' => gettext('sing-box'),
            'configd' => [
                'restart' => ['sing-box restart'],
                'start' => ['sing-box start'],
                'stop' => ['sing-box stop'],
            ],
            'name' => 'sing-box',
            'pidfile' => '/var/run/sing-box.pid',
            'enabled' => sing_box_is_enabled(),
            'rcfile' => '/usr/local/etc/rc.d/sing-box',
        ],
    ];
}

function sing_box_configure()
{
    return [
        'newwanip' => ['sing_box_newwanip:3'],
    ];
}

function sing_box_newwanip($verbose = false, $interface_map = null, $family = null)
{
    if ($family !== 'inet' || !sing_box_is_enabled()) {
        return;
    }

    if (!is_array($interface_map) || count($interface_map) === 0) {
        return;
    }

    $interfaces = implode(',', $interface_map);
    mwexecfb(
        '/usr/local/sbin/sing-box-readiness --event %s --interfaces %s',
        ['newwanip', $interfaces]
    );
}
