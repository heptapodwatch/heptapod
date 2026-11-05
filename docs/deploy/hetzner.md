# Hetzner deployment

Release images are `ghcr.io/heptapodwatch/heptapod-app` and `ghcr.io/heptapodwatch/heptapod-lighthouse`. The release's `compose.prod.yaml` defines the image defaults.

Ubuntu 24.04. For a browser-disabled prebuilt installation, start with CX23 (amd64, 2 vCPU / 4 GB RAM / 40 GB disk). For Lighthouse, use CX33 (amd64, 4 vCPU / 8 GB RAM / 80 GB disk). The arm64 alternatives, CAX11 (2 / 4 / 40) and CAX21 (4 / 8 / 80), require source builds while prebuilt images are amd64-only. The default installer enables browser services; pass `--without-browser` on a 4 GB host. Capacity depends on check mix and target latency, not site count alone; see the [capacity table and measured limits](operations.md#installation-and-capacity). PDF reports are not part of this release. Prebuilt application and Lighthouse images are amd64-only by default. Use an amd64 host for the prebuilt installation below; arm64 prebuilt installations require published arm64 images. Existing source-built arm64 instances can use `./scripts/upgrade.sh --build`. The repository variable `HEPTAPOD_IMAGE_ARCHES` enables `amd64,arm64` publication; it is not an installer setting. See [operations](operations.md) for image selection. Keep at least 12 GiB free on the Docker data filesystem: the installer reserves 6 GiB for downloading/unpacking images and 6 GiB for backup headroom. Larger databases or storage need more backup space.

## Fresh VPS prerequisites

Use a fresh Ubuntu 24.04 amd64 VPS with a sudo-capable account. Install Docker Engine and the Compose plugin from Docker's apt repository (https://docs.docker.com/engine/install/ubuntu/):

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

### Log in again before continuing

Docker group membership grants root-equivalent access. **Log out, log back in**, then confirm access in the new session:

```sh
docker ps
```

Continue only when this succeeds without sudo. Require Docker Compose v5 (tested 5.1.2); `docker compose config --help` must list `--environment`. The installer checks this before changing configuration.

## Download a release and install

Install v0.1.0 from the public repository. Pin an exact release tag rather than a moving branch such as `main`.

```sh
REPOSITORY_URL='https://github.com/heptapodwatch/heptapod.git'
RELEASE_TAG='v0.1.0'
INSTALL_DIR="$HOME/heptapod"
git clone --depth 1 --branch "$RELEASE_TAG" "$REPOSITORY_URL" "$INSTALL_DIR" && cd "$INSTALL_DIR" && ./scripts/install.sh --domain monitor.example.com --owner-email owner@example.com --without-browser
```

For a release archive without Git, extract it into an empty directory and use the [export installation command](operations.md#install-a-release-export-without-git) with the full published commit SHA supplied by the release publisher.

If the first clone failed, remove its partial destination directory before cloning again. Do not remove an existing installation or its data.

The clone downloads `scripts/install.sh`, the Compose files and the environment example from the same pinned release before executing the installer. Use an empty destination. Replace the domain and Owner email; omit `--without-browser` only on a host sized for browser services. An existing `.env.prod` makes the installer validate configuration and report any missing keys it appends, without starting or restarting services or creating an Owner. It checks `docker compose --env-file .env.prod ps -q app` read-only. If an app is running, use the printed `./scripts/upgrade.sh` instruction (add `--build` only for source-build installs).

If the first installation failed before the app started, fix the reported image-access, network or port error first. With no running app, the installer prints this explicit first-start path instead of an upgrade. Replace the email and run the Owner command only after services are healthy:

```sh
docker compose --env-file .env.prod up -d --wait
docker compose --env-file .env.prod exec -T app php artisan heptapod:user:create-owner --email=owner@example.com --if-missing --no-interaction
```

This also starts a configured but stopped instance. The installer never runs these commands on a rerun.

Before installation, point DNS A (AAAA only with working IPv6) at the VPS. In the Hetzner firewall, allow inbound TCP 22, 80 and 443 only. Caddy handles automatic TLS. Public registration is disabled. The installer creates the first verified Owner and prints its password once; use `--owner-email` or follow the prompt. See [operations](operations.md) for non-interactive installs and password recovery. The dispatcher, Horizon and scheduler run their production commands; worker sizing is documented there. For a disposable local install, the [Mailpit recipe](operations.md#capture-mail-locally-with-mailpit) captures test mail without sending it to real recipients.

Gotenberg has no internet network; its own URL filter permits only inline data and uploaded temporary files, with JavaScript disabled. Backups, upgrades, restore, key rotation, browser security and upstream CVEs: [operations](operations.md).
