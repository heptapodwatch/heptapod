---
title: Production operations
description: Install, upgrade, back up, and operate a Heptapod instance
sidebar:
    order: 2
---

Release images are `ghcr.io/heptapodwatch/heptapod-app` and `ghcr.io/heptapodwatch/heptapod-lighthouse`. The release's `compose.prod.yaml` defines the image defaults.

# Production operations

## Production image promotion

A `ci/*` image run publishes the loaded, validated native images under non-release `ci-<full SHA>` tags and records their config IDs, platform digests, index digest, run attempt and hard-job results in run-bound artifacts. Main promotes those indexes by digest only when the latest candidate run for its exact SHA is completed successfully, all native gates are green, and the registry matches the artifacts. Both platform digests and the main tags are verified after promotion; the summary records the source run and index digest. Missing or inconsistent evidence triggers an annotated full build, OPS and security fallback. A source rerun detected immediately before publication fails red without changing that job's tags; use "Re-run all jobs" (not "Re-run failed jobs") to reselect the source. The main-only manual `force_full_build` input exercises the full fallback and records the requesting actor. Upstream vulnerability reports remain report-only.

## Development fixture benchmark

Sitemap pacing is proven by dispatch spacing on a frozen test clock in `tests/Feature/SitemapPacingTest.php`, part of the application suite; the wall-clock 500-URL fixture benchmark was removed. Its last recorded measurement follows.

A local HTTP/1.1 fixture with 650 ms responses completed 500/500 URLs in 82.2 seconds at default and 105.9 seconds at slowest, with peak concurrency four. This used PHP 8.5.11, Guzzle 7.15.5 and CLI coverage enabled; parent-process CPU was about 52% of one core, excluding fixture and resolver child processes. Receiver arrival times and sender gate times use different sampled clocks. Those results are not a production capacity promise or a universal spacing guarantee under TLS/network jitter.

## API development verification and upgrade notes

Every API response in the feature suite is checked against the pinned OpenAPI 3.1.2 document with Spectator. The SDK contract test exercises 24 operations with the unchanged client through the Laravel kernel. `listCheckRuns` is a raw feature request because the installed SDK has no method for it. `syncCronChecks` is also a raw feature request: `ohdear-php-sdk` 4.7's `syncCronCheckDefinitions` expects `{data: [...]}`, while the pinned spec and `spatie/laravel-schedule-monitor` expect a bare array. Heptapod follows the spec. Its coverage assertion requires a successfully schema-validated response for every implemented operationId. Deliberately wrong object-root and array-root responses must fail validation.

The pinned specification is at `tests/Fixtures/OhDear/ohdear-openapi.yml`. The central, in-memory exception registry is `Heptapod\Testing\ApiSpec`; the pinned file is never modified. The central in-memory exception expands the pinned uptime frequency-unit enum because real Oh Dear responses return hour/day. Other check settings have no frequency-unit enum in the pinned spec.

Cron task endpoints use core controllers and actions with a `Heptapod\Contracts\CronTaskStore` seam implemented by the cron addon. This preserves the rule that addons import only core contracts.

Addon route providers register under `/api` with `auth:sanctum`, `RequireApiToken`, `throttle:api`, their resource-family abilities and core policies. Nested IDs must belong to the authorized monitor. Addon endpoints must use the same response validator and extend the tested operation table when they ship.

Spectator cannot validate an array-union root through its string-only root-type accessor. No implemented response has that schema shape. A validator failure is fatal; tests never skip it or count an unvalidated operation.

TODO: re-pin the spec and re-run the validation.

Upgrade backfill records open site pauses from `paused_at` and open disabled uptime checks from their `updated_at`; earlier resumed intervals cannot be reconstructed. The acknowledgement migration removes obsolete events/deliveries before dropping the two incident columns; rollback restores nullable columns but not deleted acknowledgement data.

An upgrade clears obsolete Owner/Admin client memberships and restricted flags (rollback does not restore discarded memberships).

Migration `2026_10_12_001148_retain_deleted_site_notification_history` has a lossy rollback: it drops retained site/client/check identity snapshots, and reapplying it backfills current names rather than the original labels. Redacted secrets cannot be restored. Rollback refuses while detached deleted-site or component notification history exists; keep the migration or restore a pre-deletion backup.

## Checks stop finishing after an engine restart

`checks_not_running` opens when work has remained outstanding for more than ten minutes and no check has finished in that window. The scheduler evaluates it every five minutes and sends system issue mail synchronously, without Horizon. Owners and opted-in Admins receive the opening and recovery. Maintenance mode, paused sites, disabled or unavailable checks and intervals between scheduled runs suppress this signal. A fresh instance gets its first completion window from its first due/dispatch time.

The scheduler adds `check_age_seconds` to `INSTANCE_HEARTBEAT_URL`: integer seconds since the later of the last finished run and the first outstanding work becoming due, or the literal `null` when no completion is expected. Reading this signal requires Postgres before the ping is sent. If Postgres is unavailable, the command fails and Sentinel can report a missing scheduler heartbeat even while the scheduler process is alive. Sentinel reports a separate checks outage after two consecutive reports above 900 seconds. A report at or below 900, or explicit `null`, recovers it. Legacy pings still prove scheduler liveness. Invalid reports also prove liveness, but two consecutive invalid/failing reports produce a checks outage with reason `invalid check age report`; Sentinel returns 422 and the application logs the status without the secret URL.

After an engine/package upgrade, verify all worker roles, not only the web health endpoint. Using the `dc` helper below:

```sh
dc ps -a horizon scheduler dispatcher
dc exec -T horizon php artisan horizon:status
dc logs --since 30m --timestamps horizon scheduler dispatcher
```

If Horizon is absent, a restart policy cannot recreate it. First check that no install, upgrade or rollback is in progress in `.heptapod/`; resume that lifecycle operation rather than starting mixed revisions. Otherwise recreate only the missing worker from the instance's current Compose configuration:

```sh
dc up -d --no-deps --wait --wait-timeout 120 horizon
dc exec -T horizon php artisan horizon:status
```

Confirm a check finishes and the system issue resolves on the next evaluation. Do not flush queues, remove volumes or recreate the other services as a recovery shortcut.

Horizon needs its 1245-second stop grace to drain a maximum-budget check. Without live restore, daemon shutdown stops containers. Shutdown-limit hypothesis: the engine/package restart may have been interrupted while draining Horizon, potentially by systemd's service stop timeout. Docker documents `dockerd --shutdown-timeout` with a 15-second default, but that alone does not establish a 15-second cap on a container with a longer stop timeout. Confirm the engine's effective shutdown behavior and systemd's `TimeoutStopUSec` and `KillMode` before attributing the failure. `unless-stopped` does not revive containers recorded as stopped, and neither `always` nor `unless-stopped` recreates a removed container. A long drain interrupted by an engine upgrade is a possible contributor to a stopped worker, not evidence that Docker removed it. Do not shorten the check drain or change restart policy to claim a removal fix.

For a disappearing worker, retain root-readable Docker and containerd journals around the package upgrade, `/var/log/apt/history.log`, `/var/log/apt/term.log`, `/var/log/dpkg.log`, the effective Docker service unit and drop-ins, the output of `systemctl show docker.service containerd.service -p TimeoutStopUSec -p KillMode`, `/etc/docker/daemon.json`, and any retained Docker `stop`, `die`, `destroy` and `create` events. Capture the package runner's logs and audit/process-accounting records for Compose removal/prune commands too. A `destroy` event establishes removal; the journals and caller records are needed to attribute it. Missing journals cannot distinguish an engine failure from an external cleanup or Compose command.

