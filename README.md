# os-sing-box-plus

`os-sing-box-plus` is a community plugin for integrating **sing-box** with **OPNsense**.

The project is based on `Opnwall/os-sing-box` and focuses on reliable service lifecycle, policy routing, DNS/FakeIP handling, health checks and recovery after network changes.

> This project is under active development and is not an official OPNsense/Deciso plugin.

## Features

- sing-box service integration for OPNsense;
- TUN-based traffic handling;
- DNS and FakeIP support;
- policy routing support;
- startup and WAN-event recovery;
- health and end-to-end connectivity checks;
- log handling and rotation;
- package build for FreeBSD/OPNsense.

## Build

Build the package on FreeBSD or OPNsense:

```sh
make package
```

The resulting package is created from the plugin source in `src/os-sing-box`.

## Status

The project is currently in development. Package layout and upgrade compatibility are being stabilized before the first independent release.

## Upstream

- [Opnwall/OPNsense-repo](https://github.com/Opnwall/OPNsense-repo)
- [SagerNet/sing-box](https://github.com/SagerNet/sing-box)
- [Vincent-Loeng/bsd-box](https://github.com/Vincent-Loeng/bsd-box)

## License

See [LICENSE](LICENSE).
