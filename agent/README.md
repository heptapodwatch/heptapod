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

- **Filesystems.** The agent reports at most 16 real filesystems, one per device. It skips tmpfs, devtmpfs, overlay, squashfs, proc, sysfs, cgroup and nsfs mounts, and everything under `/proc`, `/sys`, `/dev` and `/run`. It skips the boot partitions too (`/boot`, `/boot/efi` and anything else under `/boot`). It also skips automount points and network filesystems (NFS, CIFS/SMB, sshfs and other FUSE mounts, Ceph, GlusterFS, 9p). A disk that does not answer `statfs` within 2 seconds is left out, so a hung mount never stops the report. Mounts it cannot reach from its sandbox are left out, among them a separate `/home` (the unit sets `ProtectHome=yes`). A disk below a folder only root can open, such as `/var/lib/docker`, still works: `statfs` only needs access to the folder above it.
- **IP addresses.** The agent reports at most 16 public unicast addresses. Loopback, link-local, private (RFC 1918, IPv6 ULA) and shared (100.64.0.0/10) addresses are left out. The instance uses the addresses only to suggest which sites run on this server.
- **Reboot required.** If `/run/reboot-required` exists, the agent reports `true`. Ubuntu, and Debian with unattended-upgrades, create this file. On Ubuntu its absence means `false`. On Debian the agent also reports `true` when a `/boot/vmlinuz-*` newer than the running kernel is installed. Elsewhere the value is `null` (unknown).
- **Updates.** On Ubuntu the agent runs `/usr/lib/update-notifier/apt-check`. That is the script behind the login message, and the agent's only subprocess. It runs again only after `/var/lib/dpkg/status` or `/var/lib/apt/lists` changes; a failed run is retried after 15 minutes. Debian and other distributions report both counts as `null`: they have no count that works without root and without network, and the agent never runs `apt update`.

## What it never does

- It runs no remote commands and opens no listening port. The only response it reads from the instance is the next interval.
- It does not update itself. You upgrade by re-running the install script.
- It sends no logs, process lists, package lists, file contents or traces.
- It runs no subprocess except `apt-check` on Ubuntu.
- It does not follow redirects, and it sends its token only over HTTPS. Plain HTTP is allowed only to `localhost`, for testing.
- It never logs response bodies or the token.

## Permissions

The systemd unit is in [`packaging/heptapod-agent.service`](packaging/heptapod-agent.service). The binary prints the same unit with `heptapod-agent --print-unit`, so the install script and the binary always match. The unit sets:

- `DynamicUser=yes`: a throwaway user without a home, created for each start;
- `NoNewPrivileges=yes` and an empty `CapabilityBoundingSet=`;
- `ProtectSystem=strict`, `ProtectHome=yes` and `PrivateTmp=yes`: the whole file system is read-only, and `/home` and `/root` are hidden;
- `PrivateDevices=yes`, `ProtectKernelModules=yes`, `ProtectKernelTunables=yes`, `ProtectKernelLogs=yes` and `RestrictSUIDSGID=yes`: no device nodes, no access to kernel modules, tunables or the kernel log, no setuid files;
- `RestrictAddressFamilies=AF_INET AF_INET6 AF_UNIX AF_NETLINK`: AF_NETLINK is only used to list the interface addresses;
- `LoadCredential=token:/etc/heptapod-agent/token`: systemd hands the root-only token to the service. The agent never reads it from arguments or environment variables.

The agent reads the endpoint URL from `/etc/heptapod-agent/endpoint`. The instance must be reachable from the server over HTTPS. If your instance is only reachable through a VPN, the server must be in that VPN too.

## Install, upgrade, uninstall

You need root, systemd, amd64 or arm64, `curl` and `sha256sum`. Heptapod shows the install command when you add a server. It has this form:

```sh
curl -fsSLO --proto '=https' https://github.com/heptapodwatch/heptapod/releases/download/vX.Y.Z/install.sh
sudo sh install.sh --version vX.Y.Z --endpoint https://heptapod.example.com/agent/v1/report
```

The script then asks for the agent token and hides your input. To pass the token from a file instead, redirect it to stdin:

```sh
sudo sh install.sh --version vX.Y.Z --endpoint https://heptapod.example.com/agent/v1/report < token.txt
```

`--token hpa_...` also works, but then the token ends up in your shell history and in sudo's log, so the script warns about it.

The script:

1. downloads `heptapod-agent-linux-<arch>` and `SHA256SUMS` from the GitHub release `vX.Y.Z` over HTTPS only (redirects included), and checks the checksum;
2. checks the endpoint and the token with the new binary (`heptapod-agent --check-config`) before it changes anything;
3. installs the binary as `/usr/local/bin/heptapod-agent`;
4. writes the endpoint (`/etc/heptapod-agent/endpoint`) and the token (`/etc/heptapod-agent/token`, mode 0600, root only);
5. prints a dry-run sample, then installs, enables and starts `heptapod-agent.service`;
6. waits up to 90 seconds for the service's first accepted report.

The binary and the script come from the GitHub release, never from your Heptapod instance. The instance only hands out the endpoint and the token.

**Upgrade.** Download the new version's `install.sh` and run it with the new `--version`. Without `--endpoint`, it keeps the installed endpoint. At the token prompt, press Enter to keep the installed token.

**Uninstall.**

```sh
sudo sh install.sh --uninstall
```

This stops and disables the service and removes the unit, the binary and `/etc/heptapod-agent`. The agent keeps no other state.

## Verifying the download

The checksum check inside the script only catches damaged downloads: `SHA256SUMS` comes from the same place as the binary. To check that `install.sh` and the binary were built from this repository by its release workflow, verify their GitHub artifact attestations with the [GitHub CLI](https://cli.github.com/). Check the script before you run it with `sudo`:

```sh
gh attestation verify install.sh --repo heptapodwatch/heptapod \
  --signer-workflow heptapodwatch/heptapod/.github/workflows/release-bundle.yml
```

After the install, check the binary the same way:

```sh
gh attestation verify /usr/local/bin/heptapod-agent --repo heptapodwatch/heptapod \
  --signer-workflow heptapodwatch/heptapod/.github/workflows/release-bundle.yml
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