Operators can evaluate [Docker live restore](https://docs.docker.com/engine/daemon/live-restore/) for supported patch upgrades; it requires compatible daemon options and does not cover every upgrade. See [restart-policy semantics](https://docs.docker.com/engine/containers/start-containers-automatically/) and [daemon shutdown configuration](https://docs.docker.com/reference/cli/dockerd/). Rootless Podman is daemonless: restarting a disposable Podman container can verify its stop/restart behavior, but cannot reproduce Docker/containerd's package-upgrade shutdown path.

## Check freshness and history retention

A `check_not_fresh` system issue opens when a check has waited for a result longer than its threshold: `HEPTAPOD_FRESHNESS_INTERVAL_MULTIPLIER` (default 3) times the check interval, clamped to the queue's minimum and maximum. All values are integer seconds and are validated at boot: each minimum must cover the queue's claim and running lease and must not exceed its maximum. Changes need a config cache rebuild and a restart of the app roles.

| Variable                                 | Default | Meaning                                              |
| ---------------------------------------- | ------- | ---------------------------------------------------- |
| `HEPTAPOD_FRESHNESS_INTERVAL_MULTIPLIER` | `3`     | Missed intervals before a check counts as not fresh. |
| `HEPTAPOD_FRESHNESS_FAST_MIN_SECONDS`    | `900`   | Lowest threshold for `checks-fast` checks.           |
| `HEPTAPOD_FRESHNESS_FAST_MAX_SECONDS`    | `21600` | Highest threshold for `checks-fast` checks.          |
| `HEPTAPOD_FRESHNESS_CRAWL_MIN_SECONDS`   | `7200`  | Lowest threshold for `checks-crawl` checks.          |
| `HEPTAPOD_FRESHNESS_CRAWL_MAX_SECONDS`   | `21600` | Highest threshold for `checks-crawl` checks.         |
| `HEPTAPOD_FRESHNESS_BROWSER_MIN_SECONDS` | `7200`  | Lowest threshold for `checks-browser` checks.        |
| `HEPTAPOD_FRESHNESS_BROWSER_MAX_SECONDS` | `21600` | Highest threshold for `checks-browser` checks.       |

`MONITORING_HOURLY_ROLLUP_DELETION_ENABLED` defaults to `false`. While it is false, `heptapod:prune-history` keeps hourly rollups older than `MONITORING_HOURLY_ROLLUP_RETENTION_DAYS` (92), so `check_run_rollups_hourly` and the database disk grow without bound. Review `dc exec app php artisan heptapod:prune-history --dry-run` on the instance, then set it to `true` to delete hourly rows whose day is covered by a daily rollup.

## Development test hooks

Application tests run in CI. The PHP Gate, non-quarantined Feature/Unit tests, architecture tests and Vitest checks block merges. Browser, mutation and coverage suites remain disabled. Static checks, including `composer test:types`, and production image, deployment OPS and security gates remain active.

During a rebase with conflicts only in generated Wayfinder files, `resources/js/types/generated.d.ts`, or the `composer.lock` content hash, run `scripts/rebase-regenerate.sh` from the checkout root with the Compose app running. It takes the current branch's version, regenerates routes and types inside the app container, refreshes the lock hash if needed, stages generated files, and continues the rebase. Any non-generated conflict or lockfile dependency change stops the script without resolving conflicts; resolve those by hand before retrying.

The development Compose stack limits the app to `APP_CPUS=4` CPU cores and Horizon to `HORIZON_CPUS=2` by default. The app also runs FrankenPHP with `FRANKENPHP_NUM_THREADS=4` worker threads instead of its CPU-based default. Set these values in `.env` to tune a local stack; these limits do not change the production configuration. Recreate the services with `docker compose up -d --force-recreate app horizon` after changing them. Check the applied quotas with `docker inspect $(docker compose ps -q app) --format '{{.HostConfig.NanoCpus}}'` (4 cores = 4000000000) and likewise for Horizon (2 cores = 2000000000).

Before the first development `docker compose up`, create the shared external lock volume once: `docker volume create heptapod-heavy-locks`. Do not remove it while other worktrees run tests. `HEPTAPOD_HEAVY_SLOTS` defaults to 3 shared slots across worktrees (allowed: 2–4); another run waits and prints `waiting for heavy slot …`. Set the same value in each checkout's `.env` and export it for the host wrapper. Local `composer test` omits line coverage. The test runner removes orphaned browser servers before starting and on exit; the CI checks hook also removes them on exit. Pest Browser 5.1's Playwright shell launcher can leave its Node child running after a successful test, so the test runner terminates the test process group and both hooks sweep detached test servers (PPID 1). For host image builds and deployment proofs, hold the same slot with `scripts/with-heavy-slot.sh docker build …` or `scripts/with-heavy-slot.sh sh tests/Deployment/ops.sh`.

The pre-push hook runs `bash scripts/ci-checks.sh` with a 20-minute timeout, including time spent waiting for detached processes holding its output pipe. A timeout fails the hook, names the step, and dumps container processes with PID, PPID, and PGID; do not treat a timed-out push as successful. CI runs static checks, generated-file comparison and blocking PHP and Vitest tests on the exact candidate revision; the Browser job remains disabled. Local pre-push runs static checks, not the application suite. Run `bash scripts/lefthook-container.sh run pre-push` from the checkout to verify the code-check hook before pushing. Run tests inside the checkout's app container. `composer test`, `composer test:tia`, and the pre-push test hook refuse to overlap in the same checkout. Wait for the active run to finish before retrying. The test runner's explicit `pre-push` mode stops after 15 minutes and prints the test log and parallel worker output; it is not wired to the Git pre-push hook. Browser tests run serially after the parallel PHP suites: Pest Browser 5.1 shares `vendor/pestphp/pest-plugin-browser/.temp/playwright-server.json` across processes, so concurrent runs can delete each other's Playwright connection state. Avoid starting separate `vendor/bin/pest` runs in the same checkout while another run is active. `composer test:tia` and pre-push use Pest TIA without CLI suite selection: the default PHP suites run in parallel, then Browser runs serially through `phpunit.browser.xml` with TIA. Each suite has its own ignored graph (`.pest/tia` and `.pest/tia-browser`); only the Browser invocation receives `HEPTAPOD_TIA_BROWSER=1`. Pest rebuilds for structural inputs such as `composer.lock` and `phpunit.xml`; the script additionally rebuilds both graphs when fixture files or `phpunit.browser.xml` change. A rebase alone does not force a rebuild. Changes to root/addon migrations or addon translations/views bypass TIA and run both suites in full because Pest's dependency graph cannot reliably attribute those files to every dependent test. The runner prints the triggering paths. Move both graph directories away when a full, non-replayed pre-push run is required for other changes. The first run after a fresh graph executes both suites fully. Pre-push recreates the HTTP fixtures to load current routes. Composer test commands refuse stale fixtures; run `docker compose --profile fixtures restart fixtures fixtures-alt` and retry. After a rebase or fixture change, restart fixtures before raw `vendor/bin/pest`: `docker compose --profile fixtures restart fixtures fixtures-alt dns-fixtures`. For excluded performance benchmarks, run `docker compose exec app bash scripts/run-tests.sh benchmark <test-file>`; additional Pest arguments pass through. The benchmark uses the same lock, process cleanup, and 15-minute timeout.

For affected tests, use `docker compose exec app bash scripts/run-tests.sh focused <pest args...>`. This mode requires explicit Pest arguments and keeps the checkout lock, shared heavy slot, process cleanup, and 15-minute timeout. Use `--parallel` for parallel PHP tests; focused mode pins the process count to four, overriding caller-supplied counts. Run Browser tests serially with `-c phpunit.browser.xml`. In linked worktrees, add Compose exec environment overrides `-e GIT_DIR=/git-common/worktrees/<worktree-name> -e GIT_WORK_TREE=/workspace` before `app`.

Run affected PHP tests or the non-quarantined Feature/Unit suites through the app container, in random order, and record the seed. Do not retry a failed run:

```sh
docker compose exec app bash scripts/run-tests.sh focused tests/Feature/ImportOhDearRealDataTest.php --order-by=random --random-order-seed=110401
docker compose exec app bash scripts/run-tests.sh ci-php --order-by=random --random-order-seed=110402
```

Two development-only seams are ignored when `APP_ENV=production`. `HEPTAPOD_DEV_FAIL_AFTER_TRANSITION=<system issue kind>` (unset by default) throws after that kind's transition and before its delivery rows. `HEPTAPOD_DEV_MAIL_DEAD_AFTER_SECONDS` (default `3600`) shortens how long `mail_transport_dead` waits without a successful send; production always uses one hour.

Addons declare required system binaries in `addons/<name>/composer.json` under `extra.heptapod.system-requirements` (for example, `["dig"]`); CI verifies them in every configured native production app architecture and the development image.

Requires Docker Compose v5 (tested 5.1.2). The installer checks that `docker compose config --help` lists `--environment` before creating or changing `.env.prod`; an unsupported installation aborts without changing the file or its permissions.

Run from the checkout. Commands below use:

```sh
dc() { docker compose --env-file .env.prod "$@"; }
```

## Installation and capacity

Install a published release on Ubuntu or Debian (amd64) with the one-line installer. It needs no Git:

```sh
curl -fsSL https://heptapod.watch/install.sh | sudo bash -s -- --domain monitor.example.com --owner-email you@example.com --without-browser
```

It checks the OS, architecture and root access, then resolves the latest release from the GitHub API (or `--version v0.1.0`). It downloads that release's bundle `heptapod-<tag>.tar.gz` and its `.sha256` from the release assets, refuses a checksum mismatch, unpacks the bundle into `/opt/heptapod` (or `--dir`) and runs the bundle's `scripts/install.sh --revision <REVISION>` with your flags. The bundle holds the Compose files, `.env.prod.example`, the lifecycle scripts, these deployment docs, a `MANIFEST` listing every file, a `RELEASE` file with the tag and a `REVISION` file with the release's full commit SHA; the images hold the application. Docker Engine with Compose v5, curl, tar, flock, OpenSSL and jq must be installed. If Docker is missing, the installer stops and prints the same command with `--install-docker`, which installs Docker Engine and the Compose plugin from Docker's apt repository (the [Hetzner prerequisites](hetzner.md#fresh-vps-prerequisites) steps). It refuses a non-empty `--dir` that is not an earlier bundle installation. Before it writes into `--dir`, it refuses a `.heptapod` or `.heptapod/lifecycle.lock` that is a symlink or owned by another user (install, upgrade and rollback refuse the same), takes that directory's lifecycle lock (`.heptapod/lifecycle.lock`), checks the directory again and moves the files in by rename; the installed `scripts/install.sh` takes over the held lock, so a concurrent installer, upgrade or rollback of the same directory refuses instead of interleaving. On an existing installation it downloads nothing: it runs the installed `scripts/install.sh`, which validates the configuration and prints the upgrade or first-start command. The installation directory belongs to root: run lifecycle scripts with `sudo`, and Compose commands as `sudo docker compose --env-file .env.prod …` in that directory. Other `scripts/install.sh` flags (`--app-url`, `--behind-proxy`, `--port`) pass through.

`./scripts/install.sh --domain monitor.example.com` creates a mode-0600 `.env.prod` from the example if missing. OpenSSL generates missing DB/backup passwords, Reverb app ID/key/secret, and a 32-byte Laravel APP_KEY on the host; existing secrets are preserved. Docker Compose parses managed keys (APP_KEY, DB_PASSWORD, BACKUP_ARCHIVE_PASSWORD, REVERB_APP_ID, REVERB_APP_KEY, REVERB_APP_SECRET, APP_URL, SERVER_NAME and mode keys) in spaced, exported, quoted and colon-delimited forms. The installer appends a value only when Compose resolves it empty and the file does not mention its name. Empty definitions, multiple lines mentioning a key, and conflicting shell overrides abort before changing an existing file or its permissions; the file is never sourced as shell. Remove an empty definition to allow generation. Other variables, including REDIS_PASSWORD, are not edited. Repeating installation with the same domain preserves configuration without rotating keys. A different configured domain aborts; edit APP_URL and SERVER_NAME in `.env.prod` before changing domains. The script requires the domain argument. With `--owner-email you@example.com`, it creates the first verified Owner after startup and prints a generated 32-character password **once**; store it in a password manager. It uses letters, digits, and the symbol `-` so console formatting cannot alter it. Generated passwords use local strength validation without a breach-service request; `--password-stdin` applies the full production password policy, including its breach check. Without this flag, it prompts for the Owner email on a TTY (empty input skips). Without a TTY, it skips Owner creation and prints the command to run later. On a rerun with an existing `.env.prod`, services are not started or restarted and Owner creation is skipped. Existing secrets are preserved; only missing configuration keys are appended and listed by name. An unchanged configuration remains byte-identical. A read-only `docker compose --env-file .env.prod ps -q app` check chooses the printed next step: upgrade a running app, or explicitly start a configured instance with no running app. Use `./scripts/install.sh --help` for all installation flags. To create the first Owner later, run `dc exec -T app php artisan heptapod:user:create-owner --email=you@example.com --no-interaction`. To supply your own password without putting it in shell history or process arguments, pipe it to `dc exec -T app php artisan heptapod:user:create-owner --email=you@example.com --password-stdin --no-interaction` (a trailing newline is removed). Invalid/taken emails and a second Owner fail; the command does not support additional Owners. If locked out, use **Forgot password** on the login page: Fortify emails a reset link to the Owner's address. Restore email delivery or a backup if that mailbox is unavailable; the installer never rotates an existing Owner's password. Compose starts with `up -d --wait`; only the app entrypoint migrates, under a PostgreSQL session advisory lock. Migration/lock failures exit nonzero before the HTTP server starts. Worker services wait for app health. The source-rebuild sequence below is for an initialized checkout only: installation must create `.env.prod` and its required secrets first. Allow additional disk space for source builds.

### Install a release export without Git

The one-line installer automates this. To do it by hand, extract the release bundle into an empty directory; its `REVISION` file holds the full 40-character lowercase commit SHA. For another export, obtain that SHA from the release publisher, not a branch name or an abbreviated SHA. The registry must contain images for that same published commit:

```sh
RELEASE_REVISION=$(cat REVISION)
./scripts/install.sh --domain monitor.example.com \
    --owner-email you@example.com --without-browser \
    --revision "$RELEASE_REVISION"
```

A Git checkout still selects its own HEAD and requires a clean tree. In an export, missing revision information fails with a recovery block that accepts the full SHA without Git. If image access fails, fix it and paste the complete printed recovery block; it retains `--revision`'s value and pins verified image digests before startup. If no revision was supplied, replace the marked SHA placeholder first. Rerunning the installer with the original flags only validates the existing configuration and prints the next step; it does not start services. Subsequent `scripts/upgrade.sh` upgrades need the `REVISION` file of a release bundle or a Git checkout.

If the first installation fails before the app starts, fix the image-access, network or port error, then rerun the installer with the original flags. It validates configuration without starting services and prints the commands below when no app is running. Replace the email; create the first Owner only after the health wait succeeds. The same explicit path can start a configured but stopped instance:

```sh
docker compose --env-file .env.prod up -d --wait
docker compose --env-file .env.prod exec -T app php artisan heptapod:user:create-owner --email=you@example.com --if-missing --no-interaction
```

If the app is running, use `./scripts/upgrade.sh` instead (add `--build` only for source-build installs). The installer never executes either path on a rerun.

```sh
./scripts/install.sh --domain monitor.example.com --owner-email you@example.com
APT_SNAPSHOT_DATE=$(date -u +%F) dc build --pull app lighthouse
dc up -d --wait --wait-timeout 300
```

Per-service default caps total 5878 MiB with browser services and 3446 MiB without them, with swap disabled. 8 GiB is the recommended host size. 4 GiB is the minimum: it runs without browser services and needs the small memory profile below. App, Horizon, dispatcher, scheduler and Redis caps include at least 25% headroom over the rebased production proof's observed peaks.

| Service    | Memory cap (MiB) |
| ---------- | ---------------: |
| App        |              384 |
| Horizon    |             1990 |
| Reverb     |              192 |
| Dispatcher |               96 |
| Scheduler  |              320 |
| Postgres   |              384 |
| Redis      |               80 |
| Lighthouse |             1536 |
| Proxy      |              128 |
| Gotenberg  |              768 |

Horizon runs one master, five fixed supervisors and six workers by default: two isolated `checks-fast`, one `checks-crawl`, one dedicated `checks-browser`, one shared `notifications,reports`, and one dedicated `broadcasts`. `HEPTAPOD_CRAWL_CONCURRENCY` sizes only the crawl pool. The browser supervisor stays at one worker because the Lighthouse service admits one audit at a time. `HORIZON_MEMORY_LIMIT` sets the shared Horizon service cap (both variables live in `.env.prod`):

| Host RAM                | Profile | Crawl processes | `HEPTAPOD_CRAWL_MEMORY_LIMIT_MB` | `HORIZON_MEMORY_LIMIT` | All-service caps without/with browser |
| ----------------------- | ------- | --------------: | -------------------------------: | ---------------------: | ------------------------------------: |
| 4 GiB (minimum)         | small   |               1 |                              512 |                  1670m |                       3126 / 5558 MiB |
| 8 GiB (recommended)     | default |               1 |                              768 |                  1990m |                       3446 / 5878 MiB |
| 8 GiB or more, 2 crawls | default |               2 |                              768 |                  2990m |                       4446 / 6878 MiB |

A fresh installation reads the host's RAM. Below 6 GiB it writes the small profile into `.env.prod` and prints one line that names both keys; from 6 GiB it writes the default profile. Reruns and upgrades never change these values. Install and upgrade refuse before maintenance and migrations when the enabled services' caps exceed the host's RAM minus a 512 MiB reserve for the kernel, Docker and system services, or when `HORIZON_MEMORY_LIMIT` is below the budget for `HEPTAPOD_CRAWL_MEMORY_LIMIT_MB` and `HEPTAPOD_CRAWL_CONCURRENCY`. Both refusals print the values to set.

The Horizon cap follows a budget for the fixed worker topology. An idle Horizon container uses about 56 MiB per PHP process (782 MiB for its 14 processes). Each crawl worker runs with its own PHP memory limit, `HEPTAPOD_CRAWL_MEMORY_LIMIT_MB` (768 by default, 512 in the small profile), and the budget adds 25% headroom. One measured 2,000-page crawl with 17,561 collected URLs reached 712 MiB in the crawl worker, beside a Lighthouse audit and uptime checks. Estimated practical page limits at that link density (about 0.32 MiB per page above an idle worker of 78 MiB, from this measurement only): about 2,150 pages with the default 768 MiB limit and about 1,350 pages with the small profile's 512 MiB. Sites with more links or resources per page reach the limit sooner. A larger crawl stops at its PHP limit: the run finishes as a check error whose cause names `HEPTAPOD_CRAWL_MEMORY_LIMIT_MB` and its current value, the queued job is removed, and the other workers keep running. A measured 10,000-page crawl ended this way at 768 MiB under the default 1990m cap, with zero kernel OOM kills while uptime checks kept completing. Lower `max_pages` or exclude sections for such a site. Install and upgrade check the budget with the new image and the configured cap before maintenance and migrations, and refuse with the required value. The worker also refuses to start when `HORIZON_MEMORY_LIMIT` is below the budget of its topology; `php artisan heptapod:worker-memory` runs the same check. Raise `HORIZON_MEMORY_LIMIT` before you raise `HEPTAPOD_CRAWL_CONCURRENCY`. Queue order gives notifications priority over reports; a running report is not preempted. The dispatcher runs `heptapod:dispatch`, and the scheduler runs `schedule:work`. Horizon gets 1245 seconds to stop gracefully. `heptapod:system-health` reports anon plus kernel memory as a percentage of the worker cgroup limit (excluding reclaimable file cache): above 80% warns; absent/expired telemetry or an unavailable cgroup limit is shown as not-configured, never healthy.

The pre-live capacity proof used a shared 2-vCPU/4-GiB cgroup with 100 real dispatched uptime checks per minute, a 100-section/1000-row page audited by the Lighthouse service, and a four-page/100-site PDF. The shared-cgroup peak was 2766 MiB with zero OOM kills. Uptime queue p95 was 3.858 seconds at baseline and 4.037 seconds under concurrent load (maximum 4.374 seconds), below the 60-second queue-wait budget without starvation. Crawl jobs and the new Reverb/broadcast worker under that concurrent load are not measured.

Lighthouse's measured heavy-page ceiling is the 100-section/1000-row/SVG fixture: three consecutive audits succeeded at the 1536 MiB cap with zero OOM kills. Its cgroup reached the cap and recorded 60 transient reclaim events; after auditing, usage fell to 358 MiB (33 MiB anonymous memory and 325 MiB file cache). Cache reclaim is expected. A 10,000-row fixture exceeded the former 1408 MiB cap and returned 502; do not shrink Lighthouse to 1088 MiB. Larger pages can exhaust the service cap without consuming Postgres's budget. An OOM produces an errored Lighthouse run when the addon is installed, handled by the engine's errored-check alert.

The app at 384 MiB still needs measurement under concurrent UI/API traffic.

## Live stream contract

Production Compose sets `BROADCAST_CONNECTION=reverb` and the private `REVERB_HOST=reverb`, `REVERB_PORT=8080` and `REVERB_SCHEME=http` for every role, including legacy `.env.prod` files. The installer generates `REVERB_APP_ID`, `REVERB_APP_KEY` and `REVERB_APP_SECRET` once; upgrade generates only missing credentials before maintenance. On an RC installation without `COMPOSE_FILE`, pull the new production app image before rerunning the installer with the original flags and any original Compose overrides. Rerunning the installer with an old image starts a Reverb role that image cannot run. Then run `./scripts/upgrade.sh`; it preserves existing secrets and generates absent ones. For a source-built installation, build the new app image instead of pulling it. Keep the secret private and never reuse the development credentials. `REVERB_HOST`, `REVERB_PORT` and `REVERB_SCHEME` are the app's **internal publishing** address; do not point browsers at the service's private hostname. The HTML carries only public client settings: key, WebSocket host, port, scheme and optional URL path prefix. It reads those settings from Laravel's runtime configuration, so one prebuilt image works across installations; the secret and app ID are never rendered. Browser host/port/scheme default to its current page origin (even when a reverse proxy changes upstream request headers), and the path prefix defaults to empty (`/app/{key}`). When clients must use a different origin or a path-rewriting proxy, set `REVERB_PUBLIC_HOST`, `REVERB_PUBLIC_PORT`, `REVERB_PUBLIC_SCHEME` (`http` or `https`) and `REVERB_PUBLIC_PATH` (leading `/`). Leave them unset for same-origin Caddy and Tailscale Serve. The dedicated Reverb process runs as a restartable Compose service with a TCP readiness probe and a 192 MiB memory cap. Caddy routes only `/app/*` (WebSocket upgrade) to `reverb:8080` on the private backend network. The signed `/apps/*` publishing API stays private; Laravel reaches it at `reverb:8080`. Neither Reverb nor its credentials have a published host port. WebSocket origins default to the `APP_URL` hostname; `REVERB_APP_ALLOWED_ORIGINS` adds comma-separated trusted hostnames for another proxy/review domain (no scheme or port, `*` is rejected). `REVERB_APP_MAX_CONNECTIONS` defaults to 500 to bound anonymous socket use; size it below your process file-descriptor limit. Standard Caddy reverse_proxy preserves WebSocket upgrades; external proxies must also forward Upgrade and Connection headers. `dc exec app php artisan heptapod:system-health` reports Reverb's private TCP reachability (`ok`, `down`, or `not-configured`); it is a liveness probe, not an authenticated subscription test. Diagnose a dead stream with `dc ps reverb`, `dc logs reverb` and `dc exec reverb php artisan reverb:restart --no-interaction`; clients poll when disconnected and catch up on reconnection.

The UI subscribes once in the app shell. Owners, admins and all-scope members receive the `presence-instance` channel; restricted members receive only their assigned `presence-client.{id}` channels. Laravel authorizes each presence subscription and exposes only numeric member IDs, without names or email addresses. Legacy `private-instance` and `private-client.{id}` authorization is rejected with 403; clients must use presence subscriptions. Scope changes terminate the account's current Reverb sockets; reconnection reauthorizes each channel. Broadcast events enter the dedicated Redis `broadcasts` queue after database commit. Its worker blocks on Redis for at most one second with no idle sleep, so new jobs wake it without busy-polling and delayed retries still mature. Queue-enqueue errors are reported without blocking claimed runs. Each delivery gets one attempt: unreachable Reverb is reported at most once per minute and the disposable signal completes without a failed-job entry; unexpected publishing errors still fail the job. Missing Reverb credentials do not block Composer, Artisan or check claims: the signal job reports that configuration error once per minute and completes, while system health reports `not-configured` until the installer or upgrade supplies credentials. Channel policies register only on a broadcast authorization request, so route/type generators work without Reverb credentials and `php artisan channel:list` is empty before authorization. Without credentials, `/broadcasting/auth` returns 503 rather than an internal error. Five development Compose trials of an authenticated private subscription and post-commit signal through Caddy and Horizon reached the socket in 7–16 ms; production latency depends on load. The HTTP/Inertia request remains the authorized source of data: socket messages carry no site names, target URLs, config, secrets, results or evidence.

Every `.LiveSignal` carries `{topic, ids, at}`. `ids` contains numeric `site`, `check`, `incident` or `client` IDs when relevant; site changes include both site and client IDs so pages can ignore other clients. `at` is an ISO-8601 server timestamp. `state.changed` requests only the affected Inertia props. The run topics `run.queued`, `run.started`, `run.progress`, and `run.finished` additionally carry `run: {check_id, phase, queue_position, progress_n, progress_total, started_at, actor}`. `run` updates one check-keyed client store and never triggers a prop reload. `queue_position`, `progress_n`, `progress_total`, `started_at` and `actor` are nullable. WP runs must supply non-null progress/position only when the execution lifecycle can determine them; never put unredacted executor output or an actor email in `actor`.

Loss of the socket for 10 seconds marks the stream lost. Visible pages fall back to 15-second prop polling and reload once on reconnection; hidden pages wait until visible. Added, removed or reordered table rows wait behind the reader's “new changes” button rather than moving while someone is reading; existing rows still receive fresh status values. Without a configured socket key, the UI reports offline and polls on the same interval.

## Secret configuration

- Secret fields in every addon and notification channel schema require at least four characters after trimming surrounding whitespace. Credential-header values use the same rule. Stored values remain unchanged: `   ` and `  ab  ` are invalid; `abcd` is valid and keeps its spaces. An optional secret set to `''` is removed; an empty credential-header value removes that header. Required secrets cannot be empty. Omission, `null`, and `********` preserve an existing secret at write boundaries.
- Error redaction uses literal, longest-first substring replacement over raw, JSON-escaped, `rawurlencode`, and `urlencode` variants. Replacement masks are not processed again. Case-insensitive matching is ASCII-only, as with PHP `str_ireplace`; different Unicode letter cases are not equivalent.
- A monitor host change requires explicit re-entry of each stored credential. Missing, `null`, or masked values return 422 with field errors and leave the monitor and checks unchanged. Explicitly clearing optional secrets or header lists in the same request is allowed; required secrets still need four or more characters. A path-only change on the same origin preserves credentials. The origin includes scheme, case-insensitive host, and effective port. Schema-marked addon target URLs use the same rule. Rejection avoids silently copying or deleting credentials. Uninstalled checks are exempt because they cannot execute; their encrypted credentials remain stored. Review those credentials before reinstalling and enabling the addon.
- One-run header overrides use the core secret validation rules. Empty rows are ignored; an empty override list retains the configured headers.
- Monitor URLs cannot contain credentials. Supply an Authorization header instead. Create and update reject URL userinfo with a localized field error.
- URL uniqueness remains global. Every collision returns the same generic localized message, without identifying a client or monitor.
- Stored secrets shorter than four characters are not redacted. Existing short values are not padded or deleted. Replace them before enabling or saving their configuration. Legacy short check or crawler credentials produce an errored check with a localized renewal hint, not target downtime; the check does not send an HTTP request.
- The notification migration marks channels with short secrets `needs_attention`, disables them, and keeps the encrypted values. Delivery skips them without a transport exception. A valid configuration save clears the reason and reactivates the channel. The application logs a credential-renewal warning after startup migrations; `dc exec app php artisan heptapod:notifications:attention` repeats it. The `notificationAttention` Inertia props list id, label, channel type, and localized reason for the channels the viewer can repair (team and client channels for Owners and Admins, and always their own); no channel secret is exposed.

## Operate and recover

- `dc exec app php artisan backup:run --no-interaction` creates a verified, password-encrypted ZIP of the Postgres dump and `storage/app` at `/backups/Heptapod/`. `BACKUP_DISK=backups` is the local disk at `/backups`; `BACKUP_PATH` can change its root. Daily cleanup is 02:00 UTC and backup is 02:30 UTC. Retention is configured in `config/backup.php`. Copy archives off-host: `dc cp app:/backups /srv/heptapod-backup-export`. Protect that directory (0700), keep `.env.prod`, archive password and every APP_KEY separately, and test restoration. The backup volume alone cannot survive VPS loss. The shipped Flysystem S3 adapter supports S3-compatible disks; configure credentials and protect the remote bucket separately. A failed backup opens the `backup_failed` system issue at the next five-minute evaluation, shown in System health and mailed to Owners and opted-in Admins. This includes a backup process the kernel killed for memory (exit 137), a PHP fatal error, or a failure the backup could not record itself; the scheduler records these from the exit code. If the scheduler container itself stops or restarts during the backup, nothing records the failure: `backup_stale`, which opens when no backup has succeeded for 26 hours, is then the only backup signal, and `scheduler_stale` reports a scheduler that stays down. TODO: Wire localized backup notifications; configure an independent dead-man alert for a stopped scheduler.
- Owners can request an asynchronous backup through `POST /settings/backups` (one request per five minutes; a queued/running request blocks another until it completes or fails; exhausted jobs release their slot, and a running job past the worker retry timeout is marked failed on the next request). `GET /settings/backups/data` lists the configured archive disk's recent ZIPs and the recorded outcomes of manual and scheduled runs. A failed disk is shown as unreachable, not as an empty backup history. Only a completed archive is a backup; the web app cannot restore one. Backup requests use the same isolated Spatie `backup:run` command as scheduled backups.
- Restore on a fresh instance with the same code revision, APP_KEY and archive password. Stop writers: `dc stop horizon dispatcher scheduler`; put the app into maintenance: `dc exec app php artisan down`. Copy the selected archive: `dc cp /srv/restore/backup.zip app:/tmp/restore.zip`. Extract with the standalone recovery script (no password in shell history). It decrypts entries in 1 MiB chunks, checks their sizes and requires a SQL dump. Use a new extraction directory; a failure leaves partial files for inspection, not a usable restore:

Run the steps one at a time and stop at the first one that fails; do not paste the block with `set -e` into your SSH session, because a failing step would close it mid-recovery.

```sh
dc exec app php scripts/restore-backup.php /tmp/restore.zip /tmp/restore
# Inspect /tmp/restore/db-dumps; replace DUMP.sql below with the extracted file name.
dc exec -T app cat /tmp/restore/db-dumps/DUMP.sql | dc exec -T postgres sh -c 'psql -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$POSTGRES_DB"'
dc exec app cp -a /tmp/restore/storage/app/. /app/storage/app/
dc exec app rm -rf /tmp/restore /tmp/restore.zip
dc exec app php artisan migrate --force --no-interaction
dc exec app php artisan up
dc up -d horizon dispatcher scheduler
```

The packaged backup command uses file-backed `pg_dump`, ZIP creation and streamed disk writes; neither backup nor recovery reads a whole SQL dump into PHP memory. Allow disk space for the archive and its decrypted contents. The app's 384 MiB memory cap still applies.

The nightly backup runs in the scheduler container and its memory does not grow with the database: on a 2.4 GiB database (1.6 GiB SQL dump, 249 MiB archive), `backup:run` held 52–54 MiB and `pg_dump` 4 MiB of non-reclaimable memory through dump, ZIP, verification and copy. The rest of the container's usage was reclaimable page cache. Each PHP process in the scheduler uses about 50 MiB. The backup runs in the background, so no scheduler process waits beside it. While it runs, the scheduler holds at most four PHP processes: `schedule:work`, `backup:run`, one minute's `schedule:run` and its current command. Together with `pg_dump` that is about 210 MiB (4 × ~55 MiB measured, at most 208 MiB). The 320 MiB scheduler cap leaves about 110 MiB headroom for heavier scheduled commands and slower hosts, where a longer backup overlaps more minute runs. The backup itself does not need that headroom to grow with the database. Do not lower the scheduler cap below 256 MiB: the measured worst case leaves about 45 MiB at that limit.

Restore into an empty database, not over live tables. For recovery on an existing instance, recreate the database only after preserving its own backup. Never use `down -v` on production.

For releases that change queued job contracts, stop the dispatcher and allow pending jobs to finish under the old image before upgrading. The supported upgrade drains currently running workers; it does not promise compatibility for every queued payload. Do not flush or retry jobs as routine upgrade steps. Existing installations without `HEPTAPOD_CRAWL_CONCURRENCY` stay at one crawl process; to run two on an 8 GiB or larger host, set `HEPTAPOD_CRAWL_CONCURRENCY=2` and `HORIZON_MEMORY_LIMIT=2990m` in `.env.prod` before upgrading. An explicit older `HORIZON_MEMORY_LIMIT` (for example 832m or 1280m) is below the budget: raise it to at least 1990m (one crawl process) before upgrading. Otherwise the upgrade refuses before maintenance and names the required value.

Upgrade to a published release tag. Read that release's notes, export an off-host backup, then run the upgrade in the installation directory. Public images need no registry login.

One-line installer (release bundle) installations upgrade to the latest release, or to a chosen tag with `--to`:

```sh
cd /opt/heptapod && sudo ./scripts/upgrade.sh
cd /opt/heptapod && sudo ./scripts/upgrade.sh --to v0.2.0
```

Heptapod publishes only `vX.Y.Z` release tags; a pre-release tag is refused. The script resolves the tag (the latest one through the GitHub API) and compares it with the installed `RELEASE`, field by field as numbers. The installed release is a no-op only when it is deployed: the running app image carries `REVISION` and the last upgrade, if any, committed. Otherwise, for example after a failed image pull, the same command continues that release's upgrade. An older tag is refused: migrations only run forward, so older code never runs on a newer schema. Returning to an older release means restoring a backup taken with that release (see Operate and recover) together with that release's files, never `--to`. For a newer tag, it downloads `heptapod-<tag>.tar.gz` and its `.sha256` and refuses a checksum mismatch, and a bundle whose files differ from its `MANIFEST`, before changing anything. It unpacks the bundle in `.heptapod/`, copies every file it will replace to `.heptapod/upgrade/host-previous/`, durably records the replacement in `.heptapod/upgrade/host-replacement` (one `old` or `new` line per `MANIFEST` path, then `end`), then moves the new files over the installation by rename in `MANIFEST` order, `REVISION` last; `.env.prod`, `.heptapod/` state and Docker volumes are never replaced. Then it executes the new release's own `scripts/upgrade.sh --staged <REVISION>`. That script refuses a `REVISION` that differs from the argument and continues with the steps below. If the replacement is interrupted, the record remains. The next `./scripts/upgrade.sh`, from either release, first takes the lifecycle lock and then reads the record; both steps are inline at the top of `upgrade.sh`, before any helper script is sourced, and stay compatible across releases. It validates the whole record before touching a file: every path must be a relative `MANIFEST` path without `..`, `.env.prod` or `.heptapod`, under no symlinked directory, and every `old` path needs its previous copy. Anything else refuses and keeps the record for inspection. A record without its final `end` line was never completed, so no file was replaced: it is discarded. A valid record restores the previous files by copy and rename, verifies them, and only then removes the record and the copies, so an interrupted restore resumes on the next run. Then it reruns the restored release's upgrade. Run `upgrade.sh`, not `install.sh` or `rollback.sh`, after an interrupted replacement. If anything stops later but before maintenance, the new files stay in place and the old stack keeps serving; rerun the same command. `HEPTAPOD_RELEASE_API` (default `https://api.github.com/repos/heptapodwatch/heptapod`) and `HEPTAPOD_RELEASE_DOWNLOAD` (default `https://github.com/heptapodwatch/heptapod/releases/download`) select a mirror of the release API and assets; the hosted installer reads the same variables.

Git checkouts upgrade to the checked-out commit. Check out the release tag first; the checkout must be clean:

```sh
git fetch --tags && git checkout v0.2.0
./scripts/upgrade.sh
```

The release's `REVISION` or the checkout's `HEAD` selects the app and, when enabled, Lighthouse repositories defined by the image defaults in `compose.prod.yaml`, tagged with that full commit SHA. The script pulls them, verifies `org.opencontainers.image.revision`, and writes immutable `repo@sha256:…` pins into the managed `APP_IMAGE` / `LIGHTHOUSE_IMAGE` keys in `.env.prod`. Do not export those keys in the shell or define them more than once. `HEPTAPOD_IMAGE_REGISTRY` optionally selects a registry/repository prefix for a mirror. Revision labels detect a mismatched release; they are not signatures. Private/public registry auth does not change digest verification.

Image pull/build and disk checks happen while the old stack serves traffic. A missing tag, registry denial, label mismatch, build error or insufficient space aborts without maintenance or a backup. `--build` is the explicit source-build fallback for unpublished, dirty, arm64 or air-gapped checkouts:

```sh
./scripts/upgrade.sh --build
```

Source builds keep release-specific local tags. They export the current UTC date as `APT_SNAPSHOT_DATE`; direct Lighthouse source builds require this nonempty argument. Debian update, upgrade and Chromium installation share one layer, without Chromium version pins or CVE suppressions. Prebuilt images default to amd64. `HEPTAPOD_IMAGE_ARCHES` is the repository publication setting (`amd64`, `arm64`, or `amd64,arm64`), not an installer variable; unknown/empty values fail CI. Use `--build` on arm64 until that architecture is published.

The maintenance window contains worker drain, encrypted local backup, one-shot preparation, and the container swap. Preparation runs migrations under the Postgres advisory lock, then `heptapod:reclassify-links --policy=1` and `heptapod:notifications:attention`. It has no web healthcheck deadline. Plain Compose/PaaS boot still migrates by default; lifecycle scripts set `HEPTAPOD_APP_RUN_MIGRATIONS=0` for the subsequent web start. Overrides must preserve that interpolation. Non-writers start first. Horizon, scheduler and dispatcher start while Redis maintenance is still on; Horizon leaves queued jobs pending, and dispatcher skips claims but keeps its heartbeat. This dispatcher fence also applies to manual `artisan down`. Only healthy roles permit `artisan up`.

Compose can show `Stopping` for up to 1245 seconds (20 minutes 45 seconds) while a maximum-budget crawl finishes. Jobs retain their SIGTERM/SIGINT handlers; the maximum job timeout is 1215 seconds and Horizon's is 1230 seconds. Follow `dc logs --follow --no-log-prefix --no-color --timestamps horizon`. Never force-kill workers to shorten the drain.

`install.sh`, `upgrade.sh` and `rollback.sh` share a host `flock` in `.heptapod/`. This private directory contains the fsynced phase marker, irreversible release-intent marker, candidate pins, previous image IDs, `.env.prod`, ordered Compose snapshots, migration fingerprint, and backup path/checksum. Durable state/pin writes require Linux coreutils `sync`. Keep it with the checkout; do not delete an unfinished phase to bypass recovery. Only the lifecycle scripts coordinate this lock; an independently launched backup is not covered. Upgrade drains Horizon and stops scheduler before its own backup.

Failure handling:

- Before preparation: the script restores configuration, restarts the old writers (dispatcher last), and leaves maintenance. A host crash leaves a marker instead; the next run prints the matching recovery command.
- During preparation, swap or writer health wait: maintenance stays on and writers stop. Fix the reported cause and run `./scripts/upgrade.sh --resume`, or run `./scripts/rollback.sh --restore-backup` to return to the previous image and data. The script prints absolute commands and the exact recorded archive path for that run.
- A killed host CLI can leave its named preparation container alive. Resume and rollback refuse while that container runs and print the exact graceful stop command. PostgreSQL may continue a statement after its client exits: the one-shot sets `PGAPPNAME` to its recorded container name. If owned sessions remain after the stop, recovery refuses and prints an explicit termination command filtered by both current PID and that application name. Nothing is terminated automatically. Never prepare and restore concurrently.
- `./scripts/rollback.sh` without flags changes images/configuration only. It refuses when the migration fingerprint differs from the recorded value. The fingerprint counts completed migrations, not every schema operation: a failed nontransactional migration can leave unrecorded changes. Use backup restore when partial changes are possible. Reclassification/attention writes can occur without a schema change; image-only rollback accepts those forward-compatible relabels.
- Use the archive-bound `rollback.sh --restore-backup` command printed for the failed run. A supplied path must match the recorded archive; stale commands and checksum mismatches refuse before service/data changes. The bare flag prints the archive it will use and proceeds without a prompt. Restore decrypts with the old image, imports into a new database with `ON_ERROR_STOP` and a transaction, and verifies the old migration fingerprint before switching names. The failed database stays available for diagnosis; the script prints its exact `dropdb` command. Local `storage/app` is replaced by a same-volume rename, not merged; `app.failed-<timestamp>` remains for inspection. An interruption between database renames is reconciled from durable intent and the actual names on retry. Failed extraction/import leaves all app roles stopped and preserves its temporary data, with an exact removal command and a decrypted-data warning. Successful restore removes only its own named, labelled extraction volume after checking it is not a declared persistent volume; storage, backups, Postgres and Redis volumes are never removed.
- Release acceptance starts at the durable `releasing` intent, before `artisan up`, even if that command fails. Rollback refuses from then onward: restoring the pre-upgrade archive would lose every write accepted since release. The separate intent marker stays set while `--resume` re-enters preparation; only a new upgrade generation clears it. Recovery is forward-only (`--resume` or the exact printed `artisan up` command). Rollback's own final release writes terminal `rolled-back` before `up`, so it can never re-import data after an interrupted release. A later data restore is a manual operator decision outside this tool, using the matching application revision, APP_KEY and archive password. Never run `migrate:rollback`.

The lifecycle archive is always local (`backup:run --only-to-disk=backups` with `BACKUP_DISK=backups` and `BACKUP_PATH=/backups` for that invocation), independently of the configured backup disk/path. Scheduled and manual backups retain their configured destination. Backup restore requires host `jq`. The temporary host SQL copy has its own checkout-filesystem space check. It is removed on a failed import; before writing it, the script also prints its exact cleanup command for interruptions that bypass traps. Keep `/backups` writable and persistent, including on readonly/S3 deployments. Remote media objects are not in the archive: when `HEPTAPOD_STORAGE_DISK` is not `local`, upgrade warns and restore recovers the database only. Protect the bucket with its own versioning/replication. Redis is not restored. Pending jobs at backup time belong to the old version; already-sent notifications and other external side effects cannot be undone. The old web container serves the maintenance page during migrations; a maintenance-bypass request would use old code against the new schema. No bypass is configured by default. Maintenance does not cancel requests already in flight; the archive remains a point-in-time recovery boundary.

Recovery is same-host, before acceptance; it is not disaster recovery or a boot-time recovery service. Compose snapshots are the pre-pin TARGET model from the checked-out release, not a reconstruction of the running release's old YAML. App/data rollback assumes backward-compatible Compose changes. Postgres major upgrades/data-directory format changes and incompatible service, role or volume changes require a separately planned backup and explicit procedure; this rollback tool does not cover them. Preserve off-host copies of archives, keys and configuration.

Fresh install and upgrade measure free space on the daemon's data root (`docker info` plus `df`), so run them on that host. The floor is 12 GiB for image unpacking and backup headroom. Upgrade additionally reserves twice the current database size before maintenance. Resume verifies that the recorded images and checksummed archive still exist, then checks only the remaining two-database headroom, not the image/backup acquisition floor. Missing or unverified artifacts refuse resume; they are never reacquired implicitly. A named, guarded one-shot reads the pinned model and sets maintenance without depending on the stopped/crash-looping web container. Source builds require at least twice `HEPTAPOD_BUILD_SIZE_GB` (default 6 GiB); the floor cannot be lowered. File-heavy storage and separately mounted backup disks need additional capacity. Restore rechecks twice the database size plus uncompressed archive size and 1 GiB. Existing-config installer reruns start nothing and need no disk preflight.

Successful pull and build upgrades prune dangling labelled images and recorded generations older than current/previous. `heptapod-rollback/*:previous` retains the old IDs before any pull/build. Fully obtained/verified candidates are recorded too, so an abandoned failed candidate can be retired after a later successful upgrade. Recorded obsolete IDs are removed only when labelled and not referenced by any running or stopped container; blocked/failed removals stay recorded for retry and print exact manual commands. Unlabelled legacy images and unrelated tagged images are untouched. Retention/prune errors warn without failing the accepted deployment. Build mode additionally prunes shared daemon build cache with `HEPTAPOD_BUILD_CACHE_KEEP` (default `5GB`). Never use `system prune`, volume pruning or `image prune -a` for routine upgrades.

A failed fresh install prints a complete recovery block that resolves/pins the release, starts the configured stack, and creates the requested Owner. Use that block after fixing image access; it never starts a mutable `:main` image.

The installer persists the ordered `COMPOSE_FILE` list, including extra overrides. An exported value must match it exactly. Missing legacy `COMPOSE_FILE`: rerun the installer with the original flags and original exported list; it appends missing keys without service startup, secret rotation or Owner creation. Then upgrade the running instance. Do not infer deployment mode from `HTTP_PORT`. Keep overrides at their original paths; file lists permit only letters, digits, `_`, `.`, `/`, `:`, `+` and `-`.

Only main advances deployment tags using GITHUB_TOKEN; ci/* publishes candidate SHA indexes. Each ci/* candidate builds/scans both images for every configured architecture, including app OPS checks, regardless of changed paths. Pull requests retain path selection. Main promotes an exact-SHA candidate by digest only after native gates and architecture matching; otherwise it builds through those gates. Loaded native images are scanned/pushed without rebuilding. Matrix-specific caches separate images and architectures. Public publication adds GitHub provenance attestations; private repositories skip them without blocking publication. Docker archive promotion does not retain Buildx SBOM/provenance metadata. TODO: Attach per-architecture SBOM attestations when OCI-artifact promotion is adopted.

Before maintenance, upgrade runs `heptapod:notifications:migration-preview` with the candidate image and `RUN_MIGRATIONS=0`: a read-only report of how notification rules, follows and noise settings become channels, printed and saved to `.heptapod/upgrade/notification-migration-preview.txt`. It prints "already converted" once the old tables are gone. A failing preview stops the upgrade before writers are frozen.

Reclassification never sends notifications or resolves incidents. It validates blockers before writing, including a currently sending notification; read the streamed preparation failure and fix the cause before `--resume`. Such a sending lease clears after 90 seconds. For a read-only precheck, use the candidate ref recorded in `.heptapod/upgrade/candidate.env`, disable migrations, and invoke `heptapod:reclassify-links --policy=1 --dry-run` in a one-shot container. Keep writers fenced until recovery completes.

- Schedule maintenance after upgrading: run `dc exec app php artisan heptapod:checks:rebalance-schedule --dry-run` to preview the UTC hourly distribution, then omit `--dry-run` to spread enabled checks over their next stable slots; the command is chunked and repeatable, schedules within one interval from execution, and leaves disabled checks and current claims untouched.

The migration `2026_10_07_125311_remove_site_notification_exceptions_and_secondary_personal_addresses` requires the pre-upgrade backup created by `scripts/upgrade.sh`. It locks and counts every row in `site_notification_preferences`, `site_notification_recipients` and `site_notification_mutes`, including inherited, ended and disabled records. It also validates account-linked channels against their effective account email, including encrypted overrides. Any legacy row or incompatible channel aborts with safe table/category counts before data or schema changes. Do not delete or convert records to force the guard to pass. Stop and review the reported categories. An empty compatible inventory permits removal of the three tables and channel-only verification markers; account verification, preferences and delivery history remain intact (Follow existed then; the 2026-10-30 conversion later turned it into channel categories). `down()` deliberately fails: restore the pre-upgrade backup and application revision to roll back. Do not run a schema-only rollback. A Debian-to-Alpine Postgres switch changes libc collation: restore a logical dump into a new empty Alpine database volume while writers remain stopped, then verify ordering/index behavior before resuming. Do not reuse a Debian-initialized database volume blindly.

## Access log and capability URLs

Invitation, password-reset, email-verification and cron-ping URLs grant access by themselves. The app's access and error logs therefore record every query value, and every path segment of 24 or more characters, as `[redacted]`, and omit the `Referer` header. Logs written by an older image can contain these URLs in full; treat them as exposed. Password-reset links expire after 60 minutes and email-verification links after their signed lifetime, so they need no action. Open invitations stay valid for up to 7 days: resend or revoke each pending invitation under Settings → Members, which replaces its token. Cron ping URLs never expire: rotate the ping URL of each cron check and update the URL in the scheduled job. After that, delete the old logs (for example, recreate the app container with `docker compose up -d --force-recreate app`, which discards its Docker log) and any copies in external log collectors.

Mail sender name: `MAIL_FROM_NAME` is the display name. Older installs have `MAIL_FROM_NAME="${APP_NAME}"` with `APP_NAME=Heptapod`; this exact value `Heptapod` is shown as "Heptapod" (resolved in `config/mail.php`). Any other name is used as written. Keep `APP_NAME` unchanged: it also names the cache, session and backup paths.

With `MAIL_MAILER=log`, the default until SMTP is configured, the app log contains email bodies with invitation, password-reset, verification and signed-URL tokens redacted. SMTP and other real transports send the original links. The `array` mailer keeps messages in memory and writes no mail log. Older logs can contain complete links: rotate those links as described above and delete the old logs.

### Capture mail locally with Mailpit

Use this only on a disposable local installation. Mailpit captures messages instead of delivering them; its inbox contains access links. Keep its UI bound to loopback. Production installations must use their real SMTP provider.

From the installation directory, create a Compose override:

```sh
cat > compose.mailpit.yaml <<'YAML'
services:
    mailpit:
        image: axllent/mailpit:v1.27
        networks: [backend]
        ports:
            - '127.0.0.1:8025:8025'
YAML
# Replace the local test installation's mail settings, not a production file.
grep -v '^MAIL_' .env.prod > .env.prod.tmp && mv .env.prod.tmp .env.prod && chmod 600 .env.prod
cat >> .env.prod <<'ENV'
MAIL_MAILER=smtp
MAIL_SCHEME=smtp
MAIL_HOST=mailpit
MAIL_PORT=1025
MAIL_USERNAME=null
MAIL_PASSWORD=null
MAIL_FROM_ADDRESS=heptapod@example.test
MAIL_FROM_NAME=Heptapod
ENV
docker compose --env-file .env.prod \
    -f compose.prod.yaml -f compose.mailpit.yaml up -d --wait
docker compose --env-file .env.prod \
    -f compose.prod.yaml -f compose.mailpit.yaml \
    exec -T app php artisan config:clear
```

For a proxy-mode installation, include `-f compose.proxy.yaml` immediately after `-f compose.prod.yaml` in both commands. Open http://localhost:8025/ and request a password reset from the login page for an existing account. The message and usable reset link appear in Mailpit. Queued notifications also require the running Horizon service. Keep the override in subsequent local Compose commands. To stop capturing, restore your SMTP settings, remove the Mailpit override from the file list, and recreate services with `up -d --wait --remove-orphans`.

Without SMTP, the instance Owner can issue a one-time link for an existing pending invitation or active account:

```bash
dc exec app php artisan heptapod:user:access-link person@example.com --type=invite
dc exec app php artisan heptapod:user:access-link person@example.com --type=reset
```

Run this command only as the instance Owner. Share the printed URL through a private channel: it grants access by itself. It replaces earlier links for that invitation or account, expires after 7 days for invitations or the configured password-reset lifetime (60 minutes by default), and works once. Issuance is audited without the URL or token. Email-verification links still require a real mail transport.

Notification HTTP transports permit only public targets by default. To reach a self-hosted webhook or ntfy server on a private network, the instance Owner can set `HEPTAPOD_DELIVERY_PRIVATE_HOSTS=ntfy,webhook.example.com` in the production environment. Entries are exact host names (including Docker service names) or IP literals, comma-separated; URLs, wildcards and CIDR ranges are rejected at configuration load. The default is empty. System health lists the configured exception hosts. Only the listed host can use private addresses, pinned to its first DNS resolution for that attempt. Metadata, link-local and translation addresses remain blocked, even for a listed host. Redirects are not followed. Notification HTTP requests bypass egress proxies, as checks do. A listed host is open on every port and path: any user who can create a notification channel can then reach any service on that host (for example `http://ntfy:6379/`). List only hosts that run nothing else you need to protect.

## Retained worker diagnostics

Worker diagnostics are retained on the host, independently of container recreation and storage volumes. `upgrade.sh` captures before maintenance and again before recreation. Optional host prerequisites are Bash and `jq`; neither is installed automatically. Without `jq`, each upgrade warns that worker stderr, container exit/OOM facts, and host pressure are not captured, then continues. The collector uses the operator's existing Docker access; no container socket mount or additional credentials are required.

Run `bash ./scripts/retain-diagnostics.sh snapshot` for one capture, or `bash ./scripts/retain-diagnostics.sh watch` for continuous captures with a five-second pause after each capture (also works without systemd, including macOS). The default path is `$XDG_STATE_HOME/heptapod/diagnostics/<compose-project>` or `$HOME/.local/state/heptapod/diagnostics/<compose-project>`. Set `HEPTAPOD_DIAGNOSTICS_DIR` to an absolute host path outside the checkout if needed; export the same value for upgrades and the watcher. The directory is 0700 and files are 0600. `diagnostics.jsonl` plus `.1` through `.9` retain at most ten 128 MiB files (1.25 GiB total). Unchanged safe service state is omitted, with an hourly refresh; changed container/status/exit/OOM/restart/image/memory facts remain visible. Five private `state/<service>.json` cache files hold the latest safe state. Empty suppression summaries are omitted. Host pressure remains in every capture window. Each capture removes files last modified at least 14 days ago (file age, not per-record expiry). Stopping the collector also stops expiry; these files are not an off-host backup.

Production defaults `LOG_STDERR_FORMATTER` to the installed `Monolog\Formatter\JsonFormatter` through the existing Laravel logging configuration; development keeps its line formatter. The collector requires complete JSON framing for exception metadata. It never interprets JSON fragments or exception-looking suffixes inside `message`. `docker compose logs` adds service prefixes unless disabled: use `dc logs --no-log-prefix --no-color --timestamps` when inspecting/parsing these records. The collector uses timestamped `docker logs` and labels the actual stdout/stderr stream. Horizon forwards worker stderr through stdout, so its proven JSON exception records are accepted there; JSON exceptions from other roles' stdout are suppressed.

Only `app`, `horizon`, `dispatcher`, `scheduler`, and `reverb` contribute facts. Records contain UTC timestamps, validated production channel/level/datetime, container/service, immutable image digest, native exit/OOM/restart/memory facts, fixed-template `message_key`, and normalized exception class with app/vendor file:line. Fixed anchored Horizon start/terminate and known job-class RUNNING/DONE/FAIL console forms remain supported. Optional JSON context keys `job_class`, `job_uuid`, `queue`, and `attempt` are validated when present; the current Horizon console output does not supply UUID/queue/attempt. Unknown display names are not retained. Raw `message`, `exception.message`, all other context, traces, URLs, headers, environment, SQL and payloads are discarded. Non-JSON/legacy lines contribute only nonempty suppression count/byte summaries, without hashes. A standalone PHP fatal outside Laravel's JSON logger can therefore have an unknown error location; its CLI/container exit and native OOM facts must not be guessed. Use System health's failed-jobs count for read-only monitoring.

To roll logging back, set `LOG_STDERR_FORMATTER=null` (the literal string) in `.env.prod` and recreate the five application roles. Laravel resolves that value to its default line formatter; this was tested against the production image. Removing or emptying the setting does not undo the Compose JSON default. The collector deliberately reports legacy exception locations as unavailable/suppressed rather than parsing ambiguous line-format message text. Each window also stores numeric host load averages (1/5/15 minutes), total/available RAM bytes, and total/free swap bytes from `/proc/loadavg` and `/proc/meminfo`. Missing sources, including macOS without `/proc`, remain `null` with fixed `source_unavailable`, `field_unavailable`, `probe_failed`, or `invalid_counter` reasons. Zero swap remains zero, not unknown. No hostname, environment, platform fingerprint, or original tool output is stored.

A real 3669-second production-role fixture measurement retained 3327871 bytes after deduplication (907.024 bytes/s, 78366872 bytes/day), with source hashes unchanged and no rotation/eviction. At that measured rate, the size cap covers 17.127 days, or 2.447 times seven days of bytes; the separate 14-day file-age bound still applies. This exceeds the required seven-day typical-rate coverage with two-times headroom. The measurement ran app/Horizon/dispatcher/scheduler/Reverb with two heavy workers and a 1280 MiB Horizon cap, local Mailpit, 20 uptime checks every 60 seconds and five six-page crawl checks every 900 seconds. It completed 1220 uptime runs (1214 successes/six expected failures), 20 successful crawls, two failed-check/recovery cycles and one bounded sentinel exception job during the window. Notification scheduling ran normally; no external notification channels, heartbeat or updates were enabled. Reference deployment throughput is five uptime and five performance checks/minute, plus five certificate/1800s, four DNS/7200s, three domain/14400s and twenty daily crawl/browser/sitemap checks: about 614 scheduled checks/hour versus about 1217/hour measured here. This is a conservative local scheduling/log-volume envelope, not proof of every addon/browser/error shape, external latency, notification delivery or failure flood. Such workloads can shorten coverage; measure their retained byte rate before relying on seven days.

Inspect retained records with `jq . /path/to/diagnostics/diagnostics.jsonl`, replacing the example with the configured host directory. These structured records are intentionally incomplete: absence of a record is not proof that no failure occurred.

The first capture looks back 24 hours; subsequent captures use a retained UTC cursor. At most the last 1,000 log lines per role/window are examined. Counts describe that fetched sample, not older/truncated logs. Daemon event history is finite; capture gaps, rapid recreation between polls, or logs already rotated by the engine can lose evidence. Inclusive second boundaries can duplicate records. Exit 137 does not establish OOM: only a native inspect boolean or an actual `oom` event provides that fact. Missing OOM/signal/peak-memory fields are `null`, not false or guessed. A read-only peak probe uses the container's existing user and has a four-second host-CLI deadline. `memoryPeakStatus` distinguishes available, stopped, unsupported, failed, and invalid counters without retaining probe stderr. Capture errors warn explicitly, and a failed capture does not advance its cursor. A SIGKILL of the collector can leave `.capture-lock`; remove that empty directory only after confirming no capture is running.

For optional Linux supervision, copy `/srv/heptapod/docker/prod/heptapod-diagnostics.service` to `$HOME/.config/systemd/user/heptapod-diagnostics.service`, change its checkout paths, and use:

```sh
systemctl --user daemon-reload
systemctl --user enable --now heptapod-diagnostics.service
journalctl --user -u heptapod-diagnostics.service
```

Keep instance-specific Docker environment and `HEPTAPOD_DIAGNOSTICS_DIR` in a private user-unit drop-in, not tracked files. User services require a running user manager; arrange persistent supervision using the host's existing policy. The unit is optional, is not installed by the upgrade, and requires no new sidecar.

Review `.trivyignore` expirations before release. CI HARD-gates the application and Lighthouse images on every architecture configured by `HEPTAPOD_IMAGE_ARCHES` (amd64 by default) with HIGH/CRITICAL, ignore-unfixed, exit 1. Official runtime images are scanned without suppressions as report-only SARIF artifacts; no derived images are maintained. Known upstream risk in the 2026-10-02 arm64 scan: Postgres 17.11 Alpine has 21 HIGH / 1 CRITICAL in bundled `gosu` (OS packages clean), Redis 8.10.2 Alpine has zero, and Gotenberg 8.37.0 has 227 HIGH / 25 CRITICAL. Official runtime reports use the same configured architectures as the production-image CI matrix. Gotenberg includes outdated Chromium and PDF dependencies; private service networking does not remove that risk. Review the `runtime-*` CI artifacts and deploy only with explicit acceptance of these upstream findings. Renovate tracks the digests but remains inactive until its app is installed.

- APP_KEY rotation: export a backup, enter maintenance and stop workers. Save the old key securely; generate a replacement with `printf 'base64:'; openssl rand -base64 32` and update `.env.prod`. Add the old key to `APP_PREVIOUS_KEYS` (comma-separated), then recreate all app services. Previous keys decrypt existing encrypted data but do not re-encrypt it; keep them until a verified data re-encryption has completed. Old backups need their original key. Do not run `key:generate` blindly on a live instance; existing encrypted values and sessions depend on it.
- `GET /settings/instance/data` shows instance identity, masked mail configuration, an outbound-heartbeat indicator, the `/up` polling URL, and read-only default/retention configuration. Owners can change the instance name through `PATCH /settings/instance`; mail credentials, site defaults and retention remain set by the server configuration. `GET /settings/system/data` shows queue, scheduler, dispatcher, worker-memory, database and release state to Owners and Admins without exposing private probe diagnostics.
- Update checks query the configured GitHub releases API daily at 04:00 UTC by default. They send no instance data beyond the HTTP request. `/settings/updates/data` reports the installed and latest release versions; a private or unavailable release source shows its HTTP status and last successful check, never a fabricated update. Owners may toggle checks through `PATCH /settings/updates`. Set `HEPTAPOD_UPDATE_CHECK_ENABLED=false` and recreate app/scheduler to disable checks at the server (the UI cannot override this). `HEPTAPOD_UPDATE_CHECK_URL` overrides the release API endpoint; keep it public HTTPS. The production image bakes `HEPTAPOD_VERSION`: tagged image builds use the tag, CI builds use `sha-<short>`, and source installs/upgrades with `--build` pass `git describe --tags --always --dirty`. Builds without a known version report development/unknown instead of claiming an upgrade. Do not add a manually maintained version to `.env.prod`.
- Optional heartbeat: set `INSTANCE_HEARTBEAT_URL` to your external dead-man GET endpoint, then recreate scheduler/app. `config/instance.php` controls the minute schedule; `dc exec app php artisan instance:heartbeat` fails on missing/invalid URL or non-2xx response. Empty disables the schedule. Successful pings update the system-health last-ping timestamp in Redis; rejected responses do not advance it.

## Browser security and local proof

Browser health probes use `LIGHTHOUSE_URL` and `GOTENBERG_URL` from `.env.prod` with `/health` appended. The default internal URLs are `http://lighthouse:3000/health` and `http://gotenberg:3000/health`. No separate health URL environment variables are required. `--without-browser` disables both probes.

The PHP worker creates a scope through `http://lighthouse-proxy-control:3129/scopes` on the isolated management network, deriving `siteHost` from the claimed check context. Lighthouse (`http://lighthouse:3000/lighthouse`) accepts POST JSON `{ "url": "https://example.com", "options": { "desktop": false }, "proxyToken": "<64-character hex token returned by scope creation>" }`, never caller-provided policy. Deploy the app and Lighthouse images together: an old 125-second client cannot receive a new 150-second budget result. The PHP worker revokes the scope in a finally block. The service preserves the full LHR, returns 429 without starting an audit while busy, and kills the Node worker and Chromium process group after 150 seconds. Only that internal audit endpoint's 429 response is retried, within the same claimed attempt and original check deadline. The worker revokes each scope before waiting at most one second, then creates a fresh scope for the next admission. Each audit request is bounded by the smaller of 155 seconds and the remaining deadline minus a five-second cleanup reserve. If the budget cannot admit another request after a busy response, the accepted run is an error: “Lighthouse was busy; the audit did not run.” An admitted request that times out remains a timeout, not a busy error. Other HTTP failures propagate without retry. Safe transport diagnostics retain a known numeric HTTP status, never a URL, response body or credential. The browser client/worker budgets are 155/170 seconds. The shared `ExecutionBudget` defines a 60-second queue wait, maximum addon timeout of 1200 seconds, 15 seconds of worker bookkeeping grace, Horizon timeout of 1230 seconds, Redis `retry_after` of 1260 seconds, and running lease of addon timeout plus 30 seconds. The job timeout is addon timeout plus bookkeeping grace (maximum 1215 seconds); the addon/HTTP context deadline remains unchanged. Queued fast claims have a 600-second renewable stale window; queued crawl/browser claims have 1500 seconds while their Redis job remains present, including waits behind earlier work in the same queue. Horizon warns on waits in either heavy queue (`checks-crawl` or `checks-browser`) after 1500 seconds rather than the fast queue's 60 seconds. Set `HEPTAPOD_CRAWL_TIMEOUT_SECONDS` to an integer from 1 to 1200 seconds in `.env.prod` (default 1200); changing either crawl timeout or concurrency requires rebuilding the config cache and restarting Horizon. The Compose stop grace is 1245 seconds, checked against `ExecutionBudget::StopGrace`. Redis reservation timing is not an environment override. PHP services set resolver options `timeout:2 attempts:2`; job deadlines and lease reclamation remain the backstop for blocking DNS. Gotenberg 8.37.0 is the latest official 8.x release checked on 2026-10-01 and is digest-pinned, with one conversion and two queued requests. Cold Chromium startup has a 60-second deadline within the total 120-second API deadline, leaving room for startup on a loaded 2-vCPU host. Horizon's 1230-second timeout covers that request; report jobs must still declare a timeout above the API deadline when report integration lands. Both services run non-root with 1 GiB shm and have no host ports or DB/Redis network attachment. Lighthouse is in the `browser` Compose profile and has only an internal network attachment. Its authenticated proxy uses the same image, has a 128 MiB cap and a healthcheck, and is the only browser service with egress. Gotenberg joins the internal browser network only. Its Chromium deny-list `^(?!data:|file:///tmp/).*` permits inline data and uploaded temporary report assets; all HTTP(S), other schemes and files outside `/tmp/` are denied. Do not enable file-to-file access. JavaScript is disabled: reports must use server-rendered HTML/CSS, inline data or uploaded assets. DownloadFrom, webhooks and unused LibreOffice routes are disabled so those features cannot bypass the browser URL policy. Network isolation is a second boundary, not the only URL defense. Gotenberg logs blocked external/file asset references as `value filtered`; the conversion itself can still return 200 with those assets omitted. Do not use external assets in generated report templates; review renderer warnings when verifying a template.

```text
Internet → app (80/443) ── backend bridge ── Postgres/Redis (no published ports)
                  └────── browser internal bridge ── Lighthouse/Gotenberg
Workers ── backend + browser + control       Lighthouse ── scoped proxy ── egress → Internet
                  └────── control internal bridge ── proxy scope management
                                             Gotenberg ── no egress
```

Lighthouse Chromium uses `--no-sandbox` for container compatibility; dropped capabilities and no-new-privileges are not a browser sandbox. Monitor URLs and check configuration come from trusted Owners/Admins only. Members have scoped read and snooze access, not target/configuration writes. All Chromium HTTP(S), redirect, subresource, frame, worker and WebSocket connections use the scoped proxy with loopback bypass disabled. QUIC and non-proxied WebRTC UDP are disabled. The instrumented audit page removes its WebRTC constructors; this is not a guarantee for every possible execution context. Puppeteer's popup-blocking override is removed, restoring Chromium's default popup blocking. Private TURN-over-TCP, WebTransport and HTTP/3 behavior are not independently proven by the native suite; the internal browser network remains the direct-egress boundary. The proxy validates every resolved IP at connect time and dials numeric addresses. The proxy, PHP outbound HTTP transport, certificate handshakes and native WHOIS sockets always deny link-local destinations (including cloud metadata and mapped IPv4 forms), known provider metadata endpoints and IPv4 translation/tunnel prefixes. The PHP socket transports share the HTTP address classifier and metadata list, validate before connecting and dial the approved numeric address; TLS still verifies the configured hostname. Only certificate fetches for the configured site hostname permit private addresses; foreign-host TLS diagnostics and WHOIS registry/referral servers require public addresses. The configured hostname may use private addresses from its first resolution in the check execution. The PHP transport resolves each distinct hostname once per execution and pins that numeric set on every request and redirect, including connection reuse. DNS changes therefore cannot introduce new private addresses. The proxy resolves at connect time; later same-host connections may use new public addresses, but not new private addresses. Link-local addresses and known provider metadata endpoints are always refused, even if they appeared in that first resolution. Cross-host redirects and resources require public addresses. A refused destination is a Check error, not target downtime. Its cause names the target class and numeric address where available; it does not open a Down incident, plan a Down alert or group dependent checks under an uptime outage. Trust is not persisted: the first resolved set belongs only to that execution, and the next check uses its then-current first answer. Same-host means equal lowercased, IDNA-normalized hostname strings with trailing dots removed, not equal resolved IPs. NAT64 and 6to4 destinations are refused even for the configured hostname. A server on an IPv6-only network that reaches IPv4 sites through DNS64/NAT64 is not supported. Plain RFC1918 and IPv6 ULA intranet targets remain supported under the first-resolution rule. The proxy's data and management listeners are always denied, including unspecified-address aliases. The PHP worker derives each scope's configured hostname from the claimed check context. For rolling-upgrade compatibility the proxy accepts and ignores the old app's retired `allowPrivateExternalTargets` field; it grants no private-target permission. Other unknown scope fields are refused. Scope creation and revocation use a TCP endpoint bound only to the app/worker/proxy management network; Lighthouse has no attachment or control socket. Lighthouse receives only a random per-run token, forwards it over worker stdin and creates an anonymous loopback relay; Chromium never receives the upstream credential or an origin authentication challenge. The PHP worker revokes the scope in a finally block. Revocation closes active connections; scopes also expire after 160 seconds. HTTP connection reuse is disabled across scopes. Development uses the same image and internal-browser/proxy-egress topology. Start both services with `docker compose --profile browser up -d lighthouse lighthouse-proxy` and enable `HEPTAPOD_BROWSER_ENABLED` for dispatch. No scope-control, browser or proxy ports are published.

Uptime limits response entity bytes on the wire and after decompression to 10 MiB by default. Set `UPTIME_MAX_RESPONSE_BYTES` to a positive integer byte count to change both limits (`heptapod.uptime.max_response_bytes`); rebuild the configuration cache and restart workers after changing it. Responses above the configured limit fail with `response_too_large` and an EN/DE diagnostic such as "Response exceeded the 10 MiB limit", with the MiB limit formatted dynamically from configuration. JSON body assertions are evaluated only for valid JSON below 2 MiB, independently of the transport cap. `look_for_string`, `absent_string` and body assertions read only responses within the configured cap; oversized responses fail before evaluation, without partial/truncated assertions. Larger accepted responses still undergo status, header and string checks. Raising the cap increases each probe's memory and network budget.

For disposable local proof only, set `COMPOSE_FILE=compose.prod.yaml`, `SERVER_NAME=http://localhost:8080`, `APP_URL=http://localhost:8380`, `HTTP_PORT=127.0.0.1:8380`, `HTTPS_PORT=127.0.0.1:8443` and `SESSION_SECURE_COOKIE=false` in a private `.env.prod`. This explicit HTTP-only override disables ACME without changing the production TLS choice. Use `docker compose --env-file .env.prod -p prodtest-r3 up -d --wait` and open http://localhost:8380. Only app startup runs migrations, under a PostgreSQL session advisory lock (120-second lock wait). Horizon, dispatcher and scheduler wait for the app healthcheck. Unexpected migration errors prevent app startup and worker startup. After proof: `docker compose --env-file .env.prod -p prodtest-r3 down -v`. Keep local settings out of real deployments.

## Resolver diagnostics

A development VM forwarding measurement on 2026-10-02 found Docker's `127.0.0.11` timing out after approximately four seconds with `timeout:2 attempts:2`; allowing ten seconds exposed SERVFAIL at the same four-second point. The same MX query through `1.1.1.1` returned NOERROR in under one second, and both authoritative nameservers answered in approximately 40 ms. This is a development VM/OrbStack forwarding observation, not evidence that a production resolver needs longer timeouts. Check the production resolver before changing DNS configuration.

DNS checks recover recursive timeouts and SERVFAIL through complete authoritative answers. Run evidence records the resolver diagnostic without a warning or notification when every authority answers completely. Failed authoritative nameservers warn and preserve the comparison baseline; both resolution paths failing produces an errored run. REFUSED, malformed/truncated/oversized answers, and unexpected process failures remain errors. Compose resolver settings are unchanged.

## Public Suffix List

DNS and domain checks share the image's bundled `resources/public_suffix_list.dat`.
Refresh is a source/build-time operation for maintainers, not a production
runtime operation. The command refuses when `APP_ENV=production`. No scheduled
refresh runs: an old list only affects newly introduced suffixes.

In a source checkout using the development Compose stack:

```sh
docker compose exec app php artisan heptapod:psl:refresh
```

Review and commit the updated list and README, then build and publish a new
application image through the release workflow. Deploy that image to the app,
queue worker (`horizon` service), dispatcher and scheduler together using the
production Compose configuration. Recreate those services when upgrading;
every process loads the list bundled in its own image. Production containers
do not share a persistent PSL file, so changing one container's file cannot
update the other services. A new image supplies the list for the upgrade.

The command downloads only https://publicsuffix.org/list/public_suffix_list.dat
through the core HTTP seam. Redirects are disabled, the deadline is 30 seconds,
and wire/decoded bodies are capped at 2 MiB. Parsing and non-empty ICANN/private
sections are required before atomic file replacement. Invalid or oversized
responses leave the list and its README unchanged and return a non-zero exit.
The README download date is updated separately with atomic replacement; an I/O
failure is reported, not hidden. The bundled list remains MPL-2.0 licensed;
source notices and attribution are in `resources/README.md`.

## Behind an existing reverse proxy

Behind a TLS-terminating proxy, set `TRUSTED_PROXIES` so the request's HTTPS scheme is trusted; otherwise signed links fail validation even though generated links use the canonical HTTPS `APP_URL`.

Keep TLS on the existing proxy. The installer retains HTTPS as the public origin and
publishes the application HTTP port on loopback only:

```sh
./scripts/install.sh --domain monitor.example.com --behind-proxy --port 8380 --without-browser
dc() { docker compose --env-file .env.prod "$@"; }
```

The installer persists `COMPOSE_FILE=compose.prod.yaml:compose.proxy.yaml` in `.env.prod`; subsequent `dc` operations and `./scripts/upgrade.sh` use it without a shell export.
`--port` requires `--behind-proxy` and an integer from 1 to 65535. An exported
`HTTPS_PORT` conflicts with proxy mode. If `COMPOSE_FILE` is exported during installation,
its final file must be this checkout's `compose.proxy.yaml`; incompatible selections
abort before changing `.env.prod` bytes or permissions. Unset `COMPOSE_FILE` to use
the installer's default pair. Place extra overrides before `compose.proxy.yaml` in the exported `COMPOSE_FILE` during installation (for example `compose.prod.yaml:compose.custom.yaml:compose.proxy.yaml`); the complete list is persisted for subsequent operations. Existing incompatible mode settings abort
without overwriting secrets; edit the managed mode keys before switching modes.
`SERVER_NAME=http://monitor.example.com:8080` disables application TLS;
`APP_URL=https://monitor.example.com` remains the public origin. To expose a nonstandard
public HTTPS port, pass `--app-url` explicitly, for example:

```sh
./scripts/install.sh --domain monitor.example.com --behind-proxy --port 8390 --app-url https://monitor.example.com:8443
```

Configure the reverse proxy to terminate HTTPS on port 8443 and forward both HTTP and WebSocket upgrades (including `/app/*`) to
`http://127.0.0.1:8390`. Verify the WebSocket upgrade path on the public HTTPS URL; an HTTP-only forwarding rule leaves live updates in polling mode. Configure the proxy to preserve the public Host
(including `:8443`) and forward HTTPS scheme and port 8443. The installer
accepts only an HTTPS URL with the exact `--domain` host, optionally followed
by a port from 1 to 65535; paths, queries, fragments and credentials fail
before changing the env file. An existing different APP_URL fails instead of
being overwritten. The public APP_URL drives login links, signed URLs, mail
links and WebAuthn allowed origins. Do not publish
8080 on all interfaces. Dockerized proxies can instead join the backend network,
use `app:8080`, and remove host ports with a Compose override.

Set `TRUSTED_PROXIES` to the proxy's actual source IP or a narrowly scoped CIDR,
then recreate all application containers. Inspect the app access log's `remote_ip`;
do not infer the peer from the bridge gateway or assume it is 127.0.0.1.
Rootless Podman port forwarding can present a peer address different from the network
gateway. `TRUSTED_PROXIES` must match the actual trusted peer seen by the app;
trusting a guessed gateway can leave HTTPS requests interpreted as HTTP.
Verify HTTPS redirects, same-origin assets and WebSocket upgrades through the public
proxy after recreating the app. Trusting the forwarding peer can also trust **every host-local caller** of the loopback listener, not only the reverse proxy. A local process can forge forwarded host, scheme and client IP; this also affects link generation and IP-based throttling. This topology assumes trusted host-local workloads. There is no wildcard default.
Do not set `*` or trust a network containing untrusted clients. The proxy must
replace forwarded headers, including host, scheme, port and client address.
These headers control redirects, signed URLs, mail links and WebAuthn origins.

For isolation from neighbouring workloads, remove all app host ports and use an unpublished private proxy network. Give the proxy a specific address and trust only that address, not the gateway or the entire subnet. For example, create a dedicated network with `docker network create --subnet 172.30.83.0/24 heptapod-private-proxy`, connect only the existing proxy with `docker network connect --ip 172.30.83.2 heptapod-private-proxy <proxy-container>`, and append this override after `compose.proxy.yaml`:

```yaml
services:
    app:
        ports: !reset []
        environment:
            TRUSTED_PROXIES: 172.30.83.2
        networks: [backend, browser, private-proxy]
networks:
    private-proxy:
        external: true
        name: heptapod-private-proxy
```

The proxy uses `http://app:8080` on that network. Other host processes cannot reach an unpublished listener through host loopback. Keep the private network limited to these two endpoints; access to the Docker daemon or equivalent host privileges can defeat network isolation and must remain trusted. Equivalent host access restrictions are another option.

nginx (existing TLS server; place the `map` at `http` scope, not inside `server`):

```nginx
map $http_upgrade $connection_upgrade {
    default upgrade;
    '' close;
}
location / {
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection $connection_upgrade;
    proxy_read_timeout 75s;
    proxy_pass http://127.0.0.1:8380;
    proxy_set_header Host $http_host;
    proxy_set_header X-Forwarded-Host $http_host;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_set_header X-Forwarded-Port $server_port;
    proxy_set_header X-Forwarded-For $remote_addr;
}
```

The application limits HTTP preload links to four assets so page response headers fit
nginx's default proxy buffer.

Caddy:

```caddyfile
monitor.example.com {
    reverse_proxy 127.0.0.1:8380 {
        header_up X-Forwarded-Port 443
    }
}
```

Traefik: attach the app to the proxy network without published ports; set
`traefik.http.routers.heptapod.rule=Host(\`monitor.example.com\`)`,
`traefik.http.routers.heptapod.tls=true`and`traefik.http.services.heptapod.loadbalancer.server.port=8080`as app labels.
Select the proxy network with`traefik.docker.network`; trust only its source
IP/CIDR. Traefik supplies forwarded host and scheme.

CapRover: create an nginx app with the custom domain and HTTPS enabled. Use the
nginx location above with an upstream reachable from that container (the host's
private gateway or `app:8080` on an attached Docker network). A container cannot
reach the host's loopback port via its own 127.0.0.1. CapRover's HTTP proxy option
is equivalent when configured to that private upstream. Do not expose the app's
HTTP listener to the Internet.

## Optional browser services

The standard installer writes `COMPOSE_PROFILES=browser` and
`HEPTAPOD_BROWSER_ENABLED=true`. `--without-browser` writes a disabled profile and
`HEPTAPOD_BROWSER_ENABLED=false`. Lighthouse and Gotenberg retain their existing
caps when enabled; no network probe decides whether they are installed.
The core availability accessor for addon consumers is
`config('heptapod.browser.enabled')`. The Lighthouse addon translates false
into its unavailable state without dispatch, incident or notification. PDF reports
are not part of this release.

All non-browser caps in the capacity table still apply when browser services are
disabled: their sum is 2224 MiB at the default crawl concurrency, not a guaranteed
1 GiB hard cap. Leave headroom for the host and other
applications. Upgrades preserve complete explicit browser selections. Legacy files
without either mode key gain `HEPTAPOD_BROWSER_ENABLED=true` and `COMPOSE_PROFILES=browser`,
preserving their previous always-on browser behavior. Partial or inconsistent selections
fail before maintenance; configure both keys together.

Compose v5.1.2 does not treat inactive-profile services as orphans. After backup,
a browser-disabled upgrade explicitly removes Lighthouse and its proxy, then uses
`up --remove-orphans` for services removed from the model. Stop Gotenberg explicitly
when switching an existing deployment off.

## Running on a PaaS: roles, env, storage

Use the same production app image for every role. Set `HEPTAPOD_ROLE` to `web`
(default), `worker` (Horizon), `dispatcher`, `scheduler`, `reverb`, or `all-in-one-worker`.
Unknown roles exit nonzero. Only the web service sets `RUN_MIGRATIONS=1`; other
roles start after migrations complete. The consolidated role uses Debian
supervisord, with a required 2200 MiB memory/swap cap at one crawl worker (3200 MiB for two); it refuses to start below that and 1275-second stop grace.
It runs the same Horizon, dispatcher and scheduler commands as separate roles;
it does not include the HTTP server. Observe all three processes, not only PID 1: `supervisorctl -c /app/docker/prod/supervisord.conf status` must show three `RUNNING` states. Supervisord restarts long-running children after every exit, including normal Horizon termination. Children must remain up for five seconds and get three startup retries, absorbing short failures before FATAL. The image owns its init process through Debian tini, including when the platform does not inject an init. Compose's additional init remains supported. A supervisor eventlistener consumes PROCESS_STATE_FATAL, writes a temporary marker and requests graceful shutdown through supervisorctl. The three programs share one supervisor group, so shutdown signals all three together: the drain budget is the maximum child window (1245 seconds), not their sum. The consolidated role needs 1275 seconds of outer stop grace, including listener and exit overhead; `docker stop -t` overrides Compose's configured grace. The role wrapper forwards termination signals, waits for the existing child stop windows, then exits70 for a fatal marker or returns the normal supervisor exit code. Running Horizon jobs retain their 1245-second drain window; fatal recovery does not SIGKILL them. Compose's inherited `restart: unless-stopped` then restarts the worker container; `on-failure` also works with exit70. Configure an equivalent restart/backoff policy on a PaaS. Its platform kill timeout must be at least 1275 seconds. The role healthcheck remains an additional signal and is not the restart mechanism.
The separate Compose roles keep their measured caps. Run `reverb` as a separate, restartable service with port 8080 reachable only on the private app network; the `all-in-one-worker` role does not include it. Give all app roles the same Reverb app ID/key/secret and internal publishing settings (`REVERB_HOST=reverb`, `REVERB_PORT=8080`, `REVERB_SCHEME=http`, `BROADCAST_CONNECTION=reverb`). The web image's Caddyfile routes `/app/*` to `reverb:8080`; provide that DNS/network alias on a PaaS or replace the Caddyfile with an equivalent private upstream. Without it, the WebSocket endpoint returns 502 and clients poll. Platform templates are a separate release.

Laravel natively accepts `DB_URL=postgres://user:password@host:5432/database` and
`REDIS_URL=redis://:password@host:6379/0`. Percent-encode reserved characters in
credentials. These replace host/port/credential variables; keep
`DB_CONNECTION=pgsql`, `REDIS_CLIENT=phpredis`, `CACHE_STORE=redis`,
`SESSION_DRIVER=redis` and `QUEUE_CONNECTION=redis`. Redis cache uses the same
URL and application prefixes; provision a dedicated Redis instance.

`HEPTAPOD_STORAGE_DISK` selects the persistent Laravel disk (`local` by default,
`s3` for an S3-compatible service). The Flysystem S3 adapter ships as a required
package, so this needs no rebuild. Configure `AWS_ACCESS_KEY_ID`,
`AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`, `AWS_BUCKET` and, for compatible
services, `AWS_ENDPOINT` and `AWS_USE_PATH_STYLE_ENDPOINT=true`. Create the bucket
before starting the app. Keep credentials private and grant access only to that
bucket. Disk failures throw; they are not treated as successful writes.
`BACKUP_DISK` can override the same storage selection for encrypted backup
archives; remove the example's `BACKUP_DISK=backups` to inherit
`HEPTAPOD_STORAGE_DISK`. Backups include the database and local storage files, not
remote object contents: enable object versioning/replication and protect the
bucket separately. Future logos and reports must use the configured disk, not
write directly to the application filesystem.

For an ephemeral/read-only rootfs, mount only `/tmp` as tmpfs writable by uid/gid
33, use S3, and set `XDG_DATA_HOME=/tmp/heptapod/data` and
`XDG_CONFIG_HOME=/tmp/heptapod/config`. Use HTTP-only `SERVER_NAME` behind the
platform proxy; TLS certificates cannot persist in tmpfs. Image environment defaults put
Laravel manifests, views and backup scratch files under `/tmp/heptapod` via native
`LARAVEL_STORAGE_PATH` and `APP_*_CACHE` variables. These defaults also reach plain
`docker compose exec app php artisan …` administrative commands. Production defaults
to `APP_MAINTENANCE_DRIVER=cache` and `APP_MAINTENANCE_STORE=redis`; maintenance state
is shared across web and worker containers and survives a web-container replacement.
Keep that shared Redis store persistent through upgrades. Do not switch to file
maintenance on an ephemeral or read-only rootfs. Do not use file sessions,
file cache or local disk for persistent data in this mode. Configuration is not
cached during the image build; each platform injects its own env at runtime.

Reproduce the disposable nginx, readonly/S3 and URL-only proof on either native
architecture (the command deletes only its own test volumes):

```sh
APP_IMAGE=heptapod-app:ci COMPOSE_PROJECT_NAME=heptapod-ops-proof OPS_PORT=10680 scripts/with-heavy-slot.sh sh tests/Deployment/ops.sh
```
