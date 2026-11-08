# Development

Heptapod develops in Docker Compose. PHP 8.5.12 (FrankenPHP), Composer, Node 24 and aspell run in the containers, so you need no host PHP or Node.

## Set up a checkout

Install Git and Docker with Compose. The demo and smoke scripts also need Bash, curl, jq and standard Unix tools.

```sh
cp .env.example .env
docker volume create heptapod-heavy-locks
docker compose up -d --wait
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate
docker compose exec app npm run hooks:install
```

`heptapod-heavy-locks` is an external volume shared by all checkouts. Create it once; see [development test hooks](docs/deploy/operations.md#development-test-hooks).

## Seed demo data

The demo scripts need `COMPOSE_PROJECT_NAME` in `.env` set to this checkout's existing project name, shown by `docker compose config --format json | jq -r .name`. Never reuse another checkout's project name.

Build the development image with the source-input label the stale-image guard requires, then seed a scenario and check the main pages:

```sh
DEV_IMAGE_INPUT_HASH=$(./scripts/dev-image-hash.sh) docker compose build
./scripts/dev-up.sh attention
./scripts/smoke.sh
```

- `dev-up.sh` resets the checkout's database and queues. Never run it on a real instance or a checkout with production data.
- Demo login: `test@example.test` / `password`.
- Keep development ports local. Use synthetic data in examples, screenshots and reports.

## Services and ports

| Service     | Default URL                 |
| ----------- | --------------------------- |
| App         | http://localhost:8088       |
| Vite        | http://localhost:5188/vite/ |
| Mailpit     | http://localhost:8028       |
| Browser CDP | http://localhost:9334       |

- Set `APP_PORT`, `VITE_PORT`, `MAILPIT_PORT` and `BROWSER_PORT` in `.env` before you start another checkout. `APP_URL` uses `APP_PORT`.
- Compose derives the project name from the checkout directory, so the main checkout and a `design` worktree get separate networks and volumes. Do not share one `COMPOSE_PROJECT_NAME` between checkouts.
- All published ports bind to loopback. Postgres and Redis have no host port.
- `APP_CPUS`, `HORIZON_CPUS` and `FRANKENPHP_NUM_THREADS` tune the development CPU and PHP worker limits; see [development test hooks](docs/deploy/operations.md#development-test-hooks).
- The `horizon` service runs Laravel Horizon, `dispatcher` runs `heptapod:dispatch` and `scheduler` runs `schedule:work`.
- Inertia DevTools: set `INERTIA_DEVTOOLS_ENABLED=true` in `.env`.

## Dependencies

The app installs Composer and npm dependencies at startup when their manifests or lockfiles change, or when the installed dependencies are missing.

- Startup stamps live in `vendor` and `node_modules`. A checkout-scoped file lock serializes app startups and one-offs.
- Unchanged startups leave Laravel's cached manifests untouched. Other PHP/Node services wait for the app healthcheck.
- `vendor`, `bootstrap/cache` and `node_modules` live in checkout-scoped Docker volumes shared by the PHP/Node services. Laravel's atomic cache writes stay on the container filesystem instead of the host bind mount. The tracked `bootstrap/cache/.gitignore` stays on the host.
- For host IDE dependency indexing, copy dependencies with `docker compose cp app:/workspace/vendor/. ./vendor`.
- Native Vite/oxc binaries are installed on Linux, not copied from the host.
- After changing `package-lock.json`, run `docker compose exec app npm ci` and restart Vite. After changing `composer.lock`, run `docker compose exec app composer install`.

## Databases

- Postgres 17 creates `DB_DATABASE` and a separate `heptapod_test` database on its first start. PHPUnit forces the test database; it must never use the app database.
- Changing Postgres credentials or database names needs an explicit data migration or a recreated volume, which deletes its data.
- `docker compose down` keeps data; `docker compose down -v` deletes it.

## Offline check fixtures

The `fixtures` Compose profile serves offline HTTP, crawl, health JSON, TLS and DNS scenarios. PHP tests need it, and the test runner stops before any test when the fixtures are unreachable. Start them once per stack:

```sh
docker compose --profile fixtures up -d --wait fixtures fixtures-alt dns-fixtures
```

- `FIXTURES_HTTP_PORT` and `FIXTURES_HTTPS_PORT` in `.env` override the default loopback ports 8480 and 8481.
- The default `FIXTURES_HTTPS_ALT_PORT` 444 is privileged. Rootless Docker and Podman need a free port of 1024 or above.
- The [fixture URL table and smoke-test command](docker/fixtures/README.md) list every scenario.

## Tooling

Run project commands through the app container. CI runs static checks on PHP 8.5.12, Node 24, Postgres 17, Redis and aspell.

Run affected PHP tests with `docker compose exec app bash scripts/run-tests.sh focused <test-file>`, or the non-quarantined Feature/Unit suites with `docker compose exec app bash scripts/run-tests.sh ci-php`. Use random order and record the seed as shown in [development test hooks](docs/deploy/operations.md#development-test-hooks).

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

- Migrate before generating types or running Vite builds: Wayfinder reads route-binding types from the live schema. CI migrates its fresh database before either step.
- Commit generated artifacts with the locked dependencies, the local route environment (`APP_ENV=local`) and the current Postgres schema. A warm database is not fresh-state CI proof.
- Composer ignores only the abandoned status of `symplify/rule-doc-generator-contracts`, a transitive development dependency of `driftingly/rector-laravel`. Security advisories still fail the audit. Remove `config.audit.ignore-abandoned` when rector-laravel drops this dependency.

## CI

- CI runs formatting, spelling, lint, TypeScript, static analysis, type coverage, generated-file comparison and dependency audits. Production image, deployment OPS and security gates are active.
- The PHP Gate, non-quarantined Feature/Unit tests, architecture tests and Vitest block merges. Browser, mutation and coverage suites stay disabled. The type-coverage check is part of the static gate.
- The development image gate runs when its inputs change. Hadolint permits current apt package versions on its install step, so distro security fixes stay available. Development-image vulnerability findings are report-only.
- Production app and Lighthouse images have HIGH/CRITICAL gates on amd64 and arm64. Official runtime image scans are report-only and need upstream review. See [operations](docs/deploy/operations.md) for image and capacity caveats.
- The development image includes headless Chromium and Playwright's OS dependencies. The separate browser service supports manual CDP sessions.

## Git hooks

```sh
docker compose exec app npm run hooks:install
./scripts/check-hooks.sh                       # Host Git boundary smoke test
./scripts/check-hooks.sh --commit-modes        # Clean detached disposable checkout + own stack only
```

- Git runs on the host. The generated Lefthook hooks load `scripts/hooks-rc.sh`, then `scripts/lefthook-container.sh` runs Lefthook, Pint, vite-plus and commitlint through `docker compose exec -T app`. No host PHP, Node or Lefthook is needed.
- Pre-commit formats staged files only and stages the fixes. commit-msg enforces [Conventional Commits](https://www.conventionalcommits.org/en/v1.0.0/), for example `fix: preserve paused check state`.
- Stage whole files. Formatters read the working copy, so `stage_fixed` also stages unstaged hunks in those files.
- If the committing checkout's stack is down, hooks fail with `start the stack: docker compose up -d`. Never bypass them.
- Hooks forward Git's active index, including the temporary indexes of `git commit -a` and `git commit --only`. With `--only`, Git can leave the selected file's pre-format content staged in the normal index (`MM`); unrelated staged content stays unchanged and uncommitted.
- Linked worktrees share the common Git hooks, but each uses its own Compose project. Before you start a linked worktree's stack, add `GIT_COMMON_DIR` to its `.env` with the absolute output of `git rev-parse --path-format=absolute --git-common-dir`. Compose mounts that directory at `/git-common` so the container can use the shared index. A normal clone needs no extra configuration.
- Each checkout that has `lefthook.yml` needs the hook scripts and installed dependencies. Older linked worktrees without that config print `no lefthook.yml on this branch, hooks skipped` and exit successfully before looking for any tools; installing hooks keeps this guard.

## Dependency updates and releases

- Renovate is inert until the Renovate GitHub app is installed. Dependabot is not configured.
- Renovate groups minor/patch updates and the vite-plus/Vite/Vitest ecosystem; other majors get separate PRs.
- Renovate updates FrankenPHP's exact tag and digest. Keep its PHP patch aligned with Composer's `config.platform.php` and CI's `php-version`.
- Release-please uses the manifest and the `simple` release type, because this full-stack application is not an npm or Composer library.
- `version.txt` is the application version, starting at 0.0.0 before the first 0.1 release. Neither dependency manifest gets a library version.
- Release-please runs only through manual `workflow_dispatch` until the release policy is decided.

## Browser and developer login

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

- Inside the browser container, use `http://heptapod-app:80`, not `http://app:80`. Chrome upgrades the HSTS-preloaded `.app` hostname to HTTPS; the network alias avoids that upgrade.
- FrankenPHP proxies `/vite/*`, including WebSockets, to Vite. A relative hot-file URL keeps assets and HMR on the page's origin, so http://localhost:8088 and http://heptapod-app:80 both work without hostname or CDP request rewriting.
- The direct Vite port is for diagnostics. Open the app URL to use HMR.
- Open http://localhost:8088/dev-login (or http://heptapod-app:80/dev-login inside the browser container) and select the demo account `test@example.test` to enter the dashboard.
- On an allowlisted host the login page also shows "Sign in as demo". It returns to the page that sent you to the login, for example a deep link to a site's settings.
- Spatie's login-link package creates the development user if needed. The page exists only in `APP_ENV=local`; the package also rejects non-local environments and hosts outside its explicit allowlist. The dependency is development-only.
- Never expose this development stack publicly.
