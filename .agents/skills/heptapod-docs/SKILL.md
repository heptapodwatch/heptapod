---
name: heptapod-docs
description: Write and edit the user-facing Heptapod docs in docs/ (synced to heptapod.watch/docs). Use when adding, rewriting or reviewing a docs page.
---

<!-- Authoring rule: a project skill states product intent, judgement and short decision guides. Name the canonical component, contract or check once; if a rule can be made a default, type, guard or test, give it that owner instead of specifying it twice. -->

# Heptapod docs

The reader runs Heptapod or uses its UI and needs one answer now. Everything in `docs/` except `docs/deploy/operations.md` is public and synced to the website. `php scripts/check-docs.php` owns the mechanics; existing pages show the format.

## Page type by what the reader is doing

- **Doing a task → how-to:** verb heading, one action per step, and how the reader recognizes success.
- **Looking up a fact → reference:** a table whose rows answer the same questions.
- **Understanding a behavior → concept:** the rule in one sentence, then one case per bullet.

A page needing two types gets one section per type.

## Writing

- Lead with the result or rule, not background.
- Name controls in bold exactly as users see them; fields, routes, commands and values in code.
- Describe the current version, never change history.
- Cut what nobody acts on (implementation, internal names, design reasons); keep labels, defaults, limits, roles and what sends a notification.
- Say each fact once; link elsewhere.
- Pick a callout by consequence: note for context, tip for a shortcut, caution for a recoverable mistake, danger for data loss or security. Never for background, and no other color (HTML, inline styles).

## Words

Use the `heptapod-ui-rules` glossary exactly. Bold a word only where it names a state (**Down**). State what a role may do per action; a Member can, for example, write an incident note but cannot run checks.

## Public

Synthetic examples only (`example.com`). No host names, local paths, internal URLs, private repos, people or planning notes. Link between pages relatively.
