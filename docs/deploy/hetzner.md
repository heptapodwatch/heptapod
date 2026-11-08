---
title: Hetzner deployment
description: Server sizes and steps for a Hetzner Cloud installation.
---

This page adds the Hetzner Cloud specifics to the [install guide](https://heptapod.watch/docs/getting-started/install/): server type, firewall, Docker on a fresh Ubuntu VPS and recovery from a failed first install.

## Server types

| Setup                                    | Server type  | Size                                |
| ---------------------------------------- | ------------ | ----------------------------------- |
| Without Lighthouse (`--without-browser`) | CX23         | amd64, 2 vCPU, 4 GB RAM, 40 GB disk |
| With Lighthouse (installer default)      | CX33         | amd64, 4 vCPU, 8 GB RAM, 80 GB disk |
| Arm alternatives, source builds only     | CAX11, CAX21 | arm64, 2/4/40 and 4/8/80            |

- Use Ubuntu 24.04 amd64. The release images `ghcr.io/heptapodwatch/heptapod-app` and `ghcr.io/heptapodwatch/heptapod-lighthouse` are amd64 only.
- An arm64 instance built from source upgrades with `./scripts/upgrade.sh --build`.
- Keep at least 12 GiB free on the Docker filesystem: 6 GiB for images and 6 GiB for backups. Larger data needs more backup space.
- Capacity depends on the mix of checks, not only the number of sites. See the [capacity table](operations.md#installation-and-capacity).

## Prepare the server

1. Create the server with Ubuntu 24.04 amd64.
2. In the Hetzner firewall, allow inbound TCP 22, 80 and 443 only.
3. Point a DNS A record at the server. Add AAAA only with working IPv6.

Caddy handles TLS on its own.

## Install with the one-line installer

On the fresh server, run as a sudo-capable user:

```sh
curl -fsSL https://heptapod.watch/install.sh | sudo bash -s -- --install-docker --domain monitor.example.com --owner-email owner@example.com --without-browser
```

- `--install-docker` installs Docker Engine, the Compose plugin, curl, jq and OpenSSL as in the prerequisites below. It changes nothing when Docker is already installed.
- Leave out `--without-browser` on a CX33 to run Lighthouse.
- The installer prints the first Owner's password once. Public registration is disabled.

The [install guide](https://heptapod.watch/docs/getting-started/install/) explains every step and option, a Git checkout and a reverse proxy.

## Fresh VPS prerequisites

For a Git checkout, install Docker Engine and the Compose plugin from [Docker's apt repository](https://docs.docker.com/engine/install/ubuntu/):

```sh
sudo apt-get update
sudo apt-get install -y ca-certificates curl git jq openssl
sudo install -m 0755 -d /etc/apt/keyrings
sudo curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
sudo chmod a+r /etc/apt/keyrings/docker.asc
echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/ubuntu $(. /etc/os-release && echo "$VERSION_CODENAME") stable" | sudo tee /etc/apt/sources.list.d/docker.list > /dev/null
sudo apt-get update
sudo apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
sudo systemctl enable --now docker
sudo usermod -aG docker "$USER"
```

Docker group membership grants root-equivalent access. Log out and back in, then check access in the new session:

```sh
docker ps
```

- Continue only when `docker ps` works without sudo.
- Docker Compose v5 is required (tested 5.1.2). `docker compose config --help` must list `--environment`. The installer checks this.
- For a release bundle without Git, use the [export installation command](operations.md#install-a-release-export-without-git).

## Recover a failed first install

If the first install failed before the app started, the installer printed a recovery block ending in `# END INSTALL RECOVERY`.

1. Fix the reported image access, network or port error.
2. Paste the whole recovery block. It pins the verified release images, starts the services and creates the Owner.

:::caution
Do not run a plain `docker compose up` instead. Until the images are pinned, the production defaults pull the moving `:main` images.
:::

A rerun of the installer prints the same block while no release image is pinned.

## Start a stopped installation

On a stopped installation with pinned images, a rerun prints this start path instead of an upgrade. Run the Owner command only after the services are healthy:

```sh
sh -ec '. ./scripts/worker-memory-preflight.sh; require_worker_memory'
docker compose --env-file .env.prod up -d --wait
docker compose --env-file .env.prod exec -T app php artisan heptapod:user:create-owner --email=owner@example.com --if-missing --no-interaction
```

The installer never runs these commands itself. On an installation that is running, a rerun only validates `.env.prod`, reports keys it adds and prints the `./scripts/upgrade.sh` command.

## After the install

- Set up [backups](https://heptapod.watch/docs/running-it/backups/) and read [updating](https://heptapod.watch/docs/running-it/updating/) before the first upgrade.
- Restore, key rotation, worker sizing and password recovery are in the [operations reference](https://github.com/heptapodwatch/heptapod/blob/main/docs/deploy/operations.md).
- Gotenberg has no internet access. Its URL filter allows only inline data and uploaded temporary files, with JavaScript off.
- To capture test mail on a disposable install, use the [Mailpit recipe](operations.md#capture-mail-locally-with-mailpit).
