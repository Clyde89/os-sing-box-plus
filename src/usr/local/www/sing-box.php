<?php

/*
 * Copyright (C) 2014-2026 Deciso B.V.
 * Copyright (C) 2010 Erik Fonnesbeck
 * Copyright (C) 2008-2010 Ermal Luči
 * Copyright (C) 2004-2008 Scott Ullrich <sullrich@gmail.com>
 * Copyright (C) 2006 Daniel S. Haischt
 * Copyright (C) 2003-2004 Manuel Kasper <mk@neon1.net>
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *
 * 1. Redistributions of source code must retain the above copyright notice,
 *    this list of conditions and the following disclaimer.
 *
 * 2. Redistributions in binary form must reproduce the above copyright
 *    notice, this list of conditions and the following disclaimer in the
 *    documentation and/or other materials provided with the distribution.
 *
 * THIS SOFTWARE IS PROVIDED ``AS IS'' AND ANY EXPRESS OR IMPLIED WARRANTIES,
 * INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY
 * AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
 * AUTHOR BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY,
 * OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
 * SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
 * INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
 * CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
 * ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 * POSSIBILITY OF SUCH DAMAGE.
 */

require_once("guiconfig.inc");

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

const SINGBOX_CONFIG_FILE = '/usr/local/etc/sing-box/config.json';
const SINGBOX_BINARY = '/usr/local/bin/sing-box';
const CONFIGCTL_BINARY = '/usr/local/sbin/configctl';
const SETUP_REQUIRED_FILE = '/var/db/os-sing-box/setup-required';
const STATUS_ENDPOINT = '/sing-box.php?ajax=status';
const LOGS_ENDPOINT = '/sing-box_log.php';
const CSRF_TOKEN_KEY = 'sing_box_service_csrf_token';
const MAX_CONFIG_SIZE = 4194304;

$message = '';
$message_type = 'info';

