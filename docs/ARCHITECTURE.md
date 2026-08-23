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

## DNS bootstrap для policy-bound DoH

Если выбранные домены разрешаются через DoH, который сам должен идти через policy-bound direct outbound, нельзя создавать циклическую зависимость вида:

```text
vpn outbound -> domain_resolver=vpn-dns -> vpn-dns.detour=vpn outbound
```

На OPNsense 26.7 / FreeBSD 15.1 такой путь был воспроизведён как runtime failure `no route to host` для TCP соединения к DoH endpoint при том, что системный bound connect и PF `route-to` работали.

Подтверждённый рабочий шаблон использует отдельный bootstrap outbound без `domain_resolver`:

```json
{
  "dns": {
    "servers": [
      {
        "type": "https",
        "tag": "vpn-dns",
        "server": "8.8.8.8",
        "server_port": 443,
        "path": "/dns-query",
        "tls": {
          "enabled": true,
          "server_name": "dns.google"
        },
        "detour": "vpn-dns-bootstrap"
      }
    ]
  },
  "outbounds": [
    {
      "type": "direct",
      "tag": "vpn",
      "inet4_bind_address": "POLICY_SOURCE_IP",
      "domain_resolver": "vpn-dns"
    },
    {
      "type": "direct",
      "tag": "vpn-dns-bootstrap",
      "inet4_bind_address": "POLICY_SOURCE_IP"
    }
  ]
}
```

Bootstrap outbound должен наследовать тот же policy source/interface selection, но не должен ссылаться обратно на DNS server, который использует его как detour.

Генератор конфигурации обязан проверять такие циклы до применения настроек.

## Health

Минимальные уровни диагностики:

1. local: process/listener/TUN/route;
2. policy: DNS selective/direct и FakeIP;
3. DNS upstream: реальный запрос через каждый configured policy-bound DNS transport;
4. egress: реальный выход через выбранный gateway;
5. end-to-end: доменный запрос через фактический policy path;
6. client path: проверка DNS redirect -> listener для выбранного клиента.

Состояние последних переходов должно переживать reboot.

`deep` не должен считаться успешным только по факту доступности gateway/egress: policy-bound DoH transport проверяется отдельно, чтобы исключить ложноположительный `OK`.

## Метрики

Планируется локальный Prometheus-compatible endpoint, выключенный по умолчанию и доступный только на явно выбранном listen address/interface.

Базовые gauges/counters:

- `singbox_plus_service_up`;
- `singbox_plus_dns_tcp_up`;
- `singbox_plus_dns_udp_up`;
- `singbox_plus_dns_upstream_up`;
- `singbox_plus_dns_upstream_latency_ms`;
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

Service layer обязан создать parent directory и log file до `sing-box check`, потому что внутренний logger sing-box не обязан создавать отсутствующий parent directory.

Базовый путь plus-версии:

```text
/var/log/sing-box/sing-box.log
```

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
