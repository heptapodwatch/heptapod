---
name: test-audit
description: Use whenever writing, changing, reviewing or auditing tests. Gate for new tests and checklist for finding low-value, implementation-coupled or duplicate tests.
---

<!-- Adapted from openclaw/openclaw .agents/skills/test-audit (MIT). -->

<!-- Authoring rule: a project skill states product intent, judgement and short decision guides. Name the canonical component, contract or check once; if a rule can be made a default, type, guard or test, give it that owner instead of specifying it twice. -->

# Test audit

Heptapod's local override of `testing-best-practices`, which owns the generic audit (observable behavior, one owner per contract, determinism, independent expectations).

- **Feature tests through real entry points** (HTTP/Inertia request, artisan command, job, dispatcher, recorder) against real Postgres and Redis. Fake only the external boundary (network, clock, browser, transports), never our own domain classes; the Gate guard and architecture tests own which fakes are allowed.
- **No new unit tests.** `AGENTS.md` "Tests" owns the exceptions and the gates.
- **Earn its place.** A test protects a behavior that could credibly regress and is not covered yet. Removing duplicated or implementation-coupled tests is a valid outcome; a finding does not require a new test.
- **A regression test goes red on the unfixed behavior for the intended reason.** Show that failure.
