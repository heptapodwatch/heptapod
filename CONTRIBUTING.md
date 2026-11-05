# Contributing to Heptapod

Open an issue at https://github.com/heptapodwatch/heptapod/issues before starting a large change. Describe the user outcome, scope and a way to verify it. Keep fixes focused. Follow the [Code of Conduct](CODE_OF_CONDUCT.md); use [private security reporting](SECURITY.md) for vulnerabilities.

## Development setup

Install Git and Docker with Compose. The demo and smoke scripts also need Bash, curl, jq and standard Unix tools. Follow the [README development setup](README.md#development), including creation of the external `heptapod-heavy-locks` volume. PHP, Composer and Node run in the app container; host installations are not required.

Install the Git hooks from the running stack:

```sh
docker compose exec app npm run hooks:install
```

For the demo scripts, set `COMPOSE_PROJECT_NAME` in `.env` to this checkout's existing project name, shown by `docker compose config --format json | jq -r .name`. Do not reuse another checkout's project name. Build with the source-input label required by the stale-image guard, then seed the demo scenario and verify the main pages:

```sh
DEV_IMAGE_INPUT_HASH=$(./scripts/dev-image-hash.sh) docker compose build
./scripts/dev-up.sh attention
./scripts/smoke.sh
```

`dev-up.sh` resets that checkout's database and queues. Never use it on a real instance or a checkout containing production data. Demo login is `test@example.test` / `password`. Keep development ports local and use synthetic data in examples, screenshots and reports.

## Checks and hooks

Run commands through `docker compose exec app …`. The [README tooling section](README.md#tooling) lists formatting, spelling, type, static-analysis, dependency and build commands. Pre-push runs the static checks; CI also enforces generated-file, production image, deployment OPS and security gates.

Application tests run in CI, and the PHP Gate, non-quarantined Feature/Unit tests, architecture tests and Vitest block merges. Run affected PHP tests with `docker compose exec app bash scripts/run-tests.sh focused <test-file>` or the non-quarantined Feature/Unit suites with `docker compose exec app bash scripts/run-tests.sh ci-php`. Use random order and record the seed; see [operations](docs/deploy/operations.md#development-test-hooks) for commands and isolation rules. Browser, mutation and coverage suites remain disabled. The `composer test:types` static type-coverage gate remains enabled. Describe actual commands, results and manual verification in the pull request.

Tests use real Postgres and Redis, fixed clocks, isolated database state and fake external seams. Add regression coverage through feature entry points; do not use real network calls, sleeps or retries. A failure with no code cause is quarantined in a separate commit and tracked in a public issue with a responsible maintainer, not hidden with a skip. A quarantined file re-enters after five green isolated runs.

Keep hooks enabled. Pre-commit formats staged files and runs checks; commit-msg enforces [Conventional Commits](https://www.conventionalcommits.org/en/v1.0.0/), for example `fix: preserve paused check state`. Stage whole files: formatter fixes can stage other working-copy changes in the same file. Hooks require the checkout's stack to be running.

## Pull requests

- State the outcome, verification commands and any remaining risks.
- Follow existing patterns and the repository's `AGENTS.md` rules. Keep identifiers and package namespaces unchanged unless the change explicitly requires it.
- Put user-facing text in the English and German translation catalogs.
- Regenerate tracked routes and data types when their source changes.
- Do not include secrets, captured account data, private paths or internal service addresses.
- Leave license changes and new dependencies for explicit maintainer review.

Submit pull requests at https://github.com/heptapodwatch/heptapod/pulls. Report vulnerabilities through https://github.com/heptapodwatch/heptapod/security/advisories/new, not public issues.
