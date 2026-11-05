# Heptapod product requirements

Heptapod is an open-source, self-hosted monitoring suite for freelancers and small agencies. It brings website checks, application health, opt-in notifications and client reporting into one instance that the operator controls.

This document describes product requirements and planned capabilities, not a guarantee that every feature ships in the current release. Use the [README](README.md) and [operator documentation](docs/deploy/operations.md) for supported installation and operation.

## Users and problem

- A freelance developer maintains 10–40 client sites and needs to find failures before clients report them.
- A small agency maintains 30–150 sites and needs client-scoped access, consistent checks and notification routing.
- A client receives reports and status links without needing access to the monitoring instance.

For example, an operator monitors `shop.example.com`, `portal.example.com` and `docs.example.com` for three synthetic clients. The operator uses the dashboard and API, follows relevant alerts and sends client reports. Examples must use reserved domains and synthetic accounts; no real account or migration is required to evaluate the product.

## Goals and boundaries

- Make a Docker Compose deployment usable from the first check onward, with documented upgrades, backups and recovery.
- Cover uptime, performance, certificates, domains, DNS, sitemap, broken links, mixed content, Lighthouse, scheduled tasks and application health.
- Organize sites by client and tag, with client-scoped permissions and notification routing.
- Support English and German throughout the interface and outbound messages.
- Provide an API and optional Oh Dear import for operators who choose to migrate.
- Use the same check contracts for built-in and third-party addons.

The product monitors, reports and sends opt-in notifications. It is not an error tracker, APM service, log platform, task tracker or on-call system. It has no incident assignees, claiming, acknowledgement, escalation policies or on-call rotations. Multi-region probes, a hosted service and an addon marketplace are outside the initial scope.

## Product principles

- Show the current state before configuration or history.
- Use fewer options and useful defaults rather than exposing every implementation detail.
- Keep a healthy instance quiet. Reserve prominent status colors for warnings and failures.
- Make failures observable. Distinguish a failed check from an inability to perform the check.
- Keep data and credentials under the operator's control.
- Prefer maintained packages and shared interface patterns over custom alternatives.

## Instance and access

One instance serves one team. There is no team switcher. Roles are Owner, Admin and Member; members may be limited to specific clients. Record ownership and permission roles do not imply responsibility for responding to incidents.

Every site belongs to a client, including an internal client for the operator's own sites. Sites support tags. Each site has at most one check of each type. Configuration is validated against the addon's schema.

Locale and timezone are user preferences. Client locale and timezone govern client-facing reports and messages. German uses formal address. All user-facing text comes from the shared translation catalogs.

## Monitoring requirements

| Check | Purpose | Result details |
| --- | --- | --- |
| Uptime | Check HTTP status, response content and configured request behavior | Availability history, incidents, response status and timing |
| Performance | Track response times and thresholds | Trends and timing breakdowns |
| Certificate | Check expiry, validity, chain and hostname | Issuer, names, validity dates and changes |
| Domain | Check registration status and expiry where published | Registrar, expiry and registry status |
| DNS | Watch selected record types and detect changes | Current records and timestamped differences |
| Sitemap | Validate a sitemap and check its listed URLs | Fetch, parsing and URL failures, with documented limits |
| Broken links | Crawl configured pages and inspect links | Broken URL, response status and source page |
| Mixed content | Find insecure resources on HTTPS pages | Page, resource URL and element type |
| Lighthouse | Measure configured pages and score thresholds | Category scores, metrics, history and report |
| Scheduled tasks | Receive heartbeats and detect missed runs | Schedule, grace period, last ping and run history |
| Application health | Read compatible health JSON from an authenticated endpoint | Named checks, summaries and metadata |

Operators can configure checks, request a run and pause monitoring. Check detail pages share a state header, configuration summary, type-specific results and bounded history. Forms use shared schema-driven field patterns, inline validation and clear units.

Scheduled tasks provide copyable ping URLs and examples for curl, cron and `spatie/laravel-schedule-monitor`. Domain checks distinguish an unpublished expiry date from an expired domain. Crawling uses HTTP responses and does not render JavaScript. Resource limits and partial results must be visible, not silently presented as complete success.

### States and incidents

- “Down” means an uptime outage. A non-uptime check result is “Failed,” not “Down.”
- “Check error” means the system could not complete the check.
- Warning, paused, pending, maintenance and stale states remain distinct.
- Status uses a label or icon as well as color.
- A failing check opens an incident after its configured failure threshold. A passing result resolves it.
- Warnings create events and may notify subscribers, but do not open incidents.
- Maintenance and snoozing suppress alerts according to their documented scope without hiding the underlying monitoring state.
- Old results outside retention show an explained empty state.

The dispatcher schedules due checks and workers execute them on bounded queues. Scheduled-task pings are push-based. Histories and high-volume queries use bounded, indexed access and retention or rollups rather than unbounded result scans.

