# Дорожная карта

## Этап 0 — bootstrap репозитория

- [x] Создать fork `Clyde89/os-sing-box-plus`.
- [x] Создать `upstream-main` как зеркало исходного Opnwall tree.
- [x] Создать `develop`.
- [x] Создать `feature/repository-bootstrap`.
- [x] Очистить рабочую ветку от остальных community-плагинов.
- [x] Добавить документацию и минимальный CI.

## Этап 1 — восстановление production baseline

- [x] Исправить создание `/var/log/sing-box` при запуске.
- [x] Проверить artificial self-heal.
- [x] Проверить реальный reboot для log-directory self-heal.
- [x] Локализовать post-reboot DNS fault до policy-bound DoH transport.
- [x] Разорвать цикл `vpn outbound -> vpn-dns -> vpn outbound` отдельным DNS bootstrap outbound.
- [x] Проверить A/FakeIP, HTTPS RR, VPN egress и реальный доступ с клиента после manual restart.
- [x] Выполнить повторный reboot-test уже с DNS bootstrap fix и воспроизвести отказ DoH у boot-started процесса.
- [x] Подтвердить после reboot, что системный bound connect `192.168.0.70 -> 8.8.8.8:443` и VPN egress исправны до restart sing-box.
- [x] Подтвердить, что один `service sing-box restart` восстанавливает HTTPS RR и selected traffic без изменения PF/PBR/config.
- [x] Перенести log self-heal и DNS bootstrap архитектуру в исходники/документацию plus-версии.
- [ ] Реализовать OPNsense-aware startup readiness gate после полной загрузки policy routing/gateway state.
- [ ] Добавить post-start policy-bound DNS probe и один ограниченный self-heal restart при состоянии `underlay ready / DNS transport failed`.
- [ ] Добавить отдельный DNS-upstream health probe, чтобы `deep` не давал false-positive `OK`.
- [ ] Разобрать редкие runtime `context deadline exceeded` отдельно от boot fault и определить, какой DNS transport их генерирует.
- [ ] Исследовать единичный маршрут к `172.19.0.2:853` через `direct-wan` и подтвердить, является ли это внешним DoT трафиком клиента или TUN DNS behavior.

## Этап 2 — reproducible core

- [ ] Перейти с `releases/latest` на фиксированную Vincent/reF1nd version.
- [ ] Добавить SHA256 verification.
- [ ] Записывать core version/revision/build provenance.
- [ ] Собрать и проверить актуальный stable core на OPNsense 26.7 / FreeBSD 15.1.
- [ ] Повторить boot/DNS regression tests на новом core до окончательного закрепления startup workaround.

## Этап 3 — OPNsense-native configuration

- [ ] MVC/config.xml model.
- [ ] Generated runtime config.
- [ ] Atomic apply/rollback.
- [ ] Проверка циклических DNS detour/domain_resolver зависимостей до apply.
- [ ] Миграция существующего `os-sing-box` без потери настроек.

## Этап 4 — policy frontend

- [ ] PROXY/DIRECT/REJECT domain policies.
- [ ] IP/CIDR/range/Alias client selectors.
- [ ] Invert/group selectors.
- [ ] Interface/VLAN multiselect.
- [ ] Local/selected/interface/all-LAN capture modes.
- [ ] Separate DNS and traffic interception.

## Этап 5 — observability

- [ ] Local/deep/end-to-end health page.
- [ ] Policy-bound DNS upstream probe.
- [ ] Startup readiness state and recovery counters.
- [ ] Persistent transition history.
- [ ] Gotify transition notifications.
- [ ] Prometheus-compatible metrics endpoint.
- [ ] DNS-upstream availability/latency metrics.
- [ ] Gateway latency/loss, service/DNS/TUN/FakeIP/PBR/egress metrics.
- [ ] Optional traffic/connection metrics from safe sing-box API source.

## Этап 6 — UI и эксплуатация

- [ ] RU/EN localization.
- [ ] Log level/output/rotation/retention/compression GUI.
- [ ] Diagnostics report without secrets.
- [ ] Package upgrade/reinstall smoke tests.
- [ ] Release/rollback documentation.
