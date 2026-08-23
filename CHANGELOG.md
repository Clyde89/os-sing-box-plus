# Changelog

Все существенные изменения `os-sing-box-plus` фиксируются здесь.

Формат ориентирован на Keep a Changelog. До первого самостоятельного release проект находится в стадии `Unreleased`.

## [Unreleased]

### Added

- Создан специализированный fork `os-sing-box-plus` на базе Opnwall `os-sing-box`.
- Введена модель веток `main` / `develop` / `upstream-main` / `feature/*`.
- Зафиксированы требования к RU/EN UI, policy routing, health monitoring, метрикам и воспроизводимым сборкам.

### Changed

- Репозиторий очищается от остальных community-плагинов Opnwall и фокусируется только на sing-box.

### Fixed

- В рабочей OPNsense-инсталляции подтверждён self-heal каталога `/var/log/sing-box` при старте службы. Исправление будет перенесено в исходники package отдельным коммитом после завершения диагностики post-reboot DNS path.
