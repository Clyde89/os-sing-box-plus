# Contributing

`os-sing-box-plus` использует модель разработки с отделением upstream-зеркала, интеграционной ветки и production.

## Ветки

- `upstream-main` — точная копия `Opnwall/main`. Собственные коммиты запрещены.
- `develop` — интеграционная ветка.
- `main` — только проверенные production-изменения.
- `feature/*` — разработка отдельных задач от `develop`.

## Перенос upstream

1. Обновить `upstream-main` до актуального `Opnwall/main`.
2. Сравнить изменения `src/os-sing-box` и общей package/build-инфраструктуры.
3. Перенести только релевантные изменения в отдельную `feature/upstream-*` ветку от `develop`.
4. Выполнить статические проверки и собрать `.pkg`.
5. Проверить пакет на тестовой/контролируемой OPNsense.
6. Проверить `sing-box check`, service restart, health/deep probes, DNS/FakeIP/PBR и rollback.
7. Только после этого объединять в `main`.

## Требования к изменениям

- Не публиковать токены, UUID, пароли, Clash API secrets и другие секреты.
- Не хардкодить пользовательские IP/интерфейсы в production defaults без отдельного шаблона/миграции.
- Не применять runtime config без предварительного `sing-box check`.
- Изменения firewall/DNS interception должны иметь dry-run/preview и понятный rollback path.
- Метрики не должны по умолчанию создавать высококардинальные labels по каждому домену или клиенту.
- Production package build должен фиксировать exact core version и SHA256.
