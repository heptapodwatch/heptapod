---
name: heptapod-ui-rules
description: Build or review Heptapod pages with the shared container, header, form, navigation, and list rules. Load before changing any UI pattern.
---

# Heptapod UI rules

Keep this skill high-level: state the rule and name the component; details live in the code. The shell, the shared components and the guards already decide layout and component choice; this skill holds only product wording, judgement calls and decision guides between valid components.

Reuse shared components; never invent per-page layout variants. A new pattern goes into this skill first, as one short rule. All copy goes through `lang/en/*.json` and `lang/de/*.json`; German uses formal "Sie", button labels stay infinitives.

## Enforced by code

The guards reject specific source patterns, not every equivalent; a passing guard is not a review. Read the rule and its named exceptions in the guard itself: `scripts/check-ui-primitives.php` (it runs `check-page-headers.mjs` and `check-form-selects.mjs`), `scripts/check-translations.php`, `tests/Arch/ListPagingTest.php`, and the `Button`/`Badge` types.

## Copy

One word per concept, in EN and DE (the glossary). State words (badges, filters, mail subject tags, counts) use the glossary word exactly; prose may inflect it.

| Concept | EN | DE |
|---|---|---|
| Uptime outage (state) | Down | Ausgefallen (noun: Ausfall) |
| Uptime OK (state) | Up | Erreichbar |
| Check result failed | Failed | Fehlgeschlagen |
| Failure (notification category) | Failure / failures | Fehlgeschlagen / fehlgeschlagene Prüfungen |
| Recovery alert | Recovery / Recovered | Entwarnung; "Behoben" only for a resolved incident |
| We could not check | Check error | Prüffehler |
| Warning | Warning | Warnung |
| Uptime | Uptime | Erreichbarkeit |
| Incident | Incident | Störung |
| Check | Check | Prüfung |
| Owner (role) | Owner | Inhaber |
| Backup | Backup | Sicherung |
| Queue worker | Worker | Hintergrundprozess |
| Coding agent | Coding agent | KI-Agent |
| Run one / all checks | Run check / Run all checks | Prüfung starten / Alle Prüfungen starten |
| Open a record | Open incident / Open check | Störung öffnen / Prüfung öffnen |
| Refresh | Refresh | Aktualisieren |
| System health | System health | Systemzustand |
| Routing | Routing | Routing (never "Weiterleitung", that is an HTTP redirect) |
| A paused site (sites only) | Paused / Pause site | Pausiert / Website pausieren |
| Alerts silenced, check keeps running | Snooze / Snoozed until … / End snooze | Stummschalten / Stummgeschaltet bis … / Stummschaltung beenden |
| A turned-off check | Turned off / Off / Turn check off / Turn on | Ausgeschaltet / Aus / Prüfung ausschalten / Einschalten |

- Never call a non-uptime failure "Down", and never call a check "paused". A technical endpoint that cannot be reached ("Health endpoint unreachable") is not the Down state.
- A page, section or sheet description is one sentence. More goes into the `detail` tooltip. A tooltip holds background only: the next step, the recovery, a required choice and the consequence of an action stay in the visible sentence.
- Prose about people is gender-neutral; role names appear only as labels or as "die Rolle Inhaber".
- No developer jargon in visible copy: show the human name and put the technical name (API scope, queue) in the tooltip. Never ask the user to type UTC.
- Every non-OK result has a specific headline with the concrete reason and numbers, worded from structured evidence through lang keys. No raw keys or empty placeholders.
- Every error or refusal says what happened and what to do or when to retry.
- Overflow reads "Show all N" or, in prose, "and N more".
- Copy that names a nav destination uses its nav label ("Systemzustand öffnen").

## Foundation

- Geist Mono only for numbers, timestamps, URLs, IDs and evidence. Off-scale sizes round to the nearest existing size; never add a size for one page.
- Status is colour, shape and a translated word, through `StatusDot` / `StatusBadge`. The Down halo is for uptime only; system-health failures use the Check error shape. Runs never use the status marks (§ Runs).
- A snoozed check keeps its result in counts and attention; only its alerts are silenced.
- Charts show peaks, not averages that hide spikes.
- A usage gauge (`ScoreRing` with `tone`) is neutral while fine, Warning within 10 points of its alert threshold and Failed at or above it, ticks the threshold and names any non-neutral state in words.
- A newer Heptapod release shows only to Owners and Admins, on the resting mark's upper right stem (warning colour, danger for a security fix), and the mark's tooltip names it an update of Heptapod itself, never a site state.

## Tooltips

Use `TooltipHint` for a supplementary value (an exact time) and `TruncatedText` for text that may be cut off; they keep the value reachable by keyboard and screen reader. Tooltip content is never interactive.

