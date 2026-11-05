# Offline check fixtures

The optional `fixtures` Compose profile serves deterministic HTTP and TLS responses without internet access. Run the HTTP contract smoke test from a container on the checkout's network:

```sh
docker compose --profile fixtures up -d fixtures
network=$(docker inspect "$(docker compose ps -q fixtures)" --format '{{range $name, $_ := .NetworkSettings.Networks}}{{$name}}{{end}}')
docker run --rm --network "$network" \
    -v "$PWD/docker/fixtures:/fixtures:ro" --entrypoint sh \
    curlimages/curl /fixtures/verify.sh
docker compose --profile fixtures stop fixtures
```

Host ports bind to loopback. Set `FIXTURES_HTTP_PORT` (default `8480`) and `FIXTURES_HTTPS_PORT` (default `8481`) in `.env` when running multiple checkouts. Containers use ports 80 and 443. The aliases `fixtures.test`, `external.fixtures.test`, `expired.fixtures.test` and `wrong-host.fixtures.test` all resolve to the fixture service inside its Compose network.

OpenResty is nginx with bundled Lua. `ngx.sleep` supplies nonblocking 3-second and 35-second delays without another backend or a custom module. All other bodies are static files or nginx status/redirect responses. The service loads `nginx.conf` from the directory bind mount. After changing fixture routes or rebasing, run `docker compose --profile fixtures restart fixtures fixtures-alt` before direct tests; the test runner checks the loaded config hash and fails if the fixtures are stale. The pre-push hook recreates both fixtures automatically.

## Fixture URLs

The host column uses the default published ports. Named host URLs require local DNS or curl's `--resolve`; localhost URLs do not. Every primary HTTP path below is also served under https://fixtures.test with the valid certificate, and under the two TLS error aliases. The TLS table lists the check entry points for those variants. Directory `index.html` URLs serve the same files as the trailing-slash URLs.