function h($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function generateCsrfToken()
{
    if (empty($_SESSION[CSRF_TOKEN_KEY])) {
        $_SESSION[CSRF_TOKEN_KEY] = bin2hex(random_bytes(32));
    }
}

function getCsrfToken()
{
    return $_SESSION[CSRF_TOKEN_KEY] ?? '';
}

function verifyCsrfToken($token)
{
    $sessionToken = $_SESSION[CSRF_TOKEN_KEY] ?? '';
    return is_string($token) && $sessionToken !== '' && hash_equals($sessionToken, $token);
}

function runCommand($command)
{
    $output = [];
    $status = 0;
    exec($command . ' 2>&1', $output, $status);
    return [$output, $status];
}

function setupRequired()
{
    return is_file(SETUP_REQUIRED_FILE);
}

function finishInitialSetup()
{
    if (!setupRequired()) {
        return true;
    }

    return @unlink(SETUP_REQUIRED_FILE);
}

function configdAction($action)
{
    if (!is_executable(CONFIGCTL_BINARY)) {
        return [[], 127];
    }

    $command = escapeshellarg(CONFIGCTL_BINARY) . ' sing-box ' . escapeshellarg($action);
    return runCommand($command);
}

function readConfig()
{
    if (!is_file(SINGBOX_CONFIG_FILE)) {
        return '';
    }

    $content = file_get_contents(SINGBOX_CONFIG_FILE);
    return $content === false ? '' : $content;
}

function validateJson($content)
{
    json_decode($content, true);
    return json_last_error() === JSON_ERROR_NONE ? null : json_last_error_msg();
}

function validateSingBoxConfig($path)
{
    if (!is_executable(SINGBOX_BINARY)) {
        return [false, 'Исполняемый файл sing-box отсутствует или недоступен.'];
    }

    $command = escapeshellarg(SINGBOX_BINARY) . ' check -c ' . escapeshellarg($path);
    [$output, $status] = runCommand($command);
    $details = trim(implode("\n", $output));

    if ($status === 0) {
        return [true, $details];
    }

    return [false, $details !== '' ? $details : 'Проверка конфигурации sing-box завершилась ошибкой.'];
}

function saveConfig($content)
{
    if (trim($content) === '') {
        return [false, 'Конфигурация не может быть пустой.'];
    }

    if (strlen($content) > MAX_CONFIG_SIZE) {
        return [false, 'Размер конфигурации превышает 4 МиБ.'];
    }

    $jsonError = validateJson($content);
    if ($jsonError !== null) {
        return [false, 'Некорректный JSON: ' . $jsonError];
    }

    $directory = dirname(SINGBOX_CONFIG_FILE);
    if (!is_dir($directory) || !is_writable($directory)) {
        return [false, 'Каталог конфигурации недоступен для записи.'];
    }

    $tempFile = tempnam($directory, '.singbox_cfg_');
    if ($tempFile === false) {
        return [false, 'Не удалось создать временный файл конфигурации.'];
    }

    try {
        if (file_put_contents($tempFile, $content, LOCK_EX) === false) {
            return [false, 'Не удалось записать временный файл конфигурации.'];
        }

        if (!@chmod($tempFile, 0600)) {
            return [false, 'Не удалось установить безопасные права на временный файл конфигурации.'];
        }

        [$valid, $validationMessage] = validateSingBoxConfig($tempFile);
        if (!$valid) {
            return [false, 'Конфигурация не прошла проверку sing-box: ' . $validationMessage];
        }

        $backupFile = SINGBOX_CONFIG_FILE . '.bak';
        if (is_file(SINGBOX_CONFIG_FILE)) {
            if (!@copy(SINGBOX_CONFIG_FILE, $backupFile)) {
                return [false, 'Не удалось создать резервную копию текущей конфигурации.'];
            }

            if (!@chmod($backupFile, 0600)) {
                @unlink($backupFile);
                return [false, 'Не удалось установить безопасные права на резервную копию конфигурации.'];
            }
        }

        if (!@rename($tempFile, SINGBOX_CONFIG_FILE)) {
            return [false, 'Не удалось атомарно заменить конфигурацию.'];
        }

        $tempFile = '';
        if (!@chmod(SINGBOX_CONFIG_FILE, 0600)) {
            return [false, 'Конфигурация сохранена, но не удалось подтвердить безопасные права файла.'];
        }

        if (!finishInitialSetup()) {
            return [false, 'Конфигурация сохранена, но не удалось завершить первоначальную настройку.'];
        }

        return [true, 'Конфигурация проверена и сохранена.'];
    } finally {
        if ($tempFile !== '') {
            @unlink($tempFile);
        }
    }
}

function serviceEnabled()
{
    [$output, $status] = configdAction('enabled');
    if ($status !== 0) {
        return null;
    }

    $value = trim(implode("\n", $output));
    if ($value === 'YES') {
        return true;
    }
    if ($value === 'NO') {
        return false;
    }

    return null;
}

function serviceEnableAction($enable)
{
    if ($enable && setupRequired()) {
        return [false, 'Первоначальная настройка sing-box не завершена. Сохраните проверенную конфигурацию перед включением автозапуска.'];
    }

    $action = $enable ? 'enable' : 'disable';
    [$output, $status] = configdAction($action);
    $details = trim(implode("\n", $output));

    if ($status === 0) {
        return [true, $enable ? 'Автозапуск sing-box включён.' : 'Автозапуск sing-box отключён.'];
    }

    $message = $enable ? 'Не удалось включить автозапуск sing-box.' : 'Не удалось отключить автозапуск sing-box.';
    return [false, $message . ($details !== '' ? "\n" . $details : '')];
}

function serviceAction($action)
{
    $messages = [
        'start' => ['Служба sing-box запущена.', 'Не удалось запустить службу sing-box.'],
        'stop' => ['Служба sing-box остановлена.', 'Не удалось остановить службу sing-box.'],
        'restart' => ['Служба sing-box перезапущена.', 'Не удалось перезапустить службу sing-box.'],
    ];

    if (!isset($messages[$action])) {
        return [false, 'Недопустимое действие.'];
    }

    if (($action === 'start' || $action === 'restart') && setupRequired()) {
        return [false, 'Первоначальная настройка sing-box не завершена. Сохраните проверенную конфигурацию перед запуском службы.'];
    }

    if ($action === 'start' || $action === 'restart') {
        $enabled = serviceEnabled();
        if ($enabled === null) {
            return [false, 'Не удалось определить состояние автозапуска sing-box.'];
        }
        if (!$enabled) {
            return [false, 'Автозапуск sing-box отключён. Включите службу перед запуском или перезапуском.'];
        }
    }

    [$output, $status] = configdAction($action);
    if ($status === 0) {
        return [true, $messages[$action][0]];
    }

    $details = trim(implode("\n", $output));
    return [false, $messages[$action][1] . ($details !== '' ? "\n" . $details : '')];
}

function serviceStatus()
{
    [, $status] = configdAction('status');
    if ($status === 0) {
        return 'running';
    }
    if ($status === 1) {
        return 'stopped';
    }

    return 'error';
}

function clearLog()
{
    [$output, $status] = configdAction('clearlog');
    $details = trim(implode("\n", $output));

    if ($status === 0) {
        return [true, $details !== '' ? $details : 'Журнал sing-box очищен.'];
    }

    return [false, 'Не удалось очистить журнал sing-box.' . ($details !== '' ? "\n" . $details : '')];
}

generateCsrfToken();

if (isset($_GET['ajax']) && $_GET['ajax'] === 'status') {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(
        [
            'status' => serviceStatus(),
            'enabled' => serviceEnabled(),
            'setup_required' => setupRequired(),
        ],
        JSON_UNESCAPED_UNICODE
    );
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $message = 'Проверка CSRF не пройдена. Обновите страницу и повторите действие.';
        $message_type = 'danger';
    } else {
        $action = trim((string)($_POST['action'] ?? ''));

        switch ($action) {
            case 'save_config':
                [$success, $message] = saveConfig((string)($_POST['config_content'] ?? ''));
                $message_type = $success ? 'success' : 'danger';
                break;
            case 'enable_service':
                [$success, $message] = serviceEnableAction(true);
                $message_type = $success ? 'success' : 'danger';
                break;
            case 'disable_service':
                [$success, $message] = serviceEnableAction(false);
                $message_type = $success ? 'success' : 'danger';
                break;
            case 'start':
            case 'stop':
            case 'restart':
                [$success, $message] = serviceAction($action);
                $message_type = $success ? 'success' : 'danger';
                break;
            case 'clear_log':
                [$success, $message] = clearLog();
                $message_type = $success ? 'success' : 'danger';
                break;
            default:
                $message = 'Недопустимое действие.';
                $message_type = 'danger';
                break;
        }
    }
}

