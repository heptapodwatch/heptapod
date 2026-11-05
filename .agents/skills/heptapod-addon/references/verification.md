# Verification

GitHub CI on the candidate SHA is the gate authority; locally, run the pre-push hook without bypassing it. Pre-commit runs staged formatting and frontend lint, plus full-scope Larastan when PHP files are staged. Pre-push runs `bash scripts/ci-checks.sh` (the same static, frontend, Vitest and generated-file checks as the CI checks job), then `bash scripts/run-tests.sh pre-push` (parallel PHP TIA and serial Browser tests). CI additionally runs the full PHP suite with coverage. The first TIA graph may select the full suite. Hooks require this checkout's running Compose app container. In a linked worktree, set `GIT_COMMON_DIR` to its absolute host common-Git path in `.env`; Compose mounts that path at the same location for Pest TIA subprocesses, which can lose inherited `GIT_*` exports.

CI reads `extra.heptapod.system-requirements` from every addon manifest and runs `command -v` for each binary inside the built production app image on amd64 and arm64 and the development image. It also runs `dig -v`; any missing binary or execution failure blocks the image. Reproduce these workflow steps locally after changing a declaration or Dockerfile.

All project commands run in this checkout's Compose app service. Start only this stack's backend and offline fixtures:

```sh
docker compose --profile fixtures up -d --wait app postgres redis horizon fixtures
```

`vendor` and `bootstrap/cache` live in checkout-scoped named volumes, shared by every PHP service; this keeps Laravel's atomic manifest writes off the host bind mount. The tracked `bootstrap/cache/.gitignore` stays on the host. For host IDE indexing, copy dependencies with `docker compose cp app:/workspace/vendor/. ./vendor`.

Wait for startup to finish before running tests. Do not run `composer install`, `composer dump-autoload`, or change dependency manifests during a suite: Composer clears Laravel's bootstrap manifests. Unchanged app startups and one-offs reuse dependency stamps; a fresh `node_modules` volume runs npm without reinstalling Composer dependencies. No manual package-discovery prewarm is needed. After editing `docker/dev/entrypoint.sh`, rebuild the app image before starting the stack (`docker compose build app`).

Startup regression check (after the suite):

```sh
docker compose exec app sh scripts/check-dev-startup.sh
```

Use unique `FIXTURES_HTTP_PORT`, `FIXTURES_HTTPS_PORT` and `FIXTURES_HTTPS_ALT_PORT` values in `.env` across worktrees (0 requests ephemeral host ports). Container port 444 serves the oversized TLS chain. Checks inside Compose use `http://fixtures.test` and the HTTPS aliases; trust `docker/fixtures/tls/ca.crt` only in fixture clients. CI runs PHP on the host: it publishes 80, 443 and 444 and resolves aliases to `127.0.0.1`. Verify new fixture cases from that topology, not only inside `app`.

For a host-topology reproduction, use a throwaway PHP container with `--network host`, `--add-host fixtures.test:127.0.0.1`, the checkout mounted and the app image's PHP/dependencies. Publish this checkout's Postgres/Redis on private loopback ports and set `DB_HOST`/`REDIS_HOST` to `127.0.0.1` plus those ports. Run the owning Pest test there and paste the output and resolved alias. Never reuse another worktree's services or change its hosts.

Iteration in a linked worktree:

```sh
docker compose exec app bash scripts/run-tests.sh tia
```

The runner covers default PHP suites and the serial Browser suite, both with TIA. Composer strips Git exports; do not use `composer test:tia` in a linked worktree. In the main checkout, use `docker compose exec app composer test:tia`.

Parallel checkouts use separate test databases. Create one once (example checkout `e`), then run through a temporary root PHPUnit config:

```sh
docker compose exec postgres sh -c 'createdb -U "$POSTGRES_USER" heptapod_test_e'
docker compose exec app sh -lc 'trap "rm -f phpunit.checkout-e.xml" EXIT; sed "s/value=\"heptapod_test\"/value=\"heptapod_test_e\"/" phpunit.xml > phpunit.checkout-e.xml; vendor/bin/pest --configuration phpunit.checkout-e.xml addons/uptime/tests --compact'
```

Never reset the development database. Use direct Pest for private configs, including a full equivalent with `--parallel --processes=4 --coverage`; `composer test` also runs config:clear, so forwarding `-c` to it is wrong. Type coverage takes `--configuration`, not `-c`.

At integration, run the same checks as CI and the real shared test suites:

```sh
docker compose exec app bash scripts/ci-checks.sh
docker compose exec app composer test
docker compose exec app composer test:types
docker compose exec app composer analyse
docker compose exec app composer rector:check
docker compose exec app composer deps:check
docker compose exec app composer peck
docker compose exec app composer fmt:check
docker compose exec app npm run check
docker compose exec app npm run lint
docker compose exec app npm run types:check
```

Format only explicit owned paths in parallel checkouts (`docker compose exec app vendor/bin/pint addons/uptime --format agent`). A sequential integrator may run `docker compose exec -e GIT_DIR=/git-common/worktrees/addon -e GIT_WORK_TREE=/workspace app vendor/bin/pint --dirty --format agent`. No whole-repo fixers during parallel work.

Generate centrally:

```sh
docker compose exec app composer types:generate
docker compose exec app php tests/Fixtures/generate-contract-types.php
docker compose exec app php artisan wayfinder:generate --with-form --no-interaction
docker compose exec app npm run check:fix
git diff --exit-code -- tests/Fixtures/Contracts.generated.d.ts resources/js/types/generated.d.ts resources/js/actions resources/js/routes resources/js/wayfinder
```

The installed v3 transformer uses its Laravel Data extension and enum transformer. Concrete addon DTOs need `#[TypeScript]` and a direct caret transformer dependency. Application discovery includes addon source directories. Register each addon's source/config/migrations/routes in Larastan and Rector, config directories in Larastan's `configDirectories`, and source directories in PHPUnit. Architecture rules enumerate exact installed Composer PSR-4 prefixes, not the nonexistent broad addon/vendor roots. New imports must be direct package dependencies; `Cron`/`GuzzleHttp` are permitted when installed. Runtime and CI PCOV directory is the project root; coverage is informational, with no minimum. Type coverage stays 100%.

Test exact Oh Dear field names, nested arrays, nullable values and string status-code patterns. Test split/store/reconstruct equality with repeated header names. Assert secrets are absent from JSONB config, generic output and diagnostics.

Translation keys stay in each addon's EN/DE JSON, never duplicated in root `lang/`. The root registry test owns parity and asserts every resolved value against that locale's addon resource. Temporarily remove each new provider's `loadJsonTranslationsFrom`, run the test red for a missing resolved translation, restore it and run green. A green test without the loader is not loader proof.

Fake TLS verdicts test verdict/display mapping only. Do not present supplied `verified=true` as chain validation or synthesize expiry/hostname rejection from expected results. Real offline native fixtures own security contracts. Describe captured chain tests as issuer provenance, not trust validation.

Feature tests are the default: exercise real requests, commands, jobs and recorder against Postgres/Redis. Test parser inputs through check execution and the named external fakes, never domain mocks. Extend owning tests rather than duplicating contracts. Test stale attempts, absent staging, repeated finalize/discard, transactional rollback, meaningful same-severity events, schema edits during runs, no observation and retention-safe baselines/evidence. Poll checks need offline network tests for redirects, real timeouts and cURL timing.

Every contract element needs a consuming known addon. New keys or schema changes require supervisor approval. Stop only this checkout's stack after proof unless asked to leave it running.
