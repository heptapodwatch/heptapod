# Application health

Polls a health endpoint every 60 seconds using `oh-dear-health-check-secret`. Settings preserve `result_url`, encrypted `secret`, and ordered `headers[]` with encrypted values, including duplicate header names. The secret header cannot be overridden. Redirects fail without forwarding credentials; evidence includes only the target host.

The endpoint format is https://github.com/ohdearapp/health-check-results. Laravel validates the bounded envelope before `ohdearapp/health-check-results` hydrates it. `finishedAt` is a positive Unix timestamp. Component names must be unique. `checkResults` accepts arrays and numeric-keyed JSON objects, including nonconsecutive keys, as supported by the format's package.

- `warning` makes the aggregate warning; `failed` and `crashed` make it failed.
- `ok`, `skipped`, and unknown statuses are healthy for aggregation. Their raw status is preserved.
- An empty collection succeeds with the visible informational summary “No checks reported” (`application_health.no_checks`, EN/DE). It creates no incident or warning notification. Missing components become inactive after acceptance, retaining their identity and comparison baseline.
- Unreachable endpoints, unsuccessful HTTP status codes, redirects, invalid JSON or payloads, oversized responses, stale results, and excessive future timestamps fail. They do not replace accepted component baselines.
- Operator configuration `application_health.stale_after_seconds` defaults to 300; the boundary is inclusive. `clock_skew_seconds` permits up to 60 seconds into the future. Both must be integers, with staleness positive and clock skew nonnegative.
- `max_components` defaults to 1000 and accepts 1–1000. `max_response_bytes` defaults to 1 MiB and must be positive. The core outbound HTTP seam bounds both wire and decoded bytes during transfer. Its HTTP budget is the remaining execution deadline, capped at 30 seconds.

Components and per-run snapshots are addon-owned. Component identities exist immediately, but only recorder acceptance publishes snapshots and updates baselines. Raw history can be pruned without losing baselines or suppression identities.

`application_health.component_changed` emits on a status, `notificationMessage`, or `shortSummary` change against the accepted baseline. First-seen components, label-only edits, and meta-only edits emit nothing. The dedupe key includes the component ID, accepted predecessor run, and SHA-256 change identity. Events carry a component suppression subject, not a separate snooze implementation.

Event messages and summaries are capped at 255 characters. A field containing a configured secret or header value, case-insensitively, is omitted from the event. Meta never enters events. Stored display strings and matching meta keys/values are redacted; names used for identity are hashed. Change identities use full source messages, so changes past the display limit remain observable. Unrelated source data is preserved. No request settings or raw response bodies are logged.

This package provides check execution and typed display data. API endpoints and UI are outside its scope.

Run its feature checks against Postgres and offline fixtures:

```sh
docker compose --profile fixtures up -d --wait app postgres redis fixtures
docker compose exec app vendor/bin/pest addons/application_health/tests --compact
```

The shared core HTTP matrix owns transport limits, TLS, redirects, truncated failures, and deadlines. Addon tests bind `FakeOutboundHttp` to prove typed failure mapping and parser/recorder behavior; one real offline health response proves seam integration and cURL timing. For that CI host-topology check, set `APPLICATION_HEALTH_FIXTURE_URL` to the loopback fixture alias with its published HTTP port. Accepted 3xx responses fail with only a redacted Location host; transport failures retain their bodyless `failure_kind` and known HTTP status.