$configContent = readConfig();
$csrfToken = getCsrfToken();
$setupRequired = setupRequired();
$serviceEnabled = serviceEnabled();
$serviceStateKnown = is_bool($serviceEnabled);

if ($configContent === '' && !is_file(SINGBOX_CONFIG_FILE) && $message === '') {
    $message = 'Рабочая конфигурация отсутствует. Сохраните конфигурацию перед запуском службы.';
    $message_type = 'warning';
}

include("head.inc");
include("fbegin.inc");
?>

<style>
    .singbox-title {
        padding: 12px 14px;
        border-bottom: 1px solid #eeeeee;
        font-size: 14px;
        font-weight: 600;
    }

    .singbox-body {
        padding: 14px;
    }

    .singbox-editor,
    .singbox-log {
        max-width: none;
        font-family: Menlo, Monaco, Consolas, "Courier New", monospace;
        font-size: 14px;
        line-height: 1.3;
        white-space: pre;
        overflow-wrap: normal;
        resize: vertical;
    }

    .singbox-toolbar .btn {
        margin-right: 4px;
        margin-bottom: 4px;
    }

    .singbox-status {
        margin-bottom: 0;
        padding: 12px 18px;
    }

    .singbox-status-title {
        font-weight: 600;
        margin-left: 8px;
        margin-right: 12px;
    }

    .singbox-help {
        margin-top: 8px;
        margin-bottom: 0;
        color: #777777;
    }

    .singbox-autostart {
        margin-top: 10px;
        margin-bottom: 0;
    }
