---
name: heptapod-release-notes
description: Write the GitHub release notes for a Heptapod version in the one shared shape. Use when preparing, drafting, publishing or rewriting release notes.
---

# Release notes

The reader runs a Heptapod instance and has 30 seconds. They want to know three things: what is new for them, whether something needs their action, and how to upgrade. Every release uses the same shape, so readers know where to look.

## Shape

Title: `Heptapod vX.Y.Z`. Body, sections in this order:

```markdown
One sentence: the headline of this release.

## Before you upgrade
- **What the reader must do or know.** One sentence why.

## New
- **What you can do now.** One sentence of context, only if the lead needs it. (#12)

## Improved
- **What works better.** (#14)

## Fixed
- **The problem that is gone, as the user saw it.** (#9)

## Upgrade
- **Bundle installs:** `cd /opt/heptapod && sudo ./scripts/upgrade.sh`
- **Git installs:** `git fetch --tags && git checkout vX.Y.Z && ./scripts/upgrade.sh`
```

- Leave out a section that has no entries. "Before you upgrade" appears only when the reader must act: a manual step, a breaking change, an unsupported upgrade path.
- The first release has an "Install" section instead of "Upgrade".
- A short command block is allowed where a bullet tells the reader to run something new.

## Bullets

- One bullet = a bold lead plus at most one sentence. The lead alone must make sense.
- Write the user-visible result, not the mechanism. No class names, job names, config internals or test details.
- End with the PR reference `(#N)`. GitHub links it.
- Use the product terms: Down = uptime outage, Failed = check result, Check error = could not check.
- Leave out: refactors, tests, CI, contributor docs, agent skills, dependency bumps. Exception: a security update goes under "Fixed" as "Security: updated <component> for <advisory>".
- More than about 10 bullets: merge related ones. A release that needs more is two topics. Say so in the headline.
- The repository is public. No host names, local paths, internal URLs or customer sites. Examples use `example.com`.

## Process

1. Collect the changes: `git log --oneline <previous tag>..HEAD`, then read the PR and its issue for every user-visible commit.
2. Write the draft to a file in the shape above.
3. Show the draft to the requester and wait for an explicit OK.
4. Publish on the existing annotated tag: `gh release create vX.Y.Z -R heptapodwatch/heptapod --verify-tag --title "Heptapod vX.Y.Z" --notes-file <file>`. To fix published notes, use `gh release edit vX.Y.Z --notes-file <file>`.