## Dates and durations

- Lists, tables and history use `AbsoluteTime` in the viewer’s time zone, with one precision per list (including range endpoints); show seconds only where they distinguish events. Reserve `MonitoringTime` for status statements, with the exact instant in its tooltip.
- Table cells use `compactDuration`, so a German duration never widens a column.
- Weeks start on Monday.

## Pages

Every primary view answers one question; ordering, density and liveness follow from it.

| View | Question |
|---|---|
| Sites | Is everything OK, and what needs me? |
| Site overview | Is this site OK? |
| Check detail | What does this check see now, and what can I do about it? |
| Incidents | What broke, when, for how long, is it still going? |
| Incident detail | Everything about this episode, on one page. |
| Upcoming | What expires or happens in the coming weeks? |
| Activity | What changed? |
| Runs | What is the system doing right now? |

- Live updates hold foreign structural changes behind "N new changes" while the user reads, or has a listbox, menu or dialog open; a closed control that only keeps focus does not hold them. The user's own action shows at once. Upcoming and the site Overview apply changes in place; the Runs board applies them live except while the user reads its history or has a menu open.
- The PageHeader description is one useful line, not boilerplate. Client and site context is plain text above the title; status and facts never repeat the title.
- At most one primary header action, and never a lone More menu without it.

### Sites header

One summary: the state ring, the health headline and one meta line. While monitoring is current, the meta line shows every non-OK state with its count, so no site is ever silent; stale monitoring shows the staleness instead.

## Forms

- `SchemaFields` derives full-width lists and URLs, monospace URL entry, and syntax hints from field kinds and validation rules. Long check settings sheets declare groups in schema presentation and render them with `FormSection`.
- One page, one form, one bar. Every inline field on a page is a draft of that page's one form, saved through `FormActions`. No per-section or per-card Save, no inline edit that applies at once, never a second bar (move the second form into a sheet). Read-only controls are not drafts: a preview picker (Who gets this) or a read-only step (Oh Dear import's Show plan) acts at once and saves nothing. A page that works only through sheets and dialogs has no bar.
- Everything outside the form is a button that names its effect: "Change…" opens a SheetForm, delete opens a ConfirmDialog, one-shot actions act at once.
- Choice controls, by case:
  - 2–4 fixed options: `RadioGroup`;
  - consequence-led options, or 5–8 stored options with descriptions: `RadioCards`;
  - 5–7 fixed options without descriptions, and fixed query or preview choices: `Select`;
  - data (sites, clients, tags, members, timezones, checks) or more than 7 fixed options, previews included: the `Combobox` family;
  - up to four fixed multi-choices: checkboxes.
- A short list of user-entered rows is `ReplicatorField`, never a comma-separated text field.
- Creating a related record from inside a flow opens a SheetForm and then selects the new record; the flow is never left.
- `FormSection` `preview` is for the one artefact the page exists to shape (a rendered email), rendered by the server from the draft.
- A change with consequences beyond the field (moving a site to another client) explains what changes before Save.
- Local drafts (Add site) never store secrets, request headers or bodies, or URL userinfo, query or fragment.

## Containers

Create and edit of a record use `SheetForm`. A centred `Dialog` is only for a confirmation, a shown-once secret or a read-only preview.

## Native controls

The branded primitives in `resources/js/components/ui/` own every native control and browser validation. Every field error is an `InputError` associated with its control.

## Callouts and page notices

Pick the `Alert` tone by meaning: `info` for neutral facts, `warning` for something that needs attention, `danger` for failed or irreversible. Page-level notices go into the PageHeader `notices`; a callout that belongs to one card, sheet or section stays inside it.

## Errors and refusals

Expected refusals (cooldown, conflict, stale tab, expired link, revoked permission) never show a raw framework page or an Inertia error modal; they throw `Heptapod\Exceptions\ExpectedRefusal`. Unexpected faults stay loud. Pick one channel:
- the page already knows the refusal: disable the control and say why in `ActionNoteSlot`, which never shifts the layout;
- a field, or a control inside an open sheet, dialog or card: inline next to it;
- a one-shot action that races: a toast;
- a signed-in GET dead end: the in-shell error page, so navigation stays reachable.

## Confirmations

The confirm button names the action with its record ("Delete window", "Revoke token"), never "Confirm". Irreversible actions are destructive; reversible ones (archive, pause, end a window) stay neutral. Typed-name confirmation is only for destroying data at scale (Delete site).

## Cards

`CardAction` holds the card's controls; a link there is a ghost button, never underlined text. Two or more card actions go into `CardToolbarActions`; an Export stays visible at every width.

