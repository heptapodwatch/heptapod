---
name: heptapod-addon
description: Create or change a Heptapod check addon, its schemas, observations and accepted-run datasets.
---

# Creating a check addon

Read the existing uptime/performance packages and `app/Contracts` before editing. Addons are Composer path packages under `addons/*`, with vendor `heptapod/` and namespace `Heptapod\Addons\<Name>`.

1. Read [contracts](references/contracts.md), [storage](references/storage.md) and [verification](references/verification.md).
2. Pick one of the eleven `CheckKey` values. A key is not an installed addon; missing registry entries must fail loudly.
3. Implement `CheckType` in the addon. Register its class-string (`$registry->register(MyCheck::class)`) through its Laravel service provider. The singleton registry resolves a fresh container instance on every get, so external seam swaps reach execution. Core code never imports an addon.
4. Declare config and metric schemas before execution. Keep exact Oh Dear key names and value types. Put secret leaves in `FieldType::Secret`. Separate validation rules from rendering hints.
5. Execute against scalar context identities, decrypted validated config and the bounded history reader. Return an outcome, or null when no observation exists. Let exceptions reach the engine.
6. Stage large datasets through the persistence seam. Only the recorder can finalize the fenced, accepted attempt.
7. Add addon-owned translated keys in English and German, tests and SDK-shaped fixtures. Never copy addon keys into root `lang/`. Generate contract types and run the architecture rules.

Declare every imported library as a direct caret dependency in the addon manifest. Use provider resource loaders (`mergeConfigFrom`, `loadMigrationsFrom`, `loadRoutesFrom`, `loadJsonTranslationsFrom`) for resources the addon actually owns. Composer path-package auto-discovery boots the provider; do not import it into core. Use uptime/performance as runnable execution examples, not provider skeletons. Addon feature tests use the root TestCase/RefreshDatabase registration, or a package-local Pest bootstrap; register their directories in the root suite exactly once.

Security-critical logic (TLS trust, hostname matching, crypto, signatures, auth) only via native platform features or maintained packages, never self-written.

The contract serves the eleven known checks, not a third-party plugin platform. Do not add an element without naming its consuming check. Broken-links, mixed-content and sitemap checks crawl plain HTTP with spatie/crawler. They do not execute JavaScript or use Chromium. One crawl addon owns broken-links and mixed-content CheckTypes with one internal freshness path; do not invent a shared core crawl contract. Browserless is not part of the product.

## Fan-out template: copy / avoid

Copy:

- Class-string registration, constructor-injected external seams and real engine-path seam proof.
- Token-scoped staging, transactional finalization, harmless absent staging, retained accepted baselines and core-invoked pruning.
- Protocol-versus-severity separation and per-addon accepted-change event identities. Certificate fingerprint replacement must emit `certificate_health.certificate_changed` even at unchanged severity.
- Native/package security and parsing, bounded evidence and precise null/no-observation semantics.
- Addon-owned resources and direct caret dependencies.

Avoid:

- Missing same-severity change events or events repeated on every poll.
- Root translation copies that mask provider loading. Assert resolved values against each locale's addon JSON and prove removing the provider loader makes the test fail.
- Fake verdicts presented as native security tests. Fake TLS responses cover verdict/display mapping; real native fixture tests own trust, expiry and hostname contracts.
- Compose-only fixture verification when CI runs PHP outside Compose. Verify loopback aliases and published ports from CI's host topology.
- Copying cron's push/grace policy into application health.
- Inventing a shared core crawl contract.

Worker constraints:

- Domain/DNS: retain accepted comparison baselines; distinguish unavailable data from negative findings.
- Application health: define empty/skipped-component policy and meaningful status/message changes; reuse suppression contracts.
- Lighthouse: raw LHR through the Chromium seam; no Browserless or renderHtml.
- Sitemap: maintained parser first; bounded reachability checks with HEAD-to-GET fallback.
- Crawl: one package, two CheckTypes and one freshness path; use spatie/crawler and plain HTTP only.

## How to test your addon

Feature tests live in `addons/<key>/tests`, outside production addon namespaces. Wire each directory into phpunit.xml and tests/Pest.php. Core integration tests live in `tests/Feature`. Run the real check through the real engine/recorder against Postgres and Redis. Do not replace dispatcher, recorder, routing, models or other domain collaborators. Core defines the external contracts; transport adapters supply their real implementations and parser-through-run fixtures.

Bind a shared `Heptapod\Testing` fake to its `Heptapod\Contracts` interface with `app()->instance(...)` before resolving or dispatching the check:

| External boundary                  | Contract                   | Shared fake                    | Consumers       |
| ---------------------------------- | -------------------------- | ------------------------------ | --------------- |
| Outbound HTTP                     | `Http\OutboundHttp`        | `FakeOutboundHttp`             | Every fetch     |
| DNS / spatie/dns                   | `DnsResolver`              | `FakeDnsResolver`              | DNS             |
| TLS fetch / spatie/ssl-certificate | `CertificateFetcher`       | `FakeCertificateFetcher`       | Certificate     |
| RDAP/WHOIS transport               | `DomainRegistrationLookup` | `FakeDomainRegistrationLookup` | Domain          |
| Lighthouse execution               | `ChromiumRenderer`         | `FakeChromiumRenderer`         | Lighthouse only |

Configure exact requests with `respondWith`, or Lighthouse's `respondWithLighthouse`. Fakes match all arguments, including the nameserver/port and browser options; unexpected requests throw instead of returning an empty or successful result. Replies wrap untouched payloads plus source metadata: DNS records/raw lines, display-only PEM chain plus explicit native TLS verification verdict, RDAP JSON or WHOIS text and the raw Lighthouse report (LHR) JSON. The real addon parsers still execute. Transport adapters throw `ExternalNetworkException` or `ExternalTimeoutException`, preserving the original cause; configure those same exceptions in fakes for error runs. Never log request options/headers or raw secret-bearing payloads.

Every external HTTP fetch MUST use `Heptapod\Contracts\Http\OutboundHttp`; bind `FakeOutboundHttp` for addon behavior tests. Library integrations use its guarded `clientConfig`, not a second transport. See the HTTP section in [contracts](references/contracts.md) for request policies and typed failures. The shared core adversarial matrix owns real transport limits/TLS/redirect tests. Use `travel` for PHP time and `Notification::fake` for notification transports. Uptime and notification channels retain their signed-off transports until their approved migration; no new exceptions. PHP time travel does not change Postgres's clock; seed due/lease timestamps for database-clock tests. Keep queues real for queue behavior; fake only when queue delivery is not what the test proves.

Assert accepted runs, transitions, evidence and notification behavior through the actual engine and database. Invalid parser fixtures go through that path too; no isolated parser tests or ad hoc domain mocks. PDF reports use spatie/laravel-pdf with Gotenberg, not the Lighthouse seam.