## Notifications

Following a site or scope means receiving alerts, not accepting work. Notification preferences and role permissions remain separate.

- Channels include email, Slack, Discord, Microsoft Teams, Telegram, Pushover, ntfy and signed generic webhooks.
- Routing combines event type with instance, client, site or tag scope.
- Each supported channel provides a test action with an explicit outcome.
- Noise controls include failure thresholds, repeat intervals, quiet hours and warning digests where supported.
- Messages identify the site, check, result and time, with a link to details. Recovery messages include the duration.
- Webhook severity describes urgency, not the cause of a failure.
- Credentials are encrypted at rest and excluded from logs and exports.

## API and optional import

The API supports bearer tokens, client-scoped access and documented error responses. Operators can manage monitors, request runs, inspect check results and history, and manage maintenance and scheduled tasks within the supported API surface.

Oh Dear compatibility targets documented monitor and check operations, application-health JSON and scheduled-task integration. It does not imply compatibility with every vendor endpoint. The [API guide](docs/api.md) defines the supported surface.

Import is optional. It previews supported monitors, settings, tags, scheduled tasks and maintenance definitions before applying changes. Unsupported or invalid settings must be reported explicitly. Import examples and evaluation fixtures use synthetic data, not captured customer accounts.

## Planned client-facing capabilities

Monitoring and notifications take priority over these capabilities. Their inclusion here does not imply availability in the current release.

### Reports

Reports are generated on demand or on a client schedule, as PDF and expiring web links. Planned sections include uptime, incidents, response-time trends, certificate and domain status, Lighthouse scores and crawl findings. Operators can choose sections and add a short summary of work performed.

Reports support neutral styling or a client logo and accent color. Sender details, message text, locale and timezone are configurable per client.

### Status pages

Status pages group selected sites under public display names. Planned content includes overall state, component availability, incidents, maintenance and history. Pages are server-rendered, cached and usable without JavaScript.

Custom domains require verified ownership and TLS. Email subscriptions require confirmation and an unsubscribe path. Public, unlisted and password-protected visibility, RSS and client-viewer access belong to this phase, not the core monitoring requirement.

## Interface and accessibility

The interface uses shared page, table, filter, form, empty-state and navigation patterns. It is data-first, keyboard-accessible and usable at desktop and mobile sizes in light and dark themes.

- Tables and page headers expose the current state at a glance.
- Response times, percentages and timestamps use tabular figures.
- Loading, empty, error and stale states explain what is happening.
- Foreign live updates wait behind an “N new changes” control; a user's successful action appears immediately.
- Motion communicates a change and respects reduced-motion preferences.
- Charts and status displays have non-color alternatives.

## Addons and implementation constraints

Built-in checks are Composer path packages in `addons/*`. Identifiers retain the `Heptapod\` namespace and `heptapod` command and package names. Addons depend on core through `Heptapod\Contracts\*`.

Each check declares an identity, configuration schema, result shape and scheduling requirements. Optional type-specific interface components fit shared layouts. A generic result view keeps addons usable without custom presentation.

The stack is Laravel 13 and PHP 8.5, Inertia 3, React 19, TypeScript and Tailwind 4. PostgreSQL stores monitoring data; Redis and Horizon serve queues. Docker Compose separates application, dispatcher, scheduler and worker roles. Browser-based services are optional and resource-limited. The deployment targets amd64 and arm64.

Operating requirements include persistent storage, encrypted secrets, backup and restore instructions, documented key rotation, upgrade checks and an optional external instance heartbeat. Capacity depends on check mix, frequency, retention and browser workload; deployment documentation owns measured sizing guidance.

## Delivery and quality

Core monitoring, access, notifications and operation come first. API coverage, optional import, crawling and browser checks extend that foundation. Reports and status pages follow. A stable third-party addon API requires its own compatibility documentation.

Release evaluation uses synthetic sites and accounts. It covers initial setup, a real completed check, a failure and recovery, notification outcomes, API access boundaries, backup and restore, and an upgrade with persistent data. It does not require access to any contributor's machine, account or network.

Formatting, spelling, lint, type checks, static analysis, generated declarations and dependency audits are active gates. Production image, deployment OPS and security checks remain active. Application tests run in CI, and the PHP Gate, non-quarantined PHP tests, architecture tests and Vitest block merges; Browser, mutation and coverage suites remain disabled, and type coverage remains in the static gate. Run affected tests with `docker compose exec app bash scripts/run-tests.sh focused <test-file>` or the PHP suites with `docker compose exec app bash scripts/run-tests.sh ci-php`, using random order and a recorded seed as shown in [operations](docs/deploy/operations.md#development-test-hooks). See [CONTRIBUTING.md](CONTRIBUTING.md) for the contributor workflow.
