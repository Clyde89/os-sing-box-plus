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

        function managementStateLabel(state) {
            const labels = {
                initial_setup: 'Первоначальная настройка',
                managed: 'Управляется структурированными настройками',
                empty: 'Рабочая конфигурация ещё не создана',
                unmanaged_existing: 'Обнаружена существующая пользовательская конфигурация'
            };
            return labels[state] || 'Состояние не определено';
        }

        function captureModeLabel(mode) {
            return mode === 'all_lan' ? 'Весь локальный трафик' : 'Только выбранные клиенты';
        }

        function renderPolicySummary(data) {
            const plan = data && data.policy_plan ? data.policy_plan : {};
            const selectors = data && data.selectors ? data.selectors : {};
            const clients = Array.isArray(selectors.clients) ? selectors.clients : [];
            const compiledClients = Array.isArray(plan.source_ip_cidr) ? plan.source_ip_cidr : [];
            const domains = Array.isArray(plan.domain) ? plan.domain : [];
            const suffixes = Array.isArray(plan.domain_suffix) ? plan.domain_suffix : [];
            const operations = Array.isArray(plan.operations) ? plan.operations : [];
            const dnsRedirect = plan.dns_redirect || {};
            const fakeIpRoute = plan.fakeip_route || {};
            const policyOutbound = plan.policy_outbound || {};
            const requirements = [];

            if (plan.requires_opnsense_dns_redirect === true) {
                requirements.push('Перенаправление DNS-запросов на локальный listener');
            }
            if (plan.requires_opnsense_fakeip_route === true) {
                requirements.push('Маршрут FakeIP-трафика через TUN');
            }
            if (plan.requires_policy_outbound === true) {
                requirements.push('Policy outbound для выбранного трафика');
            }
            if (requirements.length === 0) {
                requirements.push('Дополнительные policy-компоненты не требуются');
            }

            const dnsRedirectText = dnsRedirect.required === true
                ? captureModeLabel(dnsRedirect.scope) + ': DNS/53 → ' +
                    (dnsRedirect.target_address || 'не задано') + ':' + (dnsRedirect.target_port || 'не задано')
                : 'Не требуется';
            const fakeIpRouteText = fakeIpRoute.required === true
                ? (fakeIpRoute.network || 'не задано') + ' → ' + (fakeIpRoute.interface || 'не задано')
                : 'Не требуется';
            const outboundText = policyOutbound.required === true
                ? (policyOutbound.ready === true ? 'Готов' : 'Требует настройки')
                : 'Не требуется';

            $('#policyManagementState').text(managementStateLabel(data.management_state));
            $('#policyCaptureMode').text(captureModeLabel(plan.capture_mode));
            $('#policyClientCount').text(clients.length + ' исходных, ' + compiledClients.length + ' CIDR-селекторов');
            $('#policyDomainCount').text(domains.length + ' точных, ' + suffixes.length + ' wildcard');
            $('#policyFakeIp').text(plan.fakeip_ipv4_range || 'не используется');
            $('#policyDnsTypes').text(Array.isArray(plan.dns_query_types) ? plan.dns_query_types.join(', ') : 'не используются');
            $('#policyDnsRedirect').text(dnsRedirectText);
            $('#policyFakeIpRoute').text(fakeIpRouteText);
            $('#policyOutbound').text(outboundText);
            $('#policyOperationCount').text(String(operations.length));
            $('#policyPlanState').text(plan.ready === true ? 'Готов' : (plan.required === true ? 'Требует завершения настройки' : 'Дополнительные правила не требуются'));

            const list = $('#policyRequirements').empty();
            requirements.forEach(function(item) {
                $('<li>').text(item).appendTo(list);
            });

            $('#policySummary').removeClass('hidden');
        }

        function renderPreview(data) {
            if (!data || data.result !== 'ok') {
                $('#runtimePreview').text('');
                $('#runtimeSha').text('');
                $('#runtimeWarnings').addClass('hidden').text('');
                $('#policySummary').addClass('hidden');
                $('#applyAct').prop('disabled', true);
                showMessage('danger', data && data.message ? data.message : 'Не удалось сформировать предварительную конфигурацию.');
                return;
            }

            $('#runtimePreview').text(data.config || '');
            $('#runtimeSha').text(data.sha256 ? 'SHA-256: ' + data.sha256 : '');
            renderPolicySummary(data);

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
                    : 'Настройки сохранены, но runtime-конфигурация пока не готова к применению. Проверьте сводку и предупреждения.'
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
    Сохранение параметров не изменяет рабочий <code>config.json</code>. Перед применением можно проверить сводку, предупреждения и технический preview.
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

    <div id="policySummary" class="content-box hidden">
        <div style="padding: 12px 14px; border-bottom: 1px solid #eeeeee; font-weight: 600;">
            Что будет настроено
        </div>
        <div style="padding: 14px;">
            <dl class="dl-horizontal" style="margin-bottom: 10px;">
                <dt>Состояние конфигурации</dt><dd id="policyManagementState"></dd>
                <dt>Состояние policy-плана</dt><dd id="policyPlanState"></dd>
                <dt>Клиенты</dt><dd id="policyCaptureMode"></dd>
                <dt>Селекторы клиентов</dt><dd id="policyClientCount"></dd>
                <dt>Домены</dt><dd id="policyDomainCount"></dd>
                <dt>FakeIP IPv4</dt><dd id="policyFakeIp"></dd>
                <dt>DNS-типы</dt><dd id="policyDnsTypes"></dd>
                <dt>DNS redirect</dt><dd id="policyDnsRedirect"></dd>
                <dt>Маршрут FakeIP</dt><dd id="policyFakeIpRoute"></dd>
                <dt>Policy outbound</dt><dd id="policyOutbound"></dd>
                <dt>Операций OPNsense</dt><dd id="policyOperationCount"></dd>
            </dl>
            <strong>Необходимые компоненты:</strong>
            <ul id="policyRequirements" style="margin-top: 6px; margin-bottom: 0;"></ul>
        </div>
    </div>

    <div class="content-box">
        <div style="padding: 12px 14px;">
            <details>
                <summary style="cursor: pointer; font-weight: 600;">Технический JSON runtime-конфигурации</summary>
                <div id="runtimeSha" class="text-muted" style="margin: 10px 0 8px;"></div>
                <pre id="runtimePreview" style="max-height: 480px; overflow: auto; white-space: pre; word-break: normal;"></pre>
            </details>
        </div>
    </div>
</div>
