# Contributing to Heptapod

Open an issue at https://github.com/heptapodwatch/heptapod/issues before starting a large change. Describe the user outcome, scope and a way to verify it. Keep fixes focused. Follow the [Code of Conduct](CODE_OF_CONDUCT.md); use [private security reporting](SECURITY.md) for vulnerabilities.

## Development setup

Set up a checkout, seed demo data and run the tools as described in [DEVELOPMENT.md](DEVELOPMENT.md).

## Checks and hooks

Run commands through `docker compose exec app …`. The [tooling section](DEVELOPMENT.md#tooling) lists formatting, spelling, type, static-analysis, dependency and build commands. Pre-push runs the static checks; CI also enforces generated-file, production image, deployment OPS and security gates. Tests need the [offline check fixtures](DEVELOPMENT.md#offline-check-fixtures).

Application tests run in CI, and the PHP Gate, non-quarantined Feature/Unit tests, architecture tests and Vitest block merges. Run the affected tests as shown in [tooling](DEVELOPMENT.md#tooling), in random order, and record the seed; see [operations](docs/deploy/operations.md#development-test-hooks) for commands and isolation rules. Browser, mutation and coverage suites remain disabled. The `composer test:types` static type-coverage gate remains enabled. Describe actual commands, results and manual verification in the pull request.

Tests use real Postgres and Redis, fixed clocks, isolated database state and fake external seams. Add regression coverage through feature entry points; do not use real network calls, sleeps or retries. A failure with no code cause is quarantined in a separate commit and tracked in a public issue with a responsible maintainer, not hidden with a skip. A quarantined file re-enters after five green isolated runs.

Keep the [Git hooks](DEVELOPMENT.md#git-hooks) enabled. Pre-commit formats staged files and runs checks; commit-msg enforces Conventional Commits. Hooks require the checkout's stack to be running.

## Pull requests

- State the outcome, verification commands and any remaining risks.
- Follow existing patterns and the repository's `AGENTS.md` rules. Keep identifiers and package namespaces unchanged unless the change explicitly requires it.
- Put user-facing text in the English and German translation catalogs.
- Regenerate tracked routes and data types when their source changes.
- Do not include secrets, captured account data, private paths or internal service addresses.
- Leave license changes and new dependencies for explicit maintainer review.

Submit pull requests at https://github.com/heptapodwatch/heptapod/pulls. Report vulnerabilities through https://github.com/heptapodwatch/heptapod/security/advisories/new, not public issues.
