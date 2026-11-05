## Outcome

Describe the user-visible result and link the related issue.

## Verification

List commands and actual results. Include redacted evidence made with synthetic data.
Application tests run in CI, and the PHP Gate, non-quarantined PHP tests, architecture tests and Vitest block merges. Run affected PHP tests with `docker compose exec app bash scripts/run-tests.sh focused <test-file>` or the PHP suites with `docker compose exec app bash scripts/run-tests.sh ci-php`; use random order and record the seed. See `docs/deploy/operations.md` (Development test hooks). Browser, mutation and coverage suites remain disabled.

## Risks

List remaining risks, compatibility effects and follow-up work, or state none.

- [ ] Scope is focused and generated files are current where applicable.
- [ ] User-facing text includes English and German translations where applicable.
- [ ] Examples, logs and screenshots contain no secrets or private data.
- [ ] Hooks remain enabled and commit messages follow Conventional Commits.
