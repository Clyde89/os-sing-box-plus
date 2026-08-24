# os-sing-box-plus

`os-sing-box-plus` — community-плагин для интеграции **sing-box** с **OPNsense**.

Проект основан на `Opnwall/os-sing-box` и развивается как отдельный плагин с акцентом на надёжный жизненный цикл службы, policy routing, DNS/FakeIP, диагностику и восстановление после сетевых изменений.

> Проект находится в активной разработке и не является официальным плагином OPNsense/Deciso.

## Возможности

- интеграция службы sing-box с OPNsense;
- обработка трафика через TUN;
- поддержка DNS и FakeIP;
- policy routing;
- восстановление после запуска системы и изменений WAN;
- health-check и сквозные проверки доступности;
- управление журналами и их ротацией;
- сборка пакета для FreeBSD/OPNsense.

## Сборка

Сборка выполняется на FreeBSD или OPNsense:

```sh
make package
```

Результат сохраняется в каталоге `dist/`.

## Структура

- `src/` — файлы, устанавливаемые пакетом;
- `packaging/` — служебные файлы FreeBSD-пакета;
- `build.sh` — сборка пакета;
- `Makefile` — основные команды сборки.

## Статус

До первого самостоятельного релиза структура пакета и совместимость обновлений остаются в стадии стабилизации.

## Upstream

- [Opnwall/OPNsense-repo](https://github.com/Opnwall/OPNsense-repo)
- [SagerNet/sing-box](https://github.com/SagerNet/sing-box)
- [Vincent-Loeng/bsd-box](https://github.com/Vincent-Loeng/bsd-box)

## Лицензия

См. [LICENSE](LICENSE).
