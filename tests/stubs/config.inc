<?php

$config = [
    'interfaces' => [
        'lan' => [
            'if' => 'igc0',
            'descr' => 'LAN',
        ],
    ],
    'filter' => [
        'rule' => [],
    ],
];

$currentConfigFile = getenv('MIGRATION_TEST_CURRENT_CONFIG');
if ($currentConfigFile !== false && $currentConfigFile !== '' && is_readable($currentConfigFile)) {
    $content = file_get_contents($currentConfigFile);
    $decoded = $content === false ? null : json_decode($content, true);
    if (is_array($decoded)) {
        $config = $decoded;
    }
}

function load_config_from_file($filename)
{
    $content = file_get_contents($filename);
    return $content === false ? null : json_decode($content, true);
}

function &config_read_array()
{
    global $config;

    $current = &$config;
    foreach (func_get_args() as $key) {
        if (!isset($current[$key]) || !is_array($current[$key])) {
            $current[$key] = [];
        }
        $current = &$current[$key];
    }

    return $current;
}

function write_config($description = '', $backup = true)
{
    global $config;

    $output = getenv('MIGRATION_TEST_OUTPUT');
    if ($output !== false && $output !== '') {
        file_put_contents($output, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    return $config;
}
