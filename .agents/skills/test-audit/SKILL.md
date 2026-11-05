---
name: test-audit
description: Use whenever writing, changing, reviewing or auditing tests. Gate for new tests and checklist for finding low-value, implementation-coupled or duplicate tests.
---

<!-- Adapted from openclaw/openclaw .agents/skills/test-audit (MIT). -->

# Test audit

Heptapod writes feature tests by default. A test exercises behavior through a real entry point (HTTP/Inertia request, artisan command, job, dispatcher, recorder service) against real Postgres and Redis. Only the seams in `Heptapod\Testing\*` are faked: HTTP, DNS, TLS, RDAP/WHOIS, clock, Chromium, notification transports. Never fake our own domain classes.

Default to no unit test. You verify your own code as you write it, so a unit test only earns its maintenance cost as a safety net for a later refactor: logic that is likely to be rewritten, whose exact behavior must survive the rewrite, and that no feature test pins down cheaply (for example uptime/percentile math or cron/RRULE evaluation). If in doubt, don't write it. State the reason in your report.

## Authoring gate

Answer all four before adding a test. A missing answer means do not add it.

1. Which observable behavior, invariant or contract does it protect?
2. Which credible regression makes it fail?
3. Why does existing coverage not catch it already? Each contract has one owner test at the strongest boundary. Extend a dataset or shared fixture instead of adding a near-duplicate.
4. Does it need a production seam that no production caller needs (extra public method, flag, wrapper)? If yes, test at the real boundary instead.

A test that breaks under a behavior-preserving refactor asserts implementation. Rewrite it at the owning boundary.

A bug regression test must fail on the pre-fix code for the intended reason. Show that failure in your report.

## Junk patterns

Reject a new test that matches one of these. In an audit, look for existing tests that match them:

- assertions that only prove code ran (no meaningful expectation)
- expected values produced by the code under test
- copied fixtures, config lists or translation key lists restated as assertions
- string or source greps where a behavior check exists
- the same contract asserted at several layers
- mocks that implement the asserted behavior, or one fake standing in for different APIs
- fixtures that supply the state the production path should produce
- negative tests that pass for an unrelated reason (a different guard rejects it)
- test names that promise more than the inputs exercise
- production code whose only callers are tests

## Keep

- public contracts: the Oh Dear compatible API shapes, the addon contract, migrations, stored data, security and authorization, notification payloads, architecture rules
- ordering where order is observable (incident open → notify → resolve)
- regressions with a credible failure mode

Static or slow is not a reason to delete a test.

## Running

Follow `AGENTS.md` for the canonical runner, local iteration and exact-SHA CI gate. Report actual checks and evidence; this skill does not add another full-suite or cold-stack gate.
