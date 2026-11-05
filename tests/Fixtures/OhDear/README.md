The fixtures follow the Monitor, Check, CheckRun and CheckSummary schemas and examples from https://ohdear.app/api-docs/ohdear-openapi.yml.

- The pinned specification is authored by Oh Dear, distributed under the MIT license, and attributed to https://ohdear.app. See the embedded `info.license` and source URL above.
- Reference captured on 2026-10-01; SHA-256: `f2e5cd35c18c049e1a84206e4acbc61f131a4825cd258ab7e98dca99a492d900`.
- IDs and timestamps are deterministic fixture data. Report URLs point to Heptapod.
- `import-account.json` follows the pinned User, Monitor, CronCheck, MaintenancePeriod and RecurringMaintenancePeriod fields. It is synthetic account data, not a live capture. The SDK transport fake splits monitors and cron definitions across pages, rejects non-GET requests and unexpected endpoints, and never uses real credentials. Secret values are negative-control sentinels; the importer must encrypt supported secrets and omit them from output.
- The Check fixture keeps the documented legacy `settings.uptime_check_settings.uptime_check_location` shape. It is a wire projection, not another stored setting.
- `frequency_value`, `frequency_unit` and `failed_notification_threshold` are projected from the check's canonical columns.
- Tests serialize seeded Postgres models through laravel-data DTOs and compare decoded JSON to these fixtures. They pass Monitor, Check and CheckSummary to the installed Oh Dear PHP SDK. SDK 4.7 has no CheckRun DTO; that fixture is checked directly.
- Run and summary errors serialize as `failed`; check snapshots retain `errored-or-timed-out`.
- Endpoint coverage and central in-memory spec exceptions are documented in `docs/api.md`; `tests/Feature/Api` validates real HTTP kernel responses with Spectator. The SDK operation test asserts all implemented operationIds were genuinely validated.
