# Sanitized real Oh Dear responses

These fixtures cover every endpoint called by the importer:

- `GET me`: `me.json`.
- `GET monitors`: `monitors.json`, including captured `data`, `links` and `meta`.
- `GET monitors/{id}/cron-checks`: `cron-checks-{501..505}.json`.
- `GET monitors/{id}/maintenance-periods`: `maintenance-{501..505}.json`.
- `GET monitors/{id}/recurring-maintenance-periods`: `recurring-maintenance-{501..505}.json`.

All endpoint bodies derive from read-only captures. The individual
`monitor-{501..505}.json` files remain supplemental captures; the importer reads
the monitor list, not the individual-monitor endpoint. Monitor IDs, other IDs,
hosts, labels, paths, owner details and application-health secrets are synthetic.
Every string leaf is privacy-checked, including pagination URLs; the public
`https://ohdear.app/api/` pagination origin and synthetic `/fixture-sitemap.xml`
path are the only non-`.example` host-shaped strings. Object/list/null
shapes, empty strings, settings, enabled states and schedules remain distinct.
The captured recurring endpoint returns a bare empty list, unlike the SDK's
expected `data` envelope. All captured child endpoints are empty; supplemental
spec fixtures and explicit variants protect nonempty recurring/cron/maintenance.

Expected installed checks: uptime, performance, certificate_health, DNS, domain,
broken_links, mixed_content and sitemap on all five; application_health on 501,
503 and 504. DNS is disabled on 504;
domain is disabled on 501 and 504. Incomplete disabled application_health on 502
and 505 and empty disabled cron require setup and create no rows. Unsupported
checks/settings are reported separately from invalid installed checks.

Offline gate (real CLI/SDK, Postgres/Redis, external transport seam only; no
credentials or Oh Dear traffic). Runs dry-run plus import/rerun controls:

```sh
docker compose exec app php artisan test --compact --group=ohdear-real
```
