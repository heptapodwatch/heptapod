# Heptapod server agent (experimental)

A small static Linux binary that reports one resource sample per minute from a server to a Heptapod instance. Servers in Heptapod are experimental: the agent, its data and its contract may change or be removed.

## What it reads

Everything comes from files any user may read, plus `statfs`. The agent needs no root.

| Value                            | Source                                           |
| -------------------------------- | ------------------------------------------------ |
| CPU used % and cores             | two readings of `/proc/stat`, one interval apart |
| Load (1, 5, 15 min)              | `/proc/loadavg`                                  |
| Memory total and available       | `MemTotal` and `MemAvailable` in `/proc/meminfo` |
| Uptime                           | `/proc/uptime`                                   |
| Filesystems: size and free space | `/proc/self/mountinfo` plus `statfs` per mount   |
| Public IP addresses              | the network interfaces                           |
| Hostname, kernel, distribution   | `uname`, `/etc/os-release`                       |
| Reboot required                  | see below                                        |
| Pending and security updates     | see below                                        |

- **Filesystems.** The agent reports at most 16 real filesystems, one per device. It skips tmpfs, devtmpfs, overlay, squashfs, proc, sysfs, cgroup and nsfs mounts, and everything under `/proc`, `/sys`, `/dev` and `/run`. Mounts it cannot reach from its sandbox are left out, among them a separate `/home` (the unit sets `ProtectHome=yes`). A disk below a folder only root can open, such as `/var/lib/docker`, still works: `statfs` only needs access to the folder above it.
- **IP addresses.** The agent reports at most 16 public unicast addresses. Loopback, link-local, private (RFC 1918, IPv6 ULA) and shared (100.64.0.0/10) addresses are left out. The instance uses the addresses only to suggest which sites run on this server.
- **Reboot required.** If `/run/reboot-required` exists, the agent reports `true`. Ubuntu, and Debian with unattended-upgrades, create this file. On Ubuntu its absence means `false`. On Debian the agent also compares the running kernel with the newest `/boot/vmlinuz-*`. Elsewhere the value is `null` (unknown).
- **Updates.** On Ubuntu the agent runs `/usr/lib/update-notifier/apt-check`. That is the script behind the login message, and the agent's only subprocess. It runs again only after `/var/lib/dpkg/status` or `/var/lib/apt/lists` changes. Debian and other distributions report both counts as `null`: they have no count that works without root and without network, and the agent never runs `apt update`.

## What it never does

- It runs no remote commands and opens no listening port. The only response it reads from the instance is the next interval.
- It does not update itself. You upgrade by re-running the install script.
- It sends no logs, process lists, package lists, file contents or traces.
- It runs no subprocess except `apt-check` on Ubuntu.
- It does not follow redirects, and it sends its token only over HTTPS. Plain HTTP is allowed only to `localhost`, for testing.

## Permissions

The systemd unit is in [`packaging/heptapod-agent.service`](packaging/heptapod-agent.service). The binary prints the same unit with `heptapod-agent --print-unit`, so the install script and the binary always match. The unit sets:

- `DynamicUser=yes`: a throwaway user without a home, created for each start;
- `NoNewPrivileges=yes` and an empty `CapabilityBoundingSet=`;
- `ProtectSystem=strict`, `ProtectHome=yes` and `PrivateTmp=yes`: the whole file system is read-only, and `/home` and `/root` are hidden;
- `RestrictAddressFamilies=AF_INET AF_INET6 AF_UNIX AF_NETLINK`: AF_NETLINK is only used to list the interface addresses;
- `LoadCredential=token:/etc/heptapod-agent/token`: systemd hands the root-only token to the service. The agent never reads it from arguments or environment variables.

The agent reads the endpoint URL from `/etc/heptapod-agent/endpoint`. The instance must be reachable from the server over HTTPS. If your instance is only reachable through a VPN, the server must be in that VPN too.

## Install, upgrade, uninstall

You need root, systemd, amd64 or arm64, `curl` or `wget`, and `sha256sum`. The install command for a server is shown in Heptapod when you add the server. It has this form:

```sh
curl -fsSLO https://github.com/heptapodwatch/heptapod/releases/download/vX.Y.Z/install.sh
sudo sh install.sh --version vX.Y.Z --endpoint https://heptapod.example.com/agent/v1/report --token hpa_...
```

Without `--token`, the script reads the token from stdin, or asks for it, so it stays out of your shell history:

```sh
sudo sh install.sh --version vX.Y.Z --endpoint https://heptapod.example.com/agent/v1/report < token.txt
```

The script:

1. downloads `heptapod-agent-linux-<arch>` and `SHA256SUMS` from the GitHub release `vX.Y.Z` and checks the checksum;
2. installs the binary as `/usr/local/bin/heptapod-agent`;
3. writes the endpoint (`/etc/heptapod-agent/endpoint`) and the token (`/etc/heptapod-agent/token`, mode 0600, root only);
4. prints a dry-run sample, then installs, enables and starts `heptapod-agent.service`;
5. waits up to 90 seconds for the first accepted report.

The binary and the script come from the GitHub release, never from your Heptapod instance. The instance only hands out the endpoint and the token.

**Upgrade.** Run the script again with the new `--version`. Without `--endpoint` and `--token`, it keeps the installed ones.

**Uninstall.**

```sh
sudo sh install.sh --uninstall
```

This stops and disables the service and removes the unit, the binary and `/etc/heptapod-agent`. The agent keeps no other state.

## Verifying the download

The install script checks the binary against `SHA256SUMS` from the same release. The release workflow also signs build provenance for the binaries and `install.sh` with GitHub artifact attestations. To check that a binary was built from this repository by its release workflow, use the [GitHub CLI](https://cli.github.com/):

```sh
gh attestation verify /usr/local/bin/heptapod-agent --repo heptapodwatch/heptapod
```

## Debugging

```sh
heptapod-agent --once --dry-run   # print the sample that would be sent, then exit
heptapod-agent --version
journalctl -u heptapod-agent      # sends, rejections and back-off
```

The agent logs the first accepted report and every change of state. A rejected token (HTTP 401) is logged once and retried every 10 minutes. A switched-off or removed server (404) is retried every 15 minutes. Instance errors back off from 30 seconds to 10 minutes. During an outage the agent keeps at most one unsent sample.

## Development

```sh
cd agent
go vet ./... && go test ./...
sh build.sh 0.0.0-dev /tmp/heptapod-agent   # static linux/amd64 and linux/arm64 binaries plus SHA256SUMS
```

The request and response format is ingest contract v1. The tests check every response row with a fake instance, and every collector with fixture files in `testdata/`.
