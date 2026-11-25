---
name: heptapod-release-notes
description: Write the GitHub release notes for a Heptapod version in the one shared shape. Use when preparing, drafting, publishing or rewriting release notes.
---

<!-- Authoring rule: a project skill states product intent, judgement and short decision guides. Name the canonical component, contract or check once; if a rule can be made a default, type, guard or test, give it that owner instead of specifying it twice. -->

# Release notes

The reader self-hosts Heptapod and has 30 seconds: what do I gain, must I act, how do I upgrade.

**Shape:** title `Heptapod vX.Y.Z`; one headline sentence; then **Before you upgrade** (only when the reader must act: a manual step, a breaking change, an unsupported path), **New**, **Improved**, **Fixed** and **Upgrade**. Omit empty sections. For Upgrade, link or copy the bundle and Git commands from `docs/deploy/operations.md` unchanged.

**Bullets:**

- A bold lead that makes sense alone, plus at most one sentence, ending with `(#N)`.
- The result the operator sees, not the mechanism: no class, job or config names.
- Product terms: Down, Failed, Check error.
- Omit refactors, tests, CI, contributor docs, agent skills and dependency bumps, except a security update that matters to operators ("Security: updated <component> for <advisory>" under Fixed).
- More than about ten bullets: merge related ones.
- Public: no host names, local paths, internal URLs or customer sites.

Show the full draft to the requester and wait for an explicit OK before anything is published.
