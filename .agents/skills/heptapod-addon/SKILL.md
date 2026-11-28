---
name: heptapod-addon
description: Create or change a Heptapod check addon, its schemas, observations and accepted-run datasets.
---

<!-- Authoring rule: a project skill states product intent, judgement and short decision guides. Name the canonical component, contract or check once; if a rule can be made a default, type, guard or test, give it that owner instead of specifying it twice. -->

# Check addons

An addon is a Composer path package under `addons/*` (namespace `Heptapod\Addons\<Name>`) whose service provider registers one `Heptapod\Contracts\CheckType` implementation per `CheckKey` it owns (crawl registers two). Core never imports an addon. The contracts in `app/Contracts`, the architecture tests and the uptime and performance addons are the specification; read them before editing.

## Design decisions

- **Ship checks, not a platform.** The contract serves the checks Heptapod ships. Name the consuming check before adding anything to it.
- **Oh Dear compatibility, not claimed parity.** Keep Oh Dear key names and value types for config; do not promise behavior you have not verified against Oh Dear.
- **Protocol facts are not severity.** Record what the site returned separately from how bad it is.
- **No observation is not success.** Return a no-result reason when nothing was observed; let exceptions reach the engine as check errors.
- **A change is an event; a repeated poll is not.** Emit an addon event when the accepted state meaningfully changes, even at unchanged severity (a replaced certificate), and never on every poll.
- **Unavailable data is not a negative finding.** A failed DNS or registry lookup says nothing about the domain.
- **Crawls are plain HTTP.** Broken links, mixed content and sitemap fetch with spatie/crawler and do not execute JavaScript; Lighthouse alone uses Chromium. One crawl addon owns broken links and mixed content; do not invent a shared core crawl contract.
- **Security through the platform.** TLS trust, hostname matching, crypto, signatures and auth come from native platform features or maintained packages, never own code.
- **Addons own their resources.** Translations (EN and DE), config, migrations, settings, routes and the OpenAPI text of their API operations (`Heptapod\Contracts\ApiDocumentPart`) ship in the addon and load from its provider; never copy addon keys into root `lang/` or `ApiContract`. Every imported library is a direct caret dependency of the addon.

## New check addon

The addon implements; core supplies the rest:

- `CheckRunPresenter`: `presentRun()` returns the parts and any `history_label`; `namesCheck()` says which keys already name the check. Core redacts and adds the TLS explanation.
- `MailEvidenceProvider`: `mailEventTones()` lists only the events of the addon and any override of `LifecycleTones`; recoveries go through the event mail and `mailEvidence()` (use `NoteBlock::detailsNotRetained()` when raw retention removed the facts). Only uptime implements `MailRecoveryEvidenceProvider`, for the outage mail.
- `RunPersistence`: a table with one staged row per attempt uses `StagedSnapshots` for stage, finalize, discard and purge.
- `OhDearImportMapping` lists only the settings of the addon; core adds the schedule settings.

Core edits left: a `CheckKey` case, `NotificationCategory::ChangeKeys` for change events, root `composer.json`, the `phpunit.xml` source list, the check-type maps in `resources/js`, and for Oh Dear import: its `*_check_settings` source in `ImportOhDearMonitor` (the `SettingsTypes` allow-list and the `$settings` map in `siteSettings()`, same order) and its notification types in `OhDearDestinationMapper`.

## Evidence

Prove behavior through the real engine and recorder against Postgres and Redis, with only the external boundary faked (`test-audit`). A fake transport proves verdict and display mapping, **not** native security behavior: trust, expiry and hostname checks need real fixtures. Large datasets are staged through the addon's persistence; only the recorder finalizes an accepted run.
