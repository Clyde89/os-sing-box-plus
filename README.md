# os-sing-box-plus

Community-плагин **sing-box для OPNsense**, развиваемый на базе `Opnwall/os-sing-box`.

Цель проекта — превратить исходную интеграцию sing-box в аккуратный OPNsense-плагин с предсказуемым lifecycle, policy routing, DNS/FakeIP, диагностикой, автоматическим восстановлением после сетевых событий и воспроизводимой сборкой пакета.

> Проект находится в активной разработке и пока не считается стабильным release. Он не является официальной частью OPNsense/Deciso.

## Текущий статус

Рабочий baseline проверяется на **OPNsense 26.7.2_2 / FreeBSD 15.1**.

На реальной системе уже подтверждены:

- безопасный запуск sing-box с self-heal каталога журнала;
- policy routing через отдельный gateway с PF `route-to`;
- fail-closed защита policy-трафика от выхода напрямую через WAN;
- селективный DNS и FakeIP;
- отдельный bootstrap outbound для policy-bound DoH без циклической `domain_resolver` / `detour` зависимости;
- воспроизводимый отказ уже запущенного sing-box после WAN `DOWN/UP` и последующей routing reconfiguration;
- recovery-прототип через OPNsense `newwanip`: `underlay -> policy DNS -> E2E HTTPS -> один restart -> повторная проверка`;
- автоматическое восстановление после реального WAN flap без ручного вмешательства;
- local/deep health-прототип с проверками service, DNS, TUN, FakeIP, PBR, fail-closed и VPN egress.

Следующий практический этап — перенести уже проверенные recovery/health механизмы из production-прототипа непосредственно в package-managed структуру плагина.

## Что будет в os-sing-box-plus

- OPNsense-native конфигурация через MVC / `config.xml`;
- правила `PROXY` / `DIRECT` / `REJECT`;
- выбор клиентов по IP, CIDR, диапазонам и OPNsense Alias;
- выбор интерфейсов и VLAN;
- независимое управление DNS interception и traffic interception;
- безопасный FakeIP и policy-bound DNS;
- startup/WAN recovery без бесконечных restart loops;
- health-state `OK / WARN / CRITICAL` с отдельным security-state;
- Gotify-уведомления только по переходам состояния;
- Prometheus-compatible метрики без secrets и high-cardinality labels по умолчанию;
- управление логами, ротацией и хранением;
- RU/EN интерфейс;
- фиксированная версия Vincent/reF1nd core, SHA256 и build provenance;
- безопасное обновление без потери пользовательской конфигурации.

## Структура репозитория

В рабочем tree остаётся только один OPNsense-плагин:

```text
src/os-sing-box/    исходники плагина и FreeBSD package build
docs/               архитектура и дорожная карта
.github/             CI
```

Старые README/скриншоты исходного community-репозитория удалены. Release-бинарник core также не хранится в Git; build-скрипт может получать его как внешний asset. До первого стабильного release этот механизм будет дополнительно переведён с `releases/latest` на фиксированную версию и обязательную SHA256-проверку.

Внутреннее package-имя `os-sing-box` пока сохраняется намеренно. Переименование в `os-sing-box-plus` будет выполняться только вместе с корректным migration/upgrade path для существующих установок.

## Сборка

Сборка пакета выполняется на FreeBSD/OPNsense:

```sh
make package
```

Корневой `Makefile` вызывает build единственного плагина в `src/os-sing-box`.

Текущий build pipeline переходный. Перед release обязательны:

- exact core version;
- фиксированный download URL;
- SHA256 verification;
- build provenance;
- package install/upgrade/reinstall smoke tests.

## Документация

- `docs/ARCHITECTURE.md` — архитектура и принятые технические решения;
- `docs/ROADMAP.md` — последовательность разработки и текущие задачи;
- `CHANGELOG.md` — существенные изменения проекта;
- `CONTRIBUTING.md` — правила разработки и upstream-sync.

## Ветки

- `main` — стабильная production-ветка;
- `develop` — интеграционная ветка;
- `upstream-main` — нетронутое зеркало исходного Opnwall tree;
- `feature/*` — отдельные задачи разработки.

Upstream-зеркало не используется для собственных изменений: релевантные upstream-изменения переносятся выборочно через feature-ветки.

## Правило безопасного изменения

Изменения, затрагивающие DNS, PF/PBR, FakeIP, gateway routing, startup lifecycle или package upgrade, должны проходить проверку по цепочке:

```text
backup -> validate -> apply -> service check -> policy DNS -> E2E probe -> rollback on failure
```

Бесконечные recovery/restart loops не допускаются.

## Upstream

Проект основан на community-плагине `Opnwall/os-sing-box` и сохраняет его происхождение и лицензионные требования.

Основные upstream-компоненты:

- `Opnwall/OPNsense-repo` — исходная OPNsense-интеграция;
- `SagerNet/sing-box` — upstream sing-box;
- `Vincent-Loeng/bsd-box` — FreeBSD/reF1nd builds.

## Лицензия

См. `LICENSE`.