| In-network URL                                   | Host URL                                          | Check type                                    | Response / purpose                                                                                                                                 |
| ------------------------------------------------ | ------------------------------------------------- | --------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------- |
| http://fixtures.test/                            | http://localhost:8480/                            | uptime, performance, broken_links, lighthouse | 200; crawl root with good/broken internal and external links. Lightweight baseline for performance and Lighthouse, not a calibrated score fixture. |
| http://fixtures.test/docs/                       | http://localhost:8480/docs/                       | broken_links, sitemap                         | 200; crawl depth 1, links to depth 2 and home.                                                                                                     |
| http://fixtures.test/docs/level-two/             | http://localhost:8480/docs/level-two/             | broken_links, sitemap                         | 200; depth 2, links to depth 3 and a missing page.                                                                                                 |
| http://fixtures.test/docs/level-two/level-three/ | http://localhost:8480/docs/level-two/level-three/ | broken_links, sitemap                         | 200; depth 3 and cycle back to depth 1.                                                                                                            |
| http://fixtures.test/blocked/                    | http://localhost:8480/blocked/                    | broken_links                                  | 200; robots-disallowed page containing a broken link that a robots-aware crawl must skip.                                                          |
| http://fixtures.test/robots.txt                  | http://localhost:8480/robots.txt                  | broken_links, sitemap                         | 200; disallows `/blocked/`, advertises the valid sitemap.                                                                                          |
| http://fixtures.test/missing-internal            | http://localhost:8480/missing-internal            | broken_links, sitemap                         | 404; broken link from the root and broken sitemap.                                                                                                 |
| http://fixtures.test/missing-nested              | http://localhost:8480/missing-nested              | broken_links                                  | 404; broken link at depth 2.                                                                                                                       |
| http://fixtures.test/missing-blocked             | http://localhost:8480/missing-blocked             | broken_links                                  | 404; linked only from the robots-disallowed page.                                                                                                  |
| http://external.fixtures.test/                   | http://external.fixtures.test:8480/               | broken_links                                  | 200; good link on a separate origin, still offline.                                                                                                |
| http://external.fixtures.test/missing            | http://external.fixtures.test:8480/missing        | broken_links, sitemap                         | 404; broken external link. All non-root paths on this HTTP origin return 404.                                                                      |
| http://fixtures.test/status/200                  | http://localhost:8480/status/200                  | uptime, sitemap                               | 200; successful response.                                                                                                                          |
| http://fixtures.test/status/404                  | http://localhost:8480/status/404                  | uptime, broken_links                          | 404; not found response.                                                                                                                           |
| http://fixtures.test/status/500                  | http://localhost:8480/status/500                  | uptime, sitemap                               | 500; server error response.                                                                                                                        |
| http://fixtures.test/status/503                  | http://localhost:8480/status/503                  | uptime                                        | 503; unavailable response.                                                                                                                         |
| http://fixtures.test/redirect/start              | http://localhost:8480/redirect/start              | uptime, broken_links                          | 301 to `/redirect/hop`.                                                                                                                            |
| http://fixtures.test/redirect/hop                | http://localhost:8480/redirect/hop                | uptime, broken_links                          | 302 to `/redirect/final`.                                                                                                                          |
| http://fixtures.test/redirect/final              | http://localhost:8480/redirect/final              | uptime, broken_links                          | 200; final destination of the two-hop redirect chain.                                                                                              |
| http://fixtures.test/slow                        | http://localhost:8480/slow                        | uptime, performance                           | 200 after 3 seconds; slow-response threshold.                                                                                                      |
| http://fixtures.test/timeout                     | http://localhost:8480/timeout                     | uptime, performance                           | 200 after 35 seconds; times out with a 30-second client limit.                                                                                     |
| http://fixtures.test/keyword-present.html        | http://localhost:8480/keyword-present.html        | uptime                                        | 200; contains `HEPTAPOD_FIXTURE_READY`.                                                                                                            |
| http://fixtures.test/keyword-absent.html         | http://localhost:8480/keyword-absent.html         | uptime                                        | 200; does not contain the required keyword.                                                                                                        |
| http://fixtures.test/response.json               | http://localhost:8480/response.json               | uptime                                        | 200 JSON; `ready: true`, `count: 3`, nested keyword for response assertions.                                                                       |
| http://fixtures.test/sitemap.xml                 | http://localhost:8480/sitemap.xml                 | sitemap                                       | 200; valid sitemap with four reachable pages.                                                                                                      |
| http://fixtures.test/sitemap-index.xml           | http://localhost:8480/sitemap-index.xml           | sitemap                                       | 200; index references valid and broken sitemaps.                                                                                                   |
| http://fixtures.test/sitemap-broken.xml          | http://localhost:8480/sitemap-broken.xml          | sitemap, broken_links                         | 200; lists 200, 404, 500 and broken external targets.                                                                                              |
| http://fixtures.test/sitemap-invalid.xml         | http://localhost:8480/sitemap-invalid.xml         | sitemap                                       | 200; malformed XML and invalid location.                                                                                                           |
| http://fixtures.test/mixed-content.html          | http://localhost:8480/mixed-content.html          | mixed_content                                 | 200; HTTP control page. Use its HTTPS variant below to detect mixed content.                                                                       |
| http://fixtures.test/assets/style.css            | http://localhost:8480/assets/style.css            | mixed_content                                 | 200; HTTP stylesheet referenced by the HTTPS mixed-content page.                                                                                   |
| http://fixtures.test/assets/script.js            | http://localhost:8480/assets/script.js            | mixed_content                                 | 200; HTTP script referenced by the HTTPS mixed-content page.                                                                                       |
| http://fixtures.test/assets/pixel.svg            | http://localhost:8480/assets/pixel.svg            | mixed_content                                 | 200; HTTP image referenced by the HTTPS mixed-content page.                                                                                        |
| http://fixtures.test/health/ok.json              | http://localhost:8480/health/ok.json              | application_health                            | 200; Oh Dear health result with status `ok`.                                                                                                       |
| http://fixtures.test/health/warning.json         | http://localhost:8480/health/warning.json         | application_health                            | 200; status `warning`.                                                                                                                             |
| http://fixtures.test/health/failed.json          | http://localhost:8480/health/failed.json          | application_health                            | 200; status `failed`.                                                                                                                              |
| http://fixtures.test/health/crashed.json         | http://localhost:8480/health/crashed.json         | application_health                            | 200; status `crashed`.                                                                                                                             |
| http://fixtures.test/health/skipped.json         | http://localhost:8480/health/skipped.json         | application_health                            | 200; status `skipped`.                                                                                                                             |
| http://fixtures.test/health/malformed.json       | http://localhost:8480/health/malformed.json       | application_health                            | 200; intentionally invalid JSON.                                                                                                                   |
| http://fixtures.test/health/secret.json          | http://localhost:8480/health/secret.json          | application_health                            | 200 with `oh-dear-health-check-secret: fixture-secret`, otherwise 403.                                                                             |

