# os-sing-box-plus

Community-плагин **sing-box для OPNsense**, развиваемый на базе `Opnwall/os-sing-box`.

Цель проекта — сделать интеграцию sing-box с OPNsense предсказуемой и безопасной: с нормальным жизненным циклом сервиса, policy routing, DNS/FakeIP, диагностикой, автоматическим восстановлением после сетевых событий и воспроизводимой сборкой пакета.

> Проект не является официальной частью OPNsense/Deciso.

## Текущий статус

Разработка ведётся и тестируется на **OPNsense 26.7 / FreeBSD 15.1**.

Уже подтверждено на реальной системе:

- запуск sing-box с автоматическим созданием каталога журнала;
- policy source + PF `route-to` через отдельный VPN-шлюз;
- fail-closed защита от утечки policy-трафика через WAN;
- селективный DNS через FakeIP;
- отдельный bootstrap outbound для policy-bound DoH без циклической зависимости;
- восстановление после WAN `DOWN/UP` и `rc.newwanip`;
- readiness-проверка `underlay -> policy DNS -> E2E HTTPS`;
- один ограниченный self-heal restart при сломанном policy path;
- локальная и deep-диагностика, включая VPN egress и security checks.

Следующий этап — перенести уже проверенный recovery/health lifecycle из production-прототипа непосредственно в структуру плагина и пакета.

## Основные направления

- OPNsense-native конфигурация через MVC / `config.xml`;
- правила `PROXY` / `DIRECT` / `REJECT`;
- выбор клиентов по IP, CIDR, диапазонам и OPNsense Alias;
- выбор интерфейсов и VLAN;
- независимое управление DNS interception и traffic interception;
- безопасный FakeIP и policy-bound DNS;
- startup/WAN recovery без бесконечных restart loops;
- `OK / WARN / CRITICAL` health-state с отдельным состоянием безопасности;
- Gotify-уведомления о переходах состояния;
- Prometheus-compatible метрики;
- журналирование, ротация и хранение логов;
- RU/EN интерфейс;
- фиксированная версия Vincent/reF1nd core, SHA256 и build provenance;
- безопасное обновление без потери пользовательской конфигурации.

## Структура репозитория

Репозиторий содержит только один OPNsense-плагин:

```text
src/os-sing-box/    исходники плагина и FreeBSD package build
docs/               архитектура и дорожная карта
.github/             CI
```

Внутреннее имя `os-sing-box` пока сохраняется намеренно. Переименование package/origin будет выполняться только вместе с корректным upgrade/migration path для существующих установок.

## Сборка

На FreeBSD/OPNsense:

```sh
make package
```

Корневой `Makefile` вызывает сборку единственного плагина в `src/os-sing-box`.

Текущий build pipeline ещё требует доработки перед release: production-сборка не должна использовать плавающий `releases/latest`; версия core и SHA256 должны быть зафиксированы.

## Документация

- `docs/ARCHITECTURE.md` — архитектурные решения и требования;
- `docs/ROADMAP.md` — последовательность разработки и текущий статус.

## Upstream

Проект основан на community-плагине `Opnwall/os-sing-box` и сохраняет его происхождение и лицензионные требования.

Основные upstream-компоненты:

- `Opnwall/OPNsense-repo` — исходная интеграция OPNsense;
- `SagerNet/sing-box` — sing-box;
- `Vincent-Loeng/bsd-box` — FreeBSD/reF1nd builds.

## Безопасность изменений

Изменения, затрагивающие DNS, PF/PBR, FakeIP, gateway routing, startup lifecycle или package upgrade, должны проходить проверку по цепочке:

```text
backup -> validate -> apply -> service check -> policy DNS -> E2E probe -> rollback on failure
```

Бесконечные recovery/restart loops не допускаются.

## Лицензия

См. `LICENSE`.
