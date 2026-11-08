---
name: heptapod-docs
description: Write and edit the user-facing Heptapod docs in docs/ (synced to heptapod.watch/docs). Use when adding, rewriting or reviewing a docs page.
---

# Heptapod docs

The reader runs Heptapod or uses its UI and wants one answer fast. Each page has one type, a fixed skeleton and short sentences. Everything in `docs/` except `docs/deploy/operations.md` is public and synced to https://heptapod.watch/docs/.

## Front matter

```yaml
---
title: Maintenance
description: Pause alerts for planned work and get one catch-up alert for anything still failing.
sidebar:
    order: 7
---
```

- `title`: the feature or the task as the UI names it. No "Overview", "Introduction" or "How it works".
- `description`: one sentence, 50–160 characters, that answers "what does this page help me do". It renders under the title and feeds `llms.txt` and search, so it must stand alone.

## Page types

Pick one type per page. A page that needs two types becomes two sections with their own headings, in the order below.

**How-to** (do one task):

```markdown
One or two sentences: what the reader achieves.

## <Verb> <object>          e.g. "Schedule a window"
1. One action per step, with the exact UI label in bold: Select **New window**.
2. …
Result: what the reader sees when it worked.
```

**Reference** (look up exact facts):

```markdown
One sentence: what the table covers.

| Field | Values | Default |
|---|---|---|
```

Every entry answers the same questions in the same column order. Use the field or label name exactly as the UI or API writes it. No steps, no opinions.

**Concept** (understand a behaviour):

```markdown
## <Behaviour>                e.g. "Catch-up alerts"
The rule in one sentence. Then the cases as a bullet list, one case per bullet.
```

A typical feature page: intro, how-to sections, concept sections, then reference tables last.

## Writing rules

- Lead with the answer. The first sentence of the page and of each section states the rule or the result, not the background.
- Intro: at most two sentences.
- One idea per sentence; at most about 30 words. Split at "and", "while", ";".
- Bullets for cases and conditions, tables for fields and values, prose only for one rule.
- Present tense, active voice, second person ("you") or the role name. No contractions.
- No history words: now, no longer, previously, new, used to, before this change, legacy. The page describes the current version; release notes carry changes.
- No filler: simply, just, easy, please note, note that, in order to, it is worth noting.
- Cut background nobody acts on: implementation, internal names, why a design was chosen. Keep every fact a reader relies on: UI labels, defaults, limits, roles, what sends a notification and what does not.
- Say each fact once, in its own section. Link instead of repeating: `[quiet hours](./notifications.md#quiet-hours)`.
- UI labels in bold exactly as on screen (**New window**, **End window**). Field names, routes, commands and values in code (`mute_alerts`, `POST /maintenance`).
- Headings: H2 for sections, H3 at most. Sentence case. A how-to heading starts with a verb. Headings are the "On this page" list, so they name the thing.
- Callouts (`:::caution`) only for risk the reader would regret: data loss, a message sent to customers, an irreversible action. At most one per section. Never for background.
- Code blocks only for something the reader copies or runs.

## Words

Use the glossary in `.agents/skills/heptapod-ui-rules` exactly:

- **Down**: an uptime outage. Never call another check "down".
- **Failed**: a check result. **Check error**: Heptapod could not check.
- **Warning**, **Incident**, **Up**, **Maintenance** as in the UI.
- Roles: Owner, Admin, Member. Owners and Admins see the whole instance; Members see only their assigned clients.
- "Follow" means only "send me these alerts".

## Public docs

- No host names, local paths, internal URLs, private repos or people. Example hosts use `example.com`; example addresses `ops@example.com`.
- Links between app docs are relative (`./notifications.md`). The website sync rewrites them and fails on links to pages it does not publish.
- No internal planning, lane, issue or review notes.

## Guard

`php scripts/check-docs.php` (part of `scripts/ci-checks.sh php-static`) fails a page without a one-line `description`, a sentence over 30 words or a banned word. Pages not yet rewritten are listed in the script; remove a page from that list when you rewrite it.