| In-network TLS URL                       | Host TLS URL                                  | Check type                                          | Response / purpose                                                                                       |
| ---------------------------------------- | --------------------------------------------- | --------------------------------------------------- | -------------------------------------------------------------------------------------------------------- |
| https://fixtures.test/                   | https://fixtures.test:8481/                   | certificate_health, uptime, performance, lighthouse | Valid hostname and unexpired certificate signed by the fixture CA; 200 when trusted.                     |
| https://expired.fixtures.test/           | https://expired.fixtures.test:8481/           | certificate_health                                  | Expired certificate with correct hostname; rejects verification even with trusted CA.                    |
| https://wrong-host.fixtures.test/        | https://wrong-host.fixtures.test:8481/        | certificate_health                                  | Unexpired certificate for `unrelated.fixtures.test`; rejects hostname verification even with trusted CA. |
| https://fixtures.test/mixed-content.html | https://fixtures.test:8481/mixed-content.html | mixed_content                                       | 200 when trusted; references HTTP image, script and stylesheet on `fixtures.test`.                       |

Absolute links, sitemap locations and mixed-content assets use in-network URLs. Run crawlers and browser checks on the Compose network; the host-port variants are for inspecting individual responses, not a rewritten copy of the site. `external.fixtures.test` is a separate HTTP origin, not a separate TLS certificate fixture.

`domain`: not covered. Reserved `.test` names have no public registration or RDAP expiry data. `dns`: Docker resolves the aliases for HTTP access, but this service provides no authoritative DNS records or DNS change scenarios. `cron`: not covered; cron pings target the Heptapod receiver, not this service. This checkout has no cron receiver route.

## TLS and health clients

Trust `docker/fixtures/tls/ca.crt` in the check client. HTTPS certificates do not cover localhost. Host curl examples:

```sh
curl --resolve fixtures.test:8481:127.0.0.1 \
    --cacert docker/fixtures/tls/ca.crt https://fixtures.test:8481/
curl --resolve external.fixtures.test:8480:127.0.0.1 \
    http://external.fixtures.test:8480/
curl -H 'oh-dear-health-check-secret: fixture-secret' \
    http://localhost:8480/health/secret.json
```

The expired and wrong-host certificates use the same CA, so trusting it isolates the intended TLS errors. `curl --insecure` can inspect their HTTP bodies but must not be used to test certificate validation.

The committed private keys are public, disposable test data. Never use them outside this fixture service. Regenerating certificates also replaces the CA; update the client's trust store:

```sh
docker run --rm -v "$PWD/docker/fixtures:/fixtures" --entrypoint sh \
    alpine/openssl /fixtures/generate-tls.sh
```

Native TLS security cases use https://names.fixtures.test (CN conflicts with SAN), https://www.wildcard.fixtures.test, https://wildcard.fixtures.test, https://nested.www.wildcard.fixtures.test, https://alt.fixtures.test and https://self-signed.fixtures.test. Only name-matching tests trust `tls/names.crt` as an operator PEM bundle. The separate self-signed certificate stays untrusted. https://fixtures.test:444 serves a 21-certificate chain to test bounded display capture without overriding native verification.

Regenerate these additional fixtures after the base certificates, inside this checkout's app container:

```sh
docker compose exec app sh docker/fixtures/generate-name-tls.sh
```

Health response format follows https://github.com/ohdearapp/health-check-results. `finishedAt` is fixed fixture data, not a live health timestamp.

## Certificate-health snapshot provenance

`addons/certificate_health/tests/Fixtures/replacement.crt` was signed by the
throwaway offline fixture CA with CN/SAN `fixtures.test`, valid from
2026-10-01 22:48:34 UTC through 2046-09-26 22:48:34 UTC. The optional second
argument to `generate-tls.sh` includes that signing step. Both directory
arguments must be absolute. To generate into temporary directories without
changing any tracked certificate:

```sh
docker compose exec app sh -c 'work=$(mktemp -d); sh docker/fixtures/generate-tls.sh "$work" "$work/certificate-health"; echo "$work"'
```

The new replacement is signed by the newly generated CA, not the committed CA.
Keys and fingerprints change on each run; replacing tracked snapshots requires
updating their pinned fingerprints deliberately.

`prior.crt` is an older **self-signed** certificate (subject and issuer both
`CN=fixtures.test`, SAN `DNS:fixtures.test`, CA:TRUE, serverAuth), not a leaf
signed by a missing external CA. Its validity is 2026-10-01 19:32:12 UTC through
2046-09-26 19:32:12 UTC. It was made in the shape of
`openssl req -x509 -newkey rsa:2048 -nodes -sha256 -days 7300 -subj
'/CN=fixtures.test' -addext 'subjectAltName=DNS:fixtures.test' -addext
'extendedKeyUsage=serverAuth'`, with a disposable private key that is no longer
retained. Keep that historical snapshot: it supplies the old issuer and pinned
fingerprint for the issuer-change regression. The generator does not overwrite it.
