# Архитектура os-sing-box-plus

## Принципы

`os-sing-box-plus` должен быть не просто упаковкой бинарника sing-box, а полноценным OPNsense policy-routing frontend с безопасным жизненным циклом конфигурации.

## Конфигурация

Канонические пользовательские параметры должны храниться в OPNsense `config.xml` через MVC/model API. Runtime `config.json` генерируется из модели и шаблонов.

Применение настроек:

```text
model/config.xml
  -> generate temporary config
  -> sing-box check
  -> backup current runtime config
  -> atomic replace
  -> service restart
  -> local health
  -> deep/domain-policy probe
  -> automatic rollback on failure
```

## Policy routing

Поддерживаемые действия:

- `PROXY`
- `DIRECT`
- `REJECT`

Селекторы:

- exact domain;
- domain suffix;
- keyword;
- regexp;
- local/remote rule-set;
- IPv4/IPv6;
- CIDR;
- IP range;
- OPNsense Alias;
- invert selector;
- interface/VLAN selector.

## Capture modes

- local OPNsense only;
- selected clients;
- selected interfaces;
- all LAN.

DNS interception и traffic interception должны управляться отдельно.

## Health

Минимальные уровни диагностики:

1. local: process/listener/TUN/route;
2. policy: DNS selective/direct и FakeIP;
3. egress: реальный выход через выбранный gateway;
4. end-to-end: доменный запрос через фактический policy path;
5. client path: проверка DNS redirect -> listener для выбранного клиента.

Состояние последних переходов должно переживать reboot.

## Метрики

Планируется локальный Prometheus-compatible endpoint, выключенный по умолчанию и доступный только на явно выбранном listen address/interface.

Базовые gauges/counters:

- `singbox_plus_service_up`;
- `singbox_plus_dns_tcp_up`;
- `singbox_plus_dns_udp_up`;
- `singbox_plus_tun_up`;
- `singbox_plus_fakeip_route_up`;
- `singbox_plus_gateway_up`;
- `singbox_plus_gateway_latency_ms`;
- `singbox_plus_gateway_packet_loss_percent`;
- `singbox_plus_vpn_egress_up`;
- `singbox_plus_policy_probe_up`;
- `singbox_plus_health_state`;
- `singbox_plus_health_transitions_total`;
- `singbox_plus_selected_clients`;
- при наличии безопасного источника — traffic/connection metrics из sing-box Clash API.

Высококардинальные labels по доменам/клиентам по умолчанию запрещены. Секреты в metrics endpoint не выводятся.

## Логи

- уровень логирования;
- output path;
- automatic parent directory self-heal;
- rotation size;
- retention count/time;
- compression;
- newsyslog integration;
- безопасный просмотр/очистка из GUI.

## Package build

Production build не должен использовать плавающий `releases/latest`.

Обязательно фиксируются:

- plugin version;
- package revision;
- exact Vincent/reF1nd core version;
- source/release URL;
- SHA256;
- core revision/build provenance.

## Безопасность

- fail-closed/fail-open выбирается явно;
- firewall/DNS changes применяются атомарно;
- rollback path обязателен;
- diagnostics и metrics не раскрывают secrets;
- WAN interception не включается неявно.
