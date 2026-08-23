# Дорожная карта

## Этап 0 — bootstrap репозитория

- [x] Создать fork `Clyde89/os-sing-box-plus`.
- [x] Создать `upstream-main` как зеркало исходного Opnwall tree.
- [x] Создать `develop`.
- [x] Создать `feature/repository-bootstrap`.
- [ ] Очистить рабочую ветку от остальных community-плагинов.
- [ ] Добавить документацию и минимальный CI.

## Этап 1 — восстановление production baseline

- [x] Исправить создание `/var/log/sing-box` при запуске.
- [x] Проверить artificial self-heal.
- [x] Проверить реальный reboot.
- [ ] Устранить post-reboot внутренний DNS egress fault к `8.8.8.8:443` через `vpn-nl`.
- [ ] Перенести подтверждённые исправления в исходники plugin package.

## Этап 2 — reproducible core

- [ ] Перейти с `releases/latest` на фиксированную Vincent/reF1nd version.
- [ ] Добавить SHA256 verification.
- [ ] Записывать core version/revision/build provenance.
- [ ] Обновить stable core после совместимого теста.

## Этап 3 — OPNsense-native configuration

- [ ] MVC/config.xml model.
- [ ] Generated runtime config.
- [ ] Atomic apply/rollback.
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
- [ ] Persistent transition history.
- [ ] Gotify transition notifications.
- [ ] Prometheus-compatible metrics endpoint.
- [ ] Gateway latency/loss, service/DNS/TUN/FakeIP/PBR/egress metrics.
- [ ] Optional traffic/connection metrics from safe sing-box API source.

## Этап 6 — UI и эксплуатация

- [ ] RU/EN localization.
- [ ] Log level/output/rotation/retention/compression GUI.
- [ ] Diagnostics report without secrets.
- [ ] Package upgrade/reinstall smoke tests.
- [ ] Release/rollback documentation.
