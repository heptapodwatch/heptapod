# Heptapod

Self-hosted website monitoring for freelancers and small agencies, built with Laravel and React. Website and documentation: [heptapod.watch](https://heptapod.watch).

Release images are `ghcr.io/heptapodwatch/heptapod-app` and `ghcr.io/heptapodwatch/heptapod-lighthouse`. The release's `compose.prod.yaml` defines the image defaults.

## Instance documentation

Repository `docs/` is the source of truth for this release:

- [Installation, upgrades, backups and diagnostics](docs/deploy/operations.md), with a [Hetzner deployment example](docs/deploy/hetzner.md).
- [Check runs](docs/checks/runs.md), [broken links and mixed content](docs/checks/broken-links-and-mixed-content.md), and [sitemap limits](docs/checks/sitemap.md).
- [Notifications](docs/notifications.md), [maintenance](docs/maintenance.md), [API](docs/api.md), and [interface behavior](docs/ui.md).

The app does not serve an API guide at `/docs/api`. Operators distributing these guides must provide an accessible copy alongside their instance.

## Installation

Use an Ubuntu or Debian amd64 host, a domain pointing to it, inbound TCP 80/443, and at least 12 GiB free on the Docker data filesystem (6 GiB for image download/unpacking and 6 GiB for backup headroom). Then run:

```sh
curl -fsSL https://heptapod.watch/install.sh | sudo bash -s -- --domain monitor.example.com --owner-email owner@example.com --without-browser
```

The installer downloads the latest release bundle (`--version <tag>` pins one), verifies its SHA-256 checksum, publishes it as a release folder in `/opt/heptapod` (`--dir` changes that) and runs the bundle's `scripts/install.sh`. No Git is needed. Docker Engine with Compose v5 must be installed; if it is missing, the installer stops and prints the same command with `--install-docker`, which installs Docker from Docker's apt repository. Replace the domain and email. The browser-disabled path targets 4 GB hosts; see the [Hetzner guide](docs/deploy/hetzner.md) for browser-enabled sizing. A rerun on an existing installation starts nothing and prints the upgrade command.

Upgrade to the latest release, or to a chosen tag with `--to <tag>`:

```sh
cd /opt/heptapod && sudo ./scripts/upgrade.sh
```

### Manual installation from a Git checkout

First install Docker Engine, Docker Compose v5 (tested 5.1.2), Git and OpenSSL using the [fresh Ubuntu VPS prerequisites](docs/deploy/hetzner.md#fresh-vps-prerequisites).

After adding your account to the Docker group, **log out and log back in**. In the new session, confirm access before cloning:

```sh
docker ps
```

Continue only when this succeeds without sudo.

```sh
REPOSITORY_URL='https://github.com/heptapodwatch/heptapod.git'
RELEASE_TAG='v0.1.1'
INSTALL_DIR="$HOME/heptapod"
git clone --depth 1 --branch "$RELEASE_TAG" "$REPOSITORY_URL" "$INSTALL_DIR" && cd "$INSTALL_DIR" && ./scripts/install.sh --domain monitor.example.com --owner-email owner@example.com --without-browser
```

If the first clone failed, remove its partial destination directory before cloning again; never remove an existing installation or its data.

Use an exact published release tag, not a moving branch. The clone fetches the installer and its configuration before running it. Rerunning the installer preserves existing secrets and does not start or restart an instance with `.env.prod`; follow its printed first-start or upgrade instructions. To upgrade a Git checkout, check out the new tag first (`git fetch --tags && git checkout <tag>`), then run `./scripts/upgrade.sh`.

## Development

Inertia DevTools: set `INERTIA_DEVTOOLS_ENABLED=true` in your `.env`.

Install Docker with Compose. PHP 8.5.12 (FrankenPHP), Composer, Node 24 and aspell run in the containers; no host PHP or Node installation is needed.

```sh
cp .env.example .env
docker volume create heptapod-heavy-locks
docker compose up -d --wait
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate
```

For the demo data and a dev image labelled for `scripts/dev-up.sh`, follow the first start in [CONTRIBUTING.md](CONTRIBUTING.md).

The app installs Composer and npm dependencies at startup when their manifests or lockfiles change, or their installed dependencies are missing. Startup stamps live in `vendor` and `node_modules`; a checkout-scoped file lock serializes app startups and one-offs. Unchanged startups leave Laravel's cached manifests untouched. Other PHP/Node services wait for the app healthcheck. `vendor`, `bootstrap/cache`, and `node_modules` live in checkout-scoped Docker volumes shared by the PHP/Node services. Laravel's atomic cache writes stay on the container filesystem instead of the host bind mount. The tracked `bootstrap/cache/.gitignore` stays on the host. For host IDE dependency indexing, copy dependencies with `docker compose cp app:/workspace/vendor/. ./vendor`. Native Vite/oxc binaries are installed on Linux, not copied from the host. After changing `package-lock.json`, run `docker compose exec app npm ci` and restart Vite. After changing `composer.lock`, run `docker compose exec app composer install`.

- App: http://localhost:8088
- Vite: http://localhost:5188/vite/
- Mailpit: http://localhost:8028
- Browser CDP: http://localhost:9334

Set `APP_PORT`, `VITE_PORT`, `MAILPIT_PORT` and `BROWSER_PORT` in `.env` before starting another checkout. Set `APP_CPUS`, `HORIZON_CPUS`, and `FRANKENPHP_NUM_THREADS` to tune the development CPU and PHP worker limits; see [development test hooks](docs/deploy/operations.md#development-test-hooks). `APP_URL` uses `APP_PORT`. Compose derives the project name from the checkout directory: the main checkout and a `design` worktree get separate networks and volumes. Do not set a shared `COMPOSE_PROJECT_NAME`. All published ports bind to loopback; Postgres and Redis have no host port.

Postgres 17 initializes `DB_DATABASE` and a separate `heptapod_test` database on its first start. PHPUnit forces the test database; it must never use the app database. Changing Postgres credentials or database names requires an explicit data migration or recreating the volume, which deletes its data. `docker compose down` keeps data; `docker compose down -v` deletes it.

The `horizon` service runs Laravel Horizon; `dispatcher` runs `heptapod:dispatch` and `scheduler` runs `schedule:work`.

### Offline check fixtures

The `fixtures` Compose profile serves offline HTTP, crawl, health JSON, TLS and DNS scenarios. PHP tests require it; start it before the first test run with `docker compose --profile fixtures up -d --wait fixtures fixtures-alt dns-fixtures`. See the [fixture URL table and smoke-test command](docker/fixtures/README.md). Set `FIXTURES_HTTP_PORT` and `FIXTURES_HTTPS_PORT` in `.env` to override the default loopback ports 8480 and 8481. The default `FIXTURES_HTTPS_ALT_PORT` 444 is privileged: rootless Docker and Podman need a value of 1024 or above.

### Tooling

Run project commands through the app container. CI runs static checks on PHP 8.5.12, Node 24, Postgres 17, Redis and aspell. The PHP Gate, non-quarantined Feature/Unit tests, architecture tests and Vitest run in CI and block merges. Browser, mutation and coverage suites remain disabled. Run affected PHP tests with `docker compose exec app bash scripts/run-tests.sh focused <test-file>` or the non-quarantined Feature/Unit suites with `docker compose exec app bash scripts/run-tests.sh ci-php`; use random order and record the seed as shown in [operations](docs/deploy/operations.md#development-test-hooks). The type-coverage check remains part of the static gate.

```sh
docker compose exec app composer fmt             # Pint, write fixes
docker compose exec app composer fmt:check
docker compose exec app npm run check:fix        # Kit formatter, write fixes
docker compose exec app npm run check
docker compose exec app composer peck
docker compose exec app npm run lint
docker compose exec app npm run types:check
docker compose exec app composer analyse
docker compose exec app composer rector:check
docker compose exec app composer deps:check
docker compose exec app composer normalize:check
docker compose exec app composer validate --strict
docker compose exec app php artisan migrate --force --no-interaction
docker compose exec app php artisan wayfinder:generate --with-form
docker compose exec app composer types:generate
git diff --exit-code                           # Commit generated declarations
docker compose exec app composer test:types     # Static type coverage 100%
docker compose exec app npm run build
docker compose exec app composer audit
docker compose exec app npm audit --audit-level=high
```

Migrate before generating types or running Vite builds: Wayfinder reads route-binding types from the live schema. CI migrates its fresh database before either operation. Use the same locked dependencies, local route environment (`APP_ENV=local`) and current Postgres schema when committing generated artifacts; a warm database is not fresh-state CI proof.

Composer ignores only the abandoned status of `symplify/rule-doc-generator-contracts`, a transitive development dependency of `driftingly/rector-laravel`. Security advisories still fail the audit. TODO: Remove `config.audit.ignore-abandoned` when rector-laravel drops this dependency.

CI runs formatting, spelling, lint, TypeScript, static analysis, type coverage, generated-file comparison and dependency audits. Production image, deployment OPS and security gates are active. The PHP Gate, non-quarantined PHP tests, architecture tests and Vitest are blocking CI checks; Browser tests remain disabled. See [CONTRIBUTING.md](CONTRIBUTING.md) for the current contribution policy.

The development image gate runs when its inputs change. Hadolint permits current apt package versions on its install step so distro security fixes remain available. Development-image vulnerability findings are report-only. Production app and Lighthouse images have HIGH/CRITICAL gates on amd64 and arm64; official runtime image scans are report-only and require upstream review. See [operations](docs/deploy/operations.md) for image and capacity caveats.

The development image includes headless Chromium and Playwright's OS dependencies. The separate browser service supports manual CDP sessions.

### Git hooks and releases

```sh
docker compose exec app npm run hooks:install
./scripts/check-hooks.sh                       # Host Git boundary smoke test
./scripts/check-hooks.sh --commit-modes        # Clean detached disposable checkout + own stack only
```

Git runs on the host. Its generated Lefthook hooks load `scripts/hooks-rc.sh`, then `scripts/lefthook-container.sh` runs Lefthook, Pint, vite-plus and commitlint through `docker compose exec -T app`. No host PHP/Node/Lefthook is required. Pre-commit formats staged files only and stages fixes; commit-msg enforces Conventional Commits. Stage whole files: formatters read the working copy, so `stage_fixed` also stages unstaged hunks in those files. If the committing checkout's stack is down, hooks fail with `start the stack: docker compose up -d`; never bypass them.

Hooks forward Git's active index, including temporary indexes used by `git commit -a` and `git commit --only`. With `--only`, Git can leave the selected file's pre-format content staged in the normal index (`MM`); unrelated staged content remains unchanged and uncommitted.

Linked worktrees share the common Git hooks, but each uses its own Compose project. Before starting a linked worktree's stack, add `GIT_COMMON_DIR` to its `.env` with the absolute output of `git rev-parse --path-format=absolute --git-common-dir`. Compose mounts that directory at `/git-common` so the container can use the shared index. A normal clone needs no extra configuration. Each checkout that has `lefthook.yml` needs the hook scripts and installed dependencies. Older linked worktrees without that config print `no lefthook.yml on this branch, hooks skipped` and exit successfully before looking for any tools; installing hooks preserves this guard.

Renovate is inert until the Renovate GitHub app is installed. Dependabot is not configured. Renovate groups minor/patch updates and the vite-plus/Vite/Vitest ecosystem; other majors have separate PRs. FrankenPHP's exact tag and digest are updated by Renovate. Keep its PHP patch aligned with Composer's `config.platform.php` and CI's `php-version`. Release-please uses the manifest and `simple` release type: this full-stack application is not an npm or Composer library. `version.txt` is the application version, starting at 0.0.0 before the first 0.1 release; neither dependency manifest gets a library version. Release-please runs only through manual `workflow_dispatch` until the release policy is decided.

### Browser and developer login

```sh
export AGENT_BROWSER_SESSION=heptapod-dev
# Use the BROWSER_PORT value from your .env.
BROWSER_PORT=9334
agent-browser connect "$BROWSER_PORT"
agent-browser --cdp "ws://127.0.0.1:${BROWSER_PORT}" open http://heptapod-app:80/login
agent-browser --cdp "ws://127.0.0.1:${BROWSER_PORT}" wait --text 'Sign in'
agent-browser --cdp "ws://127.0.0.1:${BROWSER_PORT}" set viewport 1440 900
agent-browser --cdp "ws://127.0.0.1:${BROWSER_PORT}" screenshot /tmp/heptapod-login.png
```

Use `http://heptapod-app:80` inside the browser container, not `http://app:80`: Chrome upgrades the HSTS-preloaded `.app` hostname to HTTPS. The network alias avoids that upgrade.

FrankenPHP proxies `/vite/*` (including WebSockets) to Vite. A relative hot-file URL keeps assets and HMR on the page's origin, so both http://localhost:8088 and http://heptapod-app:80 work without hostname or CDP request rewriting. The direct Vite port is for diagnostics; open the app URL to use HMR.

Open http://localhost:8088/dev-login (or http://heptapod-app:80/dev-login inside the browser container) and select the demo account `test@example.test` to enter the dashboard. Spatie's login-link package creates the development user if needed. This page exists only in `APP_ENV=local`; the package also rejects non-local environments and hosts outside its explicit allowlist. The dependency is development-only. Never expose this development stack publicly.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md), the [Code of Conduct](CODE_OF_CONDUCT.md) and the [security reporting policy](SECURITY.md).

## License

[AGPL-3.0-or-later](LICENSE).