</style>

<section class="page-content-main">
    <div class="container-fluid">
        <div class="row">
            <?php if ($message !== ''): ?>
                <div class="col-xs-12">
                    <div class="alert alert-<?= h($message_type); ?>">
                        <pre style="margin:0;border:0;background:transparent;padding:0;white-space:pre-wrap;word-break:break-word;"><?= h($message); ?></pre>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($setupRequired): ?>
                <div class="col-xs-12">
                    <div class="alert alert-warning">
                        <strong>Требуется первоначальная настройка.</strong>
                        Проверьте параметры конфигурации и сохраните её. До завершения настройки запуск, перезапуск и включение автозапуска sing-box заблокированы.
                    </div>
                </div>
            <?php endif; ?>

            <section class="col-xs-12">
                <div class="content-box">
                    <div class="singbox-title"><i class="fa fa-heartbeat text-muted"></i> Состояние службы</div>
                    <div class="singbox-body">
                        <div id="sing-box-status" class="alert alert-warning singbox-status">
                            <i class="fa fa-refresh fa-spin"></i>
                            <span class="singbox-status-title">Проверка...</span>
                            <span>Получение состояния sing-box</span>
                        </div>
                        <p id="sing-box-autostart" class="singbox-autostart text-muted">
                            Автозапуск:
                            <?php if ($serviceEnabled === true): ?>
                                <strong>включён</strong>
                            <?php elseif ($serviceEnabled === false): ?>
                                <strong>отключён</strong>
                            <?php else: ?>
                                <strong>состояние неизвестно</strong>
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
            </section>

            <section class="col-xs-12">
                <div class="content-box">
                    <div class="singbox-title"><i class="fa fa-sliders text-muted"></i> Управление службой</div>
                    <div class="singbox-body">
                        <form method="post" class="form-inline singbox-toolbar">
                            <input type="hidden" name="csrf_token" value="<?= h($csrfToken); ?>">
                            <button type="submit" id="enable-autostart" name="action" value="enable_service" class="btn btn-primary"<?= ($setupRequired || $serviceEnabled !== false) ? ' disabled' : ''; ?>><i class="fa fa-toggle-on"></i> Включить автозапуск</button>
                            <button type="submit" id="disable-autostart" name="action" value="disable_service" class="btn btn-default"<?= $serviceEnabled !== true ? ' disabled' : ''; ?>><i class="fa fa-toggle-off"></i> Отключить автозапуск</button>
                            <button type="submit" name="action" value="start" class="btn btn-success"<?= ($setupRequired || $serviceEnabled !== true) ? ' disabled' : ''; ?>><i class="fa fa-play"></i> Запустить</button>
                            <button type="submit" name="action" value="stop" class="btn btn-danger"><i class="fa fa-stop"></i> Остановить</button>
                            <button type="submit" name="action" value="restart" class="btn btn-warning"<?= ($setupRequired || $serviceEnabled !== true) ? ' disabled' : ''; ?>><i class="fa fa-refresh"></i> Перезапустить</button>
                        </form>
                        <p class="singbox-help">Включение автозапуска разрешается только после успешного сохранения проверенной конфигурации.</p>
                    </div>
                </div>
            </section>

            <section class="col-xs-12">
                <div class="content-box">
                    <div class="singbox-title"><i class="fa fa-file-code-o text-muted"></i> Конфигурация</div>
                    <div class="singbox-body">
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= h($csrfToken); ?>">
                            <textarea id="config_content" name="config_content" rows="16" spellcheck="false" autocapitalize="off" autocomplete="off" autocorrect="off" class="form-control singbox-editor"><?= h($configContent); ?></textarea>
                            <p class="singbox-help">Перед сохранением проверяются синтаксис JSON и команда <code>sing-box check</code>. Текущая конфигурация сохраняется в резервную копию с правами <code>0600</code>.</p>
                            <br>
                            <button type="submit" name="action" value="save_config" class="btn btn-primary"><i class="fa fa-save"></i> Проверить и сохранить</button>
                        </form>
                    </div>
                </div>
            </section>

            <section class="col-xs-12">
                <div class="content-box">
                    <div class="singbox-title"><i class="fa fa-file-text text-muted"></i> Журнал</div>
                    <div class="singbox-body">
                        <form method="post" class="form-inline singbox-toolbar">
                            <input type="hidden" name="csrf_token" value="<?= h($csrfToken); ?>">
                            <button type="submit" name="action" value="clear_log" class="btn btn-default"><i class="fa fa-trash"></i> Очистить журнал</button>
                        </form>
                        <br>
                        <textarea id="log-viewer" rows="14" class="form-control singbox-log" readonly></textarea>
                    </div>
                </div>
            </section>
        </div>
    </div>
