<script type="text/javascript">
    $(document).ready(function() {
        const previewEndpoint = '/api/singbox/settings/preview';
        const setEndpoint = '/api/singbox/settings/set';

        function showMessage(level, text) {
            const box = $('#singboxMessage');
            box.removeClass('hidden alert-info alert-success alert-warning alert-danger')
                .addClass('alert alert-' + level)
                .text(text);
        }

        function renderPreview(data) {
            if (!data || data.result !== 'ok') {
                $('#runtimePreview').text('');
                $('#runtimeSha').text('');
                $('#runtimeWarnings').addClass('hidden').text('');
                $('#applyAct').prop('disabled', true);
                showMessage('danger', data && data.message ? data.message : 'Не удалось сформировать предварительную конфигурацию.');
                return;
            }

            $('#runtimePreview').text(data.config || '');
            $('#runtimeSha').text(data.sha256 ? 'SHA-256: ' + data.sha256 : '');

            if (Array.isArray(data.warnings) && data.warnings.length > 0) {
                $('#runtimeWarnings').removeClass('hidden').text(data.warnings.join('\n'));
            } else {
                $('#runtimeWarnings').addClass('hidden').text('');
            }

            const ready = data.apply_ready === true;
            $('#applyAct').prop('disabled', !ready);
            showMessage(
                ready ? 'success' : 'warning',
                ready
                    ? 'Предварительная runtime-конфигурация сформирована и готова к применению.'
                    : 'Настройки сохранены, но runtime-конфигурация пока не готова к применению. Проверьте предупреждения.'
            );
        }

        function refreshPreview() {
            $('#applyAct').prop('disabled', true);
            $.getJSON(previewEndpoint)
                .done(renderPreview)
                .fail(function() {
                    renderPreview({result: 'failed', message: 'Не удалось получить предварительную runtime-конфигурацию через API.'});
                });
        }

        mapDataToFormUI({'frmSettings': '/api/singbox/settings/get'}).done(function() {
            $('.selectpicker').selectpicker('refresh');
        });

        $('#saveAct').click(function() {
            $('#applyAct').prop('disabled', true);
            saveFormToEndpoint(setEndpoint, 'frmSettings', function() {
                showMessage('success', 'Настройки сохранены. Выполняется проверка предварительной runtime-конфигурации.');
                refreshPreview();
            });
        });

        $('#previewAct').click(function() {
            refreshPreview();
        });

        $('#applyAct').SimpleActionButton({
            onAction: function(data) {
                if (data && data.result === 'ok') {
                    showMessage('success', data.message || 'Runtime-конфигурация успешно применена.');
                } else {
                    showMessage('danger', data && data.message ? data.message : 'Не удалось применить runtime-конфигурацию.');
                }
            }
        });
    });
</script>

<div class="alert alert-info" role="alert">
    <strong>Структурированные настройки sing-box.</strong>
    Сохранение параметров не изменяет рабочий <code>config.json</code>. Перед применением можно просмотреть сгенерированную runtime-конфигурацию и предупреждения.
</div>

<div id="singboxMessage" class="alert alert-info hidden" role="alert"></div>

<div class="col-md-12">
    {{ partial("layout_partials/base_form", ['fields': settingsForm, 'id': 'frmSettings']) }}
</div>

<div class="col-md-12">
    <button class="btn btn-primary" id="saveAct" type="button">
        <i class="fa fa-save"></i> Сохранить настройки
    </button>
    <button class="btn btn-default" id="previewAct" type="button">
        <i class="fa fa-eye"></i> Предварительный просмотр
    </button>
    <button class="btn btn-success" id="applyAct" type="button"
        data-endpoint="/api/singbox/settings/apply"
        data-label="Применить runtime-конфигурацию"
        data-error-title="Ошибка применения runtime-конфигурации"
        disabled>
    </button>
    <br><br>
</div>

<div class="col-md-12">
    <div id="runtimeWarnings" class="alert alert-warning hidden" style="white-space: pre-wrap;"></div>
    <div class="content-box">
        <div style="padding: 12px 14px; border-bottom: 1px solid #eeeeee; font-weight: 600;">
            Предварительная runtime-конфигурация
        </div>
        <div style="padding: 14px;">
            <div id="runtimeSha" class="text-muted" style="margin-bottom: 8px;"></div>
            <pre id="runtimePreview" style="max-height: 480px; overflow: auto; white-space: pre; word-break: normal;"></pre>
        </div>
    </div>
</div>
