---
name: heptapod-feature-issue
description: Turn a feature idea into a GitHub issue that one implementer can build and one reviewer can check without further context. Use when asked to write, draft or file a feature request or enhancement issue for Heptapod.
---

# Feature issue

The issue is the brief. The implementer builds from it alone, and the reviewer checks the change against its acceptance criteria. Anything that is not in the issue is out of scope.

## 1. Read before asking

Before the first question, find the facts in the repository:

- the pages, controllers, models, addons and `lang/` keys the feature touches;
- the existing behaviour and the nearest existing pattern (`.agents/skills/heptapod-ui-rules` for UI);
- related open issues: `gh issue list --search "<keywords>" --state all`.

Never ask a question that the code answers. Write down what you found. It goes into the issue.

## 2. Settle the forks with the requester

Ask about decisions only: behaviour, scope edges, wording, permissions, defaults. Rules:

- One question per message. Give your recommended answer and the reason, so the requester can reply "yes".
- Follow each answer down its branch until it is settled, then go to the next fork.
- Name each interpretation you see. Do not pick one silently.
- Push back when the idea conflicts with the product scope in `AGENTS.md`: monitoring, reporting and opt-in notifications only. No assignees, owners, claiming, acknowledgement, on-call or task tracking.
- Stop when every acceptance criterion is testable and every non-goal is named. Usually this takes three to eight questions, not twenty.

## 3. Write the body

Write to a file and use these sections in this order:

```markdown
## Problem
Who has which difficulty today. One short paragraph. Synthetic examples only.

## Outcome
The user-visible result after the change.

## Acceptance criteria
- [ ] One observable, testable statement per line (route, screen state, API field, notification, CLI output).
- [ ] EN and DE copy for every new user-facing text.

## Non-goals
- Things a reasonable implementer could add but must not.

## Affected areas
- `path/or/Class` – why it changes. Write "probably" when unsure.

## Required evidence
The proof class from AGENTS.md "Delivery" that matches the risk, with the concrete scenario or command.

## Open questions
Only forks the requester deferred on purpose. Leave out the section when empty.
```

Rules for the body:

- Use the product terms: Down = uptime outage, Failed = check result, Check error = could not check.
- Keep each criterion small enough that one review can verify it. If the list grows past about eight criteria, or covers unrelated surfaces, split the work into several issues and link them.
- The repository is public. Do not write host names, local paths, internal URLs, personal addresses, customer sites or private tooling.
- Follow `0-outbound-comms` if it is installed: short, direct, no ceremony.

## 4. File it

Show the full draft to the requester and wait for an explicit OK. Then:

```bash
gh issue create -R heptapodwatch/heptapod --title "<imperative outcome, no prefix>" --label enhancement --body-file <file>
```

Return the issue URL. If the requester adds a requirement later, edit the issue before work starts. After work starts, a new requirement is a new issue.