## Exports

One `ExportButton` per surface, never inside a menu. Every format comes from the server; the browser never builds export text. CSV never drops rows: past the limit the export is refused with a hint to narrow the filters.

## Runs

- A run is queued, running or just-finished work (check run, import, backup). Only `resources/js/components/run-family.tsx` draws one.
- Words: Queued / Running / Finished. "Late", "Done" and "Requested" are not run states. "Running now" is manual work, never scheduled runs.
- `YouMarker` marks who started a run, nothing more: no owner, assignee or acknowledgement.
- A disabled Run check always names why. Unreported progress draws no bar, never a guessed fraction.

## Motion

Motion marks state transitions only, moves only transform and opacity, never animates layout, and is instant under reduced motion. Exits are shorter than entrances. Things used many times a day (the command palette) open without motion.

## Interaction states

- Clickable rows and cards use `rowTargetClass` (`resources/js/components/entity-link.tsx`) and have one keyboard target, never both the row and its name link.
- Touch targets stay usable on mobile; text keeps WCAG AA contrast on every surface.
- Every copy control is `CopyButton`: labelled "Copy" / "Copied", or icon-only with an accessible name inside an inline value (a server's IP pill). A refused clipboard is a note beneath a standalone button and a toast for one beside its value.
- A permission matrix (many independent grants, such as API token abilities) is `AbilityMatrix`, a form grid rather than the data `Table` (no header band, no row hover), where each checkbox carries its own word, the checked boxes are exactly the grant and starting points only fill them.

## Navigation

- One destination, one entry, one label: the nav title is also the PageHeader and document title. Settings has one entry, in the sidebar footer. The user menu holds identity, Theme, Source code and Log out only.
- `SectionTabs` is navigation between pages; `SegmentedControl` switches a filter or mode on one page.
- A `SegmentedControl` option that is unavailable stays visible and says why in its tooltip (`disabledReason`).

### Sidebar footer

Status rows appear only while they are true; with nothing to report the group does not render.

## Site frame

Every `/sites/{site}` page renders in `SiteSectionsLayout` with the site rail. Site-wide actions live only in the Overview header. The rail never re-sorts under the user: only this tab's own write moves the check it touched; refreshes change marks in place.

## Site overview

The Overview answers "Is this site OK?": the header states the site state, the rail lists every check, and the body adds only numbers worth a glance and the alert line. Events, upcoming items and check tables live on their own pages.

### Who gets this

The full notification explainer appears only in Site settings → Alerts and on the incident page. Everywhere else it is one line with the channel count, plus a Details link for people who may open Site settings.

## Check detail

- The header headline is the check's headline; no site state or site actions on a check page.
- A turned-off check says "This check is turned off", never "Paused".
- Evidence shows only what was recorded; never invent facts (a served IP, a chain trust) or a number guessed from generic parameters.
- Alert sensitivity choices are `RadioCards`; the "What you would receive" preview derives from the actual timing, never fixed lengths.

## Incident episode

One incident page for open and resolved incidents. A resolved incident shows only evidence from its own episode, never the check's latest findings. Never infer a suppression reason from current settings.

## Lists

- `Table` for aligned columns, `ListHeader` / `ListRow` for responsive rows with rich actions. A data table never scrolls sideways: fold a short value into the primary cell or switch to cards by container width.
- `EmptyState`: first-run copy says what the list holds and offers the create action; the filtered variant points to Clear filters. A control that only widens the list is not a filter.
- Row emphasis is only for rows that need action now; never for Warning.
- Row actions are `RowActions`; never mix ghost and outline buttons in a row.
- At most one avatar per row, for the row's primary object; trails stay text. Avatars are resolved on the server, never fetched or classified in the browser.

### Lists and paging

Every list is one of three classes; a new list starts as Page it:
- **Page it**: a list a user may need to walk; it pages through `Heptapod\Contracts\ListPaging`.
- **Preview + link**: a teaser of a list with its own page; the footer says so and links to the filtered list.
- **Bounded by nature**: a small fixed set.

Admin-curated configuration lists (members, tokens, channels) show in full. Never cap a list silently.

## Filters

- Declare list filters once on the server; `ListFilterBar` renders the resulting controls. Keep paging and sorting in their existing helpers.
- `FilterBar`: search first, then selects, then the segmented control. Its `actions` slot holds filter actions only, never page actions.
- Filters apply live (no submit button), push to browser history and survive Back.
- An active select or combobox filter shows that it is active and has its own clear button; a search or segmented control shows its value.
- Use `FilterChip` for an active filter that can be cleared but is not changed from the bar.
