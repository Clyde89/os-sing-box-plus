# os-sing-box-plus

Улучшенный community-плагин **sing-box для OPNsense**, основанный на `Opnwall/os-sing-box`.

Проект сфокусирован только на sing-box и развивается как самостоятельная надстройка над исходным плагином Opnwall. Цель — безопасная и воспроизводимая интеграция sing-box с OPNsense: селективная маршрутизация, управление клиентами и интерфейсами, диагностика, метрики, журналирование и контролируемые обновления ядра.

## Статус

Проект находится на этапе bootstrap/refactoring. Текущая кодовая база импортирована из `Opnwall/OPNsense-repo` и будет постепенно переведена на архитектуру `os-sing-box-plus` без потери совместимости с OPNsense.

## Основные цели

- RU/EN интерфейс;
- режимы перехвата: локальный, выбранные клиенты, выбранные интерфейсы, вся LAN;
- правила `PROXY` / `DIRECT` / `REJECT` для доменов и наборов правил;
- клиенты по IP, CIDR, диапазону и OPNsense Alias, включая инверсию;
- выбор обслуживаемых интерфейсов и VLAN;
- безопасное управление DNS interception и FakeIP;
- health/deep/domain-policy проверки и аварийный rollback;
- управление логами, ротацией и хранением;
- **метрики** для Prometheus/совместимых систем мониторинга;
- Gotify-уведомления о переходах состояния;
- воспроизводимые сборки с фиксированной версией Vincent/reF1nd core и SHA256;
- сохранение пользовательской конфигурации при обновлении пакета.

## Ветки

- `main` — стабильная production-ветка;
- `develop` — интеграция и тестирование;
- `upstream-main` — неизменённое зеркало `Opnwall/main` для переноса upstream-обновлений;
- `feature/*` — отдельные задачи разработки.

## Структура

```text
src/os-sing-box/        исходная кодовая база плагина и package build
docs/                   архитектура, дорожная карта и проектные решения
.github/                 CI и шаблоны разработки
```

Переименование внутреннего package/source tree в `os-sing-box-plus` будет выполнено отдельной миграцией после проектирования upgrade path с `os-sing-box`, чтобы не ломать существующие установки.

## Upstream и происхождение

Проект основан на community-плагине `os-sing-box` из репозитория Opnwall и сохраняет совместимость с соответствующей MIT-лицензией. Ядро sing-box и FreeBSD/reF1nd-сборки являются отдельными upstream-компонентами.

- Opnwall: https://github.com/Opnwall/OPNsense-repo
- sing-box: https://github.com/SagerNet/sing-box
- Vincent-Loeng/bsd-box: https://github.com/Vincent-Loeng/bsd-box

## Важное правило разработки

Любое изменение, затрагивающее запуск, DNS, маршрутизацию, PF/PBR, FakeIP или package upgrade, должно проходить:

```text
backup -> generate/modify -> sing-box check -> restart -> health -> deep probe -> rollback on failure
```

Проект не связан с Deciso или официальным проектом OPNsense.
