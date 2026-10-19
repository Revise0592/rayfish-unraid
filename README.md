# Rayfish for Unraid

An Unraid plugin that runs [Rayfish](https://github.com/rayfish/rayfish), a peer-to-peer mesh VPN
with no control server, on your Unraid server, and adds a **Settings → Rayfish** page to manage it.

> Rayfish is experimental, pre-1.0 software without an independent security audit. This plugin is
> an unofficial wrapper and is not affiliated with the Rayfish project.

## Install

In the Unraid webGUI go to **Plugins → Install Plugin**, paste this URL and click **Install**:

```
https://raw.githubusercontent.com/revise0592/rayfish-unraid/main/plugin/rayfish.plg
```

Requires Unraid 6.12 or newer on x86_64.

## Use

Open **Settings → Rayfish** (under Network Services). From there you can:

- enable, disable, restart or stop the daemon;
- see `ray status` for every network, including peers and addresses;
- create a network, mint an invite code, or join with a code from another device;
- run any other `ray` command (`requests home`, `firewall list`, `netcheck`, `--help` …);
- read the daemon logs.

The `ray` CLI is also on the path, so the same commands work from the Unraid terminal or SSH:

```bash
ray create --name home
ray invite home
ray status
```

## How it works

| What | Where |
| --- | --- |
| `ray` binary (upstream static musl build, SHA-256 pinned) | cached in `/boot/config/plugins/rayfish/`, installed to `/usr/local/bin/ray` |
| Identity, networks, firewall rules | `/boot/config/plugins/rayfish/state`, symlinked to `/etc/rayfish` |
| Blob store (file transfers, membership snapshots) | `/var/lib/rayfish` (RAM) |
| Logs | `/var/log/rayfish` (RAM) |
| Service script | `/etc/rc.d/rc.rayfish {start\|stop\|restart\|status}` |
| Plugin settings | `/boot/config/plugins/rayfish/rayfish.cfg` |

Unraid runs from RAM and has no systemd, so the plugin runs `ray daemon` itself rather than using
`ray up` / `ray install`. Those, along with `ray update` and `ray gui`, are blocked on the settings
page; update Rayfish by updating the plugin. Only the small config files live on the flash drive,
so the node keeps its identity and memberships across reboots without large writes to flash.

Uninstalling the plugin keeps `/boot/config/plugins/rayfish/state`. Delete that folder to forget
the node's identity for good.

## Troubleshooting

- **The daemon fails with "add TUN IPv6 address".** Rayfish's overlay is IPv6-only. Set
  **Settings → Network Settings → Network protocol** to **IPv4 + IPv6**, then restart Rayfish.
- **Startup errors** are written to `/var/log/rayfish/stdout.log` and shown on the settings page.

## Development

The page and scripts live in `source/`. After changing them, rebuild the package and commit the
new archive together with the updated `.plg` (it pins the archive by SHA-256):

```bash
./build.sh            # version = today's date
./build.sh 2026.10.19a
```

To move to a newer Rayfish release, update `rayVersion` and `raySHA256` in `plugin/rayfish.plg`
(the checksum is published next to each release asset as `ray-linux-x86_64-musl.sha256`) and add
a `<CHANGES>` entry.