</section>

<script>
    const STATUS_ENDPOINT = <?= json_encode(STATUS_ENDPOINT, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
    const LOGS_ENDPOINT = <?= json_encode(LOGS_ENDPOINT, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;

    function setStatus(state, setupRequired, enabled) {
        const element = document.getElementById('sing-box-status');
        const states = {
            running: {
                className: 'alert alert-success singbox-status',
                html: '<i class="fa fa-check-circle text-success"></i> <span class="singbox-status-title">sing-box запущен</span><span>Служба работает</span>'
            },
            stopped: {
                className: 'alert alert-danger singbox-status',
                html: '<i class="fa fa-times-circle text-danger"></i> <span class="singbox-status-title">sing-box остановлен</span><span>Служба не работает</span>'
            },
            error: {
                className: 'alert alert-warning singbox-status',
                html: '<i class="fa fa-exclamation-circle text-warning"></i> <span class="singbox-status-title">Состояние неизвестно</span><span>Не удалось получить состояние службы</span>'
            }
        };

        const next = states[state] || states.error;
        element.className = next.className;
        element.innerHTML = next.html;

        if (setupRequired) {
            element.className = 'alert alert-warning singbox-status';
            element.innerHTML = '<i class="fa fa-wrench text-warning"></i> <span class="singbox-status-title">Требуется настройка</span><span>Служба не будет запущена до сохранения проверенной конфигурации</span>';
        }

        const autostart = document.getElementById('sing-box-autostart');
        if (enabled === true) {
            autostart.innerHTML = 'Автозапуск: <strong>включён</strong>';
        } else if (enabled === false) {
            autostart.innerHTML = 'Автозапуск: <strong>отключён</strong>';
        } else {
            autostart.innerHTML = 'Автозапуск: <strong>состояние неизвестно</strong>';
        }

        const enableButton = document.getElementById('enable-autostart');
        const disableButton = document.getElementById('disable-autostart');
        if (enableButton) {
            enableButton.disabled = setupRequired || enabled !== false;
        }
        if (disableButton) {
            disableButton.disabled = enabled !== true;
        }
    }

    function refreshStatus() {
        fetch(STATUS_ENDPOINT, {cache: 'no-store'})
            .then(response => response.json())
            .then(data => setStatus(data.status, data.setup_required === true, data.enabled))
            .catch(() => setStatus('error', false, null));
    }

    function refreshLogs() {
        fetch(LOGS_ENDPOINT, {cache: 'no-store'})
            .then(response => response.text())
            .then(content => {
                const viewer = document.getElementById('log-viewer');
                const stickToBottom = viewer.scrollTop + viewer.clientHeight >= viewer.scrollHeight - 20;
                viewer.value = content;
                if (stickToBottom) {
                    viewer.scrollTop = viewer.scrollHeight;
                }
            })
            .catch(() => {
                document.getElementById('log-viewer').value = '[Ошибка] Не удалось загрузить журнал sing-box.';
            });
    }

    document.addEventListener('DOMContentLoaded', () => {
        refreshStatus();
        refreshLogs();
        setInterval(refreshStatus, 3000);
        setInterval(refreshLogs, 5000);
    });
</script>

<?php include("foot.inc"); ?>
